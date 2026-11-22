<?php

declare(strict_types=1);

namespace App\Http\Controllers\Peso;

use App\Http\Controllers\Controller;
use App\Http\Requests\Peso\UploadPesoRequest;
use App\Models\PesoItem;
use App\Models\PesoShipment;
use App\Services\Peso\PesoShipmentImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\Receiving\PesoReceivingReconciliationService;
use App\Services\Receiving\PesoStockReceiptService;
use App\Models\Product;
use App\Models\Inventory\Product as InventoryProduct;
use App\Models\PesoProductMapping;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PesoShipmentController extends Controller
{
    public function __construct(
    private readonly PesoShipmentImportService $importService,
    private readonly PesoReceivingReconciliationService $reconciliationService,
) {
}

    public function create(): Response
    {
        $organizationId = (int) (auth()->user()?->organization_id ?? 1);

        $shipments = PesoShipment::query()
            ->whereHas('items', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->with('warehouse:id,name')
            ->withCount([
                'items as total_items_count',
                'items as received_items_count' => function ($query) {
                    $query->where('status', 'received');
                },
            ])
            ->orderByDesc('id')
            ->get();

        return Inertia::render("Peso/Upload", [
            'shipments' => $shipments,
        ]);
    }

    public function store(UploadPesoRequest $request): RedirectResponse
{
    $file = $request->file('re12_pdf');
    $path = $file->store('peso');

    try {
        $shipment = $this->importService->import(
            Storage::disk('local')->path($path),
            (int) (auth()->user()?->organization_id ?? 1)
        );

        /*
         * Reconcile any physical barcode scans that may have happened
         * before this PESO PDF was uploaded.
         */
        $this->reconciliationService->reconcileShipment($shipment->id);

        return redirect()
            ->route('peso.shipments.show', $shipment->id)
            ->with('success', 'PESO RE-12 imported and receiving reconciled successfully.');

    } catch (\Throwable $e) {
        Storage::disk('local')->delete($path);
        throw $e;
    }
}

    public function show(int $id): Response
    {
        $shipment = PesoShipment::query()
            ->with(["warehouse", "items.product", "items.productVariant"])
            ->findOrFail($id);

        return Inertia::render("Peso/Show", [
            "shipment" => $shipment,
            'mappings' => PesoProductMapping::query()
                ->where('organization_id', auth()->user()->organization_id)
                ->get()->keyBy(fn (PesoProductMapping $mapping) => json_encode([$mapping->manufacturer_name, $mapping->re12_code])),
            'products' => InventoryProduct::query()
                ->where('organization_id', auth()->user()->organization_id)
                ->orderBy('name')
                ->get([
                    'id', 'name', 'sku', 'base_inventory_unit', 'partial_barcode_sales',
                    'minimum_sale_quantity', 'sale_increment',
                ]),
        ]);
    }

    public function receiveAll(Request $request, int $id): RedirectResponse
    {
        $shipment = PesoShipment::findOrFail($id);

        DB::transaction(function () use ($shipment, $request) {
            $items = PesoItem::query()
                ->where('peso_shipment_id', $shipment->id)
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                if ($item->status !== 'received') {
                    $item->status = 'received';
                    $item->received_at = now();
                    $item->save();
                }

                app(PesoStockReceiptService::class)->receive($item, $request->user()?->id);
            }

            $total = $items->where('barcode_level', 'L1')->count();
            $received = $items->where('barcode_level', 'L1')->where('status', 'received')->count();

            $shipment->update([
                "status" => $received >= $total ? "received" : "ready_for_receiving",
                "received_packages" => $received,
            ]);
        });

        return redirect()->route("peso.shipments.show", $shipment->id)
            ->with("success", "All cartons received into Godown stock successfully.");
    }

public function mapProduct(Request $request, int $id): RedirectResponse
{
    $validated = $request->validate([
        'product_id' => ['nullable', 'required_without:item_product_ids', 'integer', 'exists:products,id'],
        'item_product_ids' => ['nullable', 'required_without:product_id', 'array', 'min:1'],
        'item_product_ids.*' => ['nullable', 'integer', 'exists:products,id'],
        'barcode_quantities' => ['nullable', 'array'],
        'barcode_quantities.*' => ['nullable', 'integer', 'min:1'],
    ]);

    $organizationId = (int) $request->user()->organization_id;
    $shipment = PesoShipment::query()->whereHas('items', fn ($q) => $q->where('organization_id', $organizationId))->findOrFail($id);
    $productIds = collect($validated['item_product_ids'] ?? [])->filter()->map(fn ($productId) => (int) $productId);
    if (! empty($validated['product_id'])) $productIds->push((int) $validated['product_id']);
    $productIds = $productIds->unique()->values();
    $products = InventoryProduct::query()
        ->where('organization_id', $organizationId)
        ->whereIn('id', $productIds)
        ->get()
        ->keyBy('id');

    if ($products->count() !== $productIds->count()) {
        throw ValidationException::withMessages([
            'item_product_ids' => 'Each PESO product group must map to a product in your organization.',
        ]);
    }

    DB::transaction(function () use ($shipment, $validated, $products, $request, $organizationId) {
        $items = PesoItem::query()->where('peso_shipment_id', $shipment->id)->lockForUpdate()->get();
        $groups = $items->groupBy(fn (PesoItem $item) => json_encode([$item->product_name, $item->size_code]));
        if (! empty($validated['product_id']) && $groups->count() > 1) {
            throw ValidationException::withMessages(['product_id' => 'Use group-specific product mappings when a shipment contains multiple manufacturer name and code groups.']);
        }

        foreach ($groups as $groupItems) {
            $first = $groupItems->first();
            $provided = $groupItems->map(fn (PesoItem $item) => array_key_exists($item->id, $validated['item_product_ids'] ?? [])
                ? $validated['item_product_ids'][$item->id]
                : ($validated['product_id'] ?? null))->unique();
            if ($provided->count() !== 1) {
                throw ValidationException::withMessages(['item_product_ids' => 'All barcodes in a manufacturer name and RE-12 code group must use one product.']);
            }
            $rawProductId = $provided->first();
            if ($rawProductId === null) continue;
            $product = $products->get((int) $rawProductId);
            if ($product === null) continue;

            $mapping = PesoProductMapping::query()
                ->where('organization_id', $organizationId)
                ->where('manufacturer_name_key', PesoProductMapping::exactNameKey((string) $first->product_name))
                ->where('re12_code', (string) ($first->size_code ?? ''))
                ->lockForUpdate()->first();
            if ($mapping && $mapping->product_id !== null && (int) $mapping->product_id !== (int) $product->id) {
                throw ValidationException::withMessages(['item_product_ids' => 'This exact manufacturer and RE-12 code group already has a different saved product mapping.']);
            }
            if ($mapping?->product_variant_id !== null) {
                $mappedVariant = \App\Models\Inventory\ProductVariant::query()
                    ->where('organization_id', $organizationId)
                    ->where('product_id', $product->id)
                    ->find($mapping->product_variant_id);
                if ($mappedVariant === null) {
                    throw ValidationException::withMessages(['item_product_ids' => 'The saved size-code mapping points to a variant outside the selected product.']);
                }
            }
            if ($mapping && $mapping->product_id === null && $mapping->internal_product_name !== $product->name) {
                throw ValidationException::withMessages(['item_product_ids' => "This group is classified as {$mapping->internal_product_name}; select a product with that exact internal name."]);
            }
            $mapping ??= new PesoProductMapping([
                'organization_id' => $organizationId,
                'manufacturer_name' => (string) $first->product_name,
                'manufacturer_name_key' => PesoProductMapping::exactNameKey((string) $first->product_name),
                're12_code' => (string) ($first->size_code ?? ''),
                'internal_product_name' => $product->name,
            ]);
            $mapping->product_id = $product->id;
            $mapping->save();

            foreach ($groupItems as $item) {
                if (($item->barcode_level ?? 'L1') !== 'L1') {
                    $item->product_id = null;
                    $item->product_variant_id = null;
                    $item->peso_product_mapping_id = $mapping->id;
                    $item->save();
                    continue;
                }

                if ($item->order_item_id !== null || $item->allocations()->exists()) {
                    if ((int) $item->product_id !== (int) $product->id) {
                        throw ValidationException::withMessages(['item_product_ids' => 'A loaded or allocated barcode cannot be remapped.']);
                    }
                    continue;
                }

                $receiptExists = \App\Models\Inventory\StockAdjustment::query()
                    ->where('type', 'peso_receipt')->where('reference_type', PesoItem::class)
                    ->where('reference_id', $item->id)->exists();
                if ($receiptExists && (int) $item->product_id !== (int) $product->id) {
                    throw ValidationException::withMessages([
                        "item_product_ids.{$item->id}" => 'A received barcode with a stock receipt cannot be remapped.',
                    ]);
                }

                if ($product->partial_barcode_sales) {
                    $quantity = $validated['barcode_quantities'][$item->id] ?? null;
                    if (! is_numeric($quantity) || ! $product->acceptsSaleQuantity((int) $quantity)) {
                        throw ValidationException::withMessages(["barcode_quantities.{$item->id}" => "Enter a manually confirmed quantity following {$product->minimum_sale_quantity} {$product->base_inventory_unit} minimum and {$product->sale_increment} increments."]);
                    }
                    if ($receiptExists && (int) $item->original_quantity !== (int) $quantity) {
                        throw ValidationException::withMessages(["barcode_quantities.{$item->id}" => 'A barcode quantity cannot change after its stock receipt has been recorded.']);
                    }
                    $item->original_quantity = (int) $quantity;
                    if (! $receiptExists) $item->remaining_quantity = (int) $quantity;
                    $item->base_unit = $product->base_inventory_unit;
                    $item->quantity_source = 'manual';
                } else {
                    $item->original_quantity = 1;
                    $item->remaining_quantity = 1;
                    $item->base_unit = null;
                    $item->quantity_source = 'package';
                }
                $item->product_id = $product->id;
                $item->product_variant_id = $mapping->product_variant_id;
                $item->peso_product_mapping_id = $mapping->id;
                $item->save();

                if ($item->status === 'received') app(PesoStockReceiptService::class)->receive($item, $request->user()?->id);
            }
        }
    });

    return redirect()
        ->route('peso.shipments.show', $shipment->id)
        ->with('success', 'PESO product mapping updated successfully.');
}
    /**
     * PESO dashboard / uploaded RE-12 list.
     */
    public function index(): Response
    {
        $organizationId = (int) (auth()->user()?->organization_id ?? 1);

        $shipments = PesoShipment::query()
            ->whereHas('items', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->with('warehouse:id,name')
            ->withCount([
                'items as total_items_count',
                'items as received_items_count' => function ($query) {
                    $query->where('status', 'received');
                },
                'items as pending_items_count' => function ($query) {
                    $query->where('status', 'expected');
                },
            ])
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Peso/Index', [
            'shipments' => $shipments,
        ]);
    }

    /**
     * Show all PESO barcodes that are still expected.
     */
    public function pendingReceiving(): Response
    {
        $organizationId = (int) (auth()->user()?->organization_id ?? 1);

        $items = PesoItem::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'expected')
            ->with([
                'shipment:id,re12_number,peso_date,licence_number,warehouse_id',
                'warehouse:id,name',
                'product:id,name,base_inventory_unit,partial_barcode_sales',
            ])
            ->orderBy('peso_shipment_id')
            ->orderBy('id')
            ->get([
                'id',
                'peso_shipment_id',
                'warehouse_id',
                'product_id',
                'product_name',
                'size_code',
                'barcode',
                'status',
                'original_quantity',
                'remaining_quantity',
                'base_unit',
                'quantity_source',
            ]);

        return Inertia::render('Peso/PendingReceiving', [
            'items' => $items,
        ]);
    }
}
