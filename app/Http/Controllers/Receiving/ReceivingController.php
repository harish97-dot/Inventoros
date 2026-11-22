<?php

declare(strict_types=1);

namespace App\Http\Controllers\Receiving;

use App\Http\Controllers\Controller;
use App\Models\PesoItem;
use App\Models\PesoShipment;
use App\Models\Warehouse;
use App\Services\Receiving\ReceivingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReceivingController extends Controller
{
    public function __construct(
        private readonly ReceivingService $receivingService,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        $warehouses = Warehouse::query()
            ->where(
                'organization_id',
                (int) ($user?->organization_id ?? 1)
            )
            ->orderBy('id')
            ->get([
                'id',
                'name',
            ]);

        return Inertia::render('Receiving/Index', [
            'warehouses' => $warehouses,
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => [
                'required',
                'integer',
                'exists:warehouses,id',
            ],
            'barcode' => [
                'required',
                'string',
                'max:150',
            ],
        ]);

        $user = $request->user();

        $scan = $this->receivingService->scan(
            organizationId: (int) ($user?->organization_id ?? 1),
            warehouseId: (int) $validated['warehouse_id'],
            barcode: $validated['barcode'],
            userId: $user?->id,
        );

        $pesoItem = $scan->peso_item_id
            ? PesoItem::query()->with('product:id,name,base_inventory_unit,partial_barcode_sales')->find($scan->peso_item_id)
            : null;

        $shipment = $scan->peso_shipment_id
            ? PesoShipment::query()->find($scan->peso_shipment_id)
            : null;

        $receivedCount = $shipment
            ? PesoItem::query()
                ->where('peso_shipment_id', $shipment->id)
                ->where('status', 'received')
                ->count()
            : null;

        $totalPackages = $shipment
            ? PesoItem::query()
                ->where('peso_shipment_id', $shipment->id)
                ->count()
            : null;

        return response()->json([
            'success' => $scan->status !== 'REJECTED',

            'message' => match ($scan->status) {
                'VERIFIED' => 'Barcode received successfully.',

                'DUPLICATE' => 'Barcode was already scanned.',

                'RECEIVED_PENDING_VERIFICATION' =>
                    'Barcode recorded and is pending PESO verification.',

                'REJECTED' => 'Barcode belongs to a different godown. No stock was received.',

                default => 'Barcode scan processed.',
            },

            'scan' => [
                'id' => $scan->id,
                'barcode' => $scan->barcode,
                'status' => $scan->status,
                'warehouse_id' => $scan->warehouse_id,
                'peso_item_id' => $scan->peso_item_id,
                'peso_shipment_id' => $scan->peso_shipment_id,
                'notes' => $scan->notes,
                'scanned_at' => $scan->scanned_at,
                'product_name' => $pesoItem?->product?->name ?? $pesoItem?->product_name,
                'original_quantity' => $pesoItem?->original_quantity,
                'remaining_quantity' => $pesoItem?->remaining_quantity,
                'base_unit' => $pesoItem?->base_unit ?? $pesoItem?->product?->base_inventory_unit,
            ],

            'received_count' => $receivedCount,
            'total_packages' => $totalPackages,
            'shipment_status' => $shipment?->status,
        ], $scan->status === 'REJECTED' ? 422 : 200);
    }
}
