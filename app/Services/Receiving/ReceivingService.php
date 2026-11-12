<?php

declare(strict_types=1);

namespace App\Services\Receiving;

use App\Models\PesoItem;
use App\Models\PesoShipment;
use App\Models\ReceivingScan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReceivingService
{
    public function scan(
        int $organizationId,
        int $warehouseId,
        string $barcode,
        ?int $userId = null
    ): ReceivingScan {
        $barcode = trim($barcode);

        if ($barcode === '') {
            throw ValidationException::withMessages([
                'barcode' => 'Barcode is required.',
            ]);
        }

        return DB::transaction(function () use (
            $organizationId,
            $warehouseId,
            $barcode,
            $userId
        ): ReceivingScan {

            /*
             * Check whether this barcode was already physically scanned.
             */
            $existing = ReceivingScan::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('barcode', $barcode)
                ->where('status', '!=', 'REJECTED')
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return ReceivingScan::create([
                    'organization_id' => $organizationId,
                    'warehouse_id' => $warehouseId,
                    'barcode' => $barcode,
                    'status' => 'DUPLICATE',
                    'scanned_by' => $userId,
                    'scanned_at' => now(),
                    'peso_item_id' => $existing->peso_item_id,
                    'peso_shipment_id' => $existing->peso_shipment_id,
                    'notes' => "Barcode was already scanned in receiving scan #{$existing->id}.",
                ]);
            }

            /*
             * Check whether the barcode already exists in an uploaded
             * PESO manifest for this organization and godown.
             */
            $pesoItem = PesoItem::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('barcode', $barcode)
                ->whereIn('status', ['expected', 'received'])
                ->latest('id')
                ->first();

            /*
             * PESO PDF already uploaded and barcode exists.
             */
            if ($pesoItem !== null && $pesoItem->status === 'expected') {

                $scan = ReceivingScan::create([
                    'organization_id' => $organizationId,
                    'warehouse_id' => $warehouseId,
                    'barcode' => $barcode,
                    'status' => 'VERIFIED',
                    'scanned_by' => $userId,
                    'scanned_at' => now(),
                    'peso_item_id' => $pesoItem->id,
                    'peso_shipment_id' => $pesoItem->peso_shipment_id,
                    'notes' => 'Physical barcode matched the PESO expected barcode.',
                ]);

                $pesoItem->update([
                    'status' => 'received',
                    'received_at' => now(),
                ]);

                // Keep the parent PESO shipment receiving summary
                // synchronized with the actual received PESO items.
                $shipment = PesoShipment::query()
                    ->find($pesoItem->peso_shipment_id);

                if ($shipment !== null) {
                    $receivedPackages = PesoItem::query()
                        ->where('peso_shipment_id', $shipment->id)
                        ->where('status', 'received')
                        ->count();

                    $totalPackages = PesoItem::query()
                        ->where('peso_shipment_id', $shipment->id)
                        ->count();

                    $shipment->update([
                        'received_packages' => $receivedPackages,
                        'status' => $receivedPackages >= $totalPackages
                            ? 'received'
                            : 'ready_for_receiving',
                    ]);
                }

                return $scan->fresh([
                    'pesoItem',
                    'pesoShipment',
                ]);
            }

            /*
             * PESO PDF has not been uploaded yet.
             *
             * We still record the physical scan immediately.
             * Later, reconciliation can match this scan against PESO.
             */
            return ReceivingScan::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'barcode' => $barcode,
                'status' => 'RECEIVED_PENDING_VERIFICATION',
                'scanned_by' => $userId,
                'scanned_at' => now(),
                'notes' => 'Physical barcode received before a matching PESO manifest was available.',
            ]);
        });
    }
}