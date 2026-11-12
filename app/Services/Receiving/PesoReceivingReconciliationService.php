<?php

declare(strict_types=1);

namespace App\Services\Receiving;

use App\Models\PesoItem;
use App\Models\ReceivingScan;
use Illuminate\Support\Facades\DB;

final class PesoReceivingReconciliationService
{
    /**
     * Reconcile physically scanned barcodes against one PESO shipment.
     */
    public function reconcileShipment(int $pesoShipmentId): array
    {
        return DB::transaction(function () use ($pesoShipmentId): array {

            $pesoItems = PesoItem::query()
                ->where('peso_shipment_id', $pesoShipmentId)
                ->get();

            $verified = 0;
            $unexpected = 0;
            $pending = 0;
            $missing = 0;

            foreach ($pesoItems as $pesoItem) {

                /*
                 * Has this PESO barcode already been physically scanned?
                 */
                $scan = ReceivingScan::query()
                    ->where('organization_id', $pesoItem->organization_id)
                    ->where('warehouse_id', $pesoItem->warehouse_id)
                    ->where('barcode', $pesoItem->barcode)
                    ->whereIn('status', [
                        'RECEIVED_PENDING_VERIFICATION',
                        'VERIFIED',
                    ])
                    ->latest('id')
                    ->first();

                if ($scan === null) {
                    /*
                     * PESO expects this barcode but nobody has scanned it.
                     */
                    if ($pesoItem->status === 'expected') {
                        $missing++;
                    }

                    continue;
                }

                /*
                 * Already verified.
                 */
                if ($scan->status === 'VERIFIED') {
                    $verified++;
                    continue;
                }

                /*
                 * Physical scan existed before the PESO manifest.
                 * Now the PESO barcode has been found.
                 */
                if ($scan->status === 'RECEIVED_PENDING_VERIFICATION') {

                    $scan->update([
                        'status' => 'VERIFIED',
                        'peso_item_id' => $pesoItem->id,
                        'peso_shipment_id' => $pesoItem->peso_shipment_id,
                        'notes' => 'Physical barcode matched the PESO manifest during reconciliation.',
                    ]);

                    $pesoItem->update([
                        'status' => 'received',
                        'received_at' => $scan->scanned_at ?? now(),
                    ]);

                    $verified++;
                }
            }

            /*
             * Find physical scans that still have no PESO match.
             */
            $pendingScans = ReceivingScan::query()
                ->where('status', 'RECEIVED_PENDING_VERIFICATION')
                ->where('warehouse_id', $pesoItems->first()?->warehouse_id)
                ->get();

            foreach ($pendingScans as $scan) {

                $matchingPesoItem = PesoItem::query()
                    ->where('organization_id', $scan->organization_id)
                    ->where('warehouse_id', $scan->warehouse_id)
                    ->where('barcode', $scan->barcode)
                    ->exists();

                if (!$matchingPesoItem) {
                    /*
                     * Do not mark it UNEXPECTED merely because this
                     * particular shipment doesn't contain it.
                     *
                     * It could belong to another future/previous shipment.
                     */
                    $pending++;
                }
            }

            /*
             * Count PESO items still waiting for physical scanning.
             */
            $missing = PesoItem::query()
                ->where('peso_shipment_id', $pesoShipmentId)
                ->where('status', 'expected')
                ->count();

            /*
             * Update shipment received count.
             */
            $shipment = $pesoItems->first()?->shipment;

            if ($shipment !== null) {
                $shipment->update([
                    'received_packages' => PesoItem::query()
                        ->where('peso_shipment_id', $pesoShipmentId)
                        ->where('status', 'received')
                        ->count(),
                ]);
            }

            return [
                'peso_shipment_id' => $pesoShipmentId,
                'total' => $pesoItems->count(),
                'verified' => $verified,
                'pending' => $pending,
                'missing' => $missing,
                'unexpected' => $unexpected,
            ];
        });
    }
}