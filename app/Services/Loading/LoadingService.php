<?php

declare(strict_types=1);

namespace App\Services\Loading;

use App\Models\Order\OrderItem;
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
