<?php

declare(strict_types=1);

namespace App\Services\Receiving;

use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\ProductVariant;
use App\Models\PesoItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Books each physically received PESO barcode into inventory exactly once. */
final class PesoStockReceiptService
{
    public function receive(PesoItem $pesoItem, ?int $userId = null): ?StockAdjustment
    {
        return DB::transaction(function () use ($pesoItem, $userId): ?StockAdjustment {
            $lockedItem = PesoItem::query()
                ->whereKey($pesoItem->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedItem->status !== 'received' || $lockedItem->product_id === null) {
                return null;
            }
            if (($lockedItem->barcode_level ?? 'L1') !== 'L1') {
                return null;
            }

            $existing = StockAdjustment::query()
                ->where('type', 'peso_receipt')
                ->where('reference_type', PesoItem::class)
                ->where('reference_id', $lockedItem->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $product = $lockedItem->product;
            if ($product === null) {
                return null;
            }

            $quantity = (int) $lockedItem->original_quantity;
            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    'barcode_quantity' => 'A received PESO barcode must have a positive original quantity.',
                ]);
            }

            if ($product->partial_barcode_sales && $lockedItem->base_unit !== $product->base_inventory_unit) {
                throw ValidationException::withMessages([
                    'barcode_quantity' => "Barcode quantity must use the product base unit ({$product->base_inventory_unit}).",
                ]);
            }

            $warehouseLocations = ProductLocation::query()
                ->where('organization_id', $product->organization_id)
                ->where('warehouse_id', $lockedItem->warehouse_id)
                ->orderBy('id')
                ->pluck('id');
            $locationId = $warehouseLocations->contains((int) $product->location_id)
                ? (int) $product->location_id
                : $warehouseLocations->first();

            $actor = $userId === null ? null : User::query()->find($userId);

            if ($lockedItem->product_variant_id !== null) {
                $variant = ProductVariant::query()
                    ->where('organization_id', $product->organization_id)
                    ->where('product_id', $product->id)
                    ->findOrFail($lockedItem->product_variant_id);

                return StockAdjustment::adjustVariant(
                    variant: $variant,
                    quantity: $quantity,
                    type: 'peso_receipt',
                    reason: "PESO barcode received: {$lockedItem->barcode}",
                    notes: 'Exact manufacturer barcode received against PESO / RE-12.',
                    reference: $lockedItem,
                    allowNegative: false,
                    actor: $actor,
                    locationId: $locationId === null ? null : (int) $locationId,
                    preferWarehouseId: $lockedItem->warehouse_id,
                );
            }

            return StockAdjustment::adjust(
                product: $product,
                quantity: $quantity,
                type: 'peso_receipt',
                reason: "PESO barcode received: {$lockedItem->barcode}",
                notes: 'Exact manufacturer barcode received against PESO / RE-12.',
                reference: $lockedItem,
                allowNegative: false,
                actor: $actor,
                locationId: $locationId === null ? null : (int) $locationId,
            );
        });
    }
}
