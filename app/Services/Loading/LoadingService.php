<?php

declare(strict_types=1);

namespace App\Services\Loading;

use App\Models\Order\OrderItem;
use App\Models\PesoBarcodeAllocation;
use App\Models\PesoItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LoadingService
{
    /**
     * Scan a physical PESO manufacturer barcode
     * and assign it to a customer order item.
     *
     * IMPORTANT:
     * This does NOT reduce stock.
     * Stock was already reduced when the order was created.
     */
    public function scan(
        int $organizationId,
        int $warehouseId,
        int $orderItemId,
        string $barcode,
        ?int $userId = null
    ): PesoItem {
        $barcode = trim($barcode);

        if ($barcode === '') {
            throw ValidationException::withMessages([
                'barcode' => 'Barcode is required.',
            ]);
        }

        return DB::transaction(function () use (
            $organizationId,
            $warehouseId,
            $orderItemId,
            $barcode,
            $userId
        ): PesoItem {

            /*
             * 1. Find the order item.
             */
            $orderItem = OrderItem::query()
                ->where('id', $orderItemId)
                ->whereHas('order', function ($query) use ($organizationId, $warehouseId) {
                    $query->where('organization_id', $organizationId)
                        ->where('warehouse_id', $warehouseId);
                })
                ->lockForUpdate()
                ->first();

            if ($orderItem === null) {
                throw ValidationException::withMessages([
                    'order_item_id' => 'Order item was not found for this warehouse.',
                ]);
            }

            /*
             * 2. Check whether this barcode exists
             *    in the PESO manifest for this warehouse.
             */
            $pesoItem = PesoItem::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('barcode', $barcode)
                ->lockForUpdate()
                ->first();

            if ($pesoItem === null) {
                throw ValidationException::withMessages([
                    'barcode' => 'Barcode was not found in the PESO stock for this godown.',
                ]);
            }

            /*
             * 3. Barcode must have been physically received.
             */
            if ($pesoItem->status !== 'received') {
                throw ValidationException::withMessages([
                    'barcode' => 'This barcode has not been received yet.',
                ]);
            }

            /*
             * Partial products allocate quantities from a persistent barcode
             * balance. Keep the legacy one-row/one-unit assignment path below
             * unchanged for every other product.
             */
            $orderItem->loadMissing('product');
            $product = $orderItem->product;

            if (($pesoItem->barcode_level ?? 'L1') !== 'L1') {
                throw ValidationException::withMessages([
                    'barcode' => 'Only an L1 package barcode can be allocated to an order; child barcode levels are trace-only.',
                ]);
            }

            if ((int) ($pesoItem->product_variant_id ?? 0) !== (int) ($orderItem->product_variant_id ?? 0)) {
                throw ValidationException::withMessages([
                    'barcode' => 'Barcode size-code stock does not match the selected order specification.',
                ]);
            }

            if ($product?->partial_barcode_sales) {
                if ((int) $pesoItem->product_id !== (int) $orderItem->product_id) {
                    throw ValidationException::withMessages([
                        'barcode' => 'Barcode product does not match the customer order item.',
                    ]);
                }

                $allocated = (int) PesoBarcodeAllocation::query()
                    ->where('order_item_id', $orderItem->id)
                    ->selectRaw("COALESCE(SUM(CASE WHEN event_type = 'allocated' THEN quantity ELSE -quantity END), 0) as quantity")
                    ->value('quantity');
                $outstanding = (int) $orderItem->quantity - $allocated;

                if ($outstanding <= 0) {
                    throw ValidationException::withMessages([
                        'barcode' => 'All required quantities for this order item have already been loaded.',
                    ]);
                }

                $remaining = (int) $pesoItem->remaining_quantity;
                if ((int) $pesoItem->original_quantity < 1
                    || $remaining < 0
                    || $remaining > (int) $pesoItem->original_quantity) {
                    throw ValidationException::withMessages([
                        'barcode' => 'This barcode has an invalid quantity balance.',
                    ]);
                }

                if ($pesoItem->base_unit !== $product->base_inventory_unit) {
                    throw ValidationException::withMessages([
                        'barcode' => 'Barcode quantity unit does not match the product base unit.',
                    ]);
                }

                if ($remaining === 0) {
                    throw ValidationException::withMessages([
                        'barcode' => 'This barcode has no remaining quantity available.',
                    ]);
                }

                if ($remaining < $outstanding) {
                    throw ValidationException::withMessages([
                        'barcode' => "This barcode has {$remaining} {$product->base_inventory_unit} remaining, fewer than the {$outstanding} required by this order line.",
                    ]);
                }

                $quantity = $outstanding;
                $afterAllocation = $remaining - $quantity;
                $increment = max(1, (int) $product->sale_increment);
                if (! $product->acceptsSaleQuantity($quantity)
                    || ($afterAllocation > 0 && $afterAllocation % $increment !== 0)) {
                    throw ValidationException::withMessages([
                        'barcode' => "The remaining barcode quantity cannot satisfy this order in the configured {$increment} {$product->base_inventory_unit} increment.",
                    ]);
                }

                $pesoItem->remaining_quantity = $afterAllocation;
                $pesoItem->base_unit = $product->base_inventory_unit;
                $pesoItem->save();

                PesoBarcodeAllocation::create([
                    'peso_item_id' => $pesoItem->id,
                    'order_item_id' => $orderItem->id,
                    'event_type' => 'allocated',
                    'quantity' => $quantity,
                    'occurred_at' => now(),
                    'reason' => 'PESO barcode loaded for order',
                ]);

                return $pesoItem->fresh(['product', 'orderItem']);
            }

            /*
             * 4. Barcode must not already belong
             *    to another customer order.
             */
            if ($pesoItem->order_item_id !== null) {
                throw ValidationException::withMessages([
                    'barcode' => 'This barcode has already been assigned to an order.',
                ]);
            }
            
          $assignedCount = PesoItem::query()
    ->where('order_item_id', $orderItem->id)
    ->count();

if ($assignedCount >= (int) $orderItem->quantity) {
    throw ValidationException::withMessages([
        'barcode' => 'All required barcodes for this order item have already been loaded.',
    ]);
}
            /*
             * 5. Product must match the customer order item.
             */
            if ((int) $pesoItem->product_id !== (int) $orderItem->product_id) {
                throw ValidationException::withMessages([
                    'barcode' => 'Barcode product does not match the customer order item.',
                ]);
            }
            $assignedCount = PesoItem::query()
    ->where('order_item_id', $orderItem->id)
    ->count();

if ($assignedCount >= (int) $orderItem->quantity) {
    throw ValidationException::withMessages([
        'barcode' => 'All required barcodes for this order item have already been loaded.',
    ]);
}

            /*
             * 6. Assign the exact physical barcode
             *    to this order item.
             *
             * DO NOT change stock here.
             */
            $pesoItem->order_item_id = $orderItem->id;
            $pesoItem->save();

            return $pesoItem->fresh([
                'product',
                'orderItem',
            ]);
        });
    }
}
