<?php

declare(strict_types=1);

namespace App\Http\Controllers\Receiving;

use App\Http\Controllers\Controller;
use App\Services\Receiving\ReceivingService;
use App\Models\PesoItem;
use App\Models\PesoShipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReceivingController extends Controller
{
    public function __construct(
        private readonly ReceivingService $receivingService,
    ) {
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'barcode' => ['required', 'string', 'max:150'],
        ]);

        $user = $request->user();

        $scan = $this->receivingService->scan(
            organizationId: (int) ($user?->organization_id ?? 1),
            warehouseId: (int) $validated['warehouse_id'],
            barcode: $validated['barcode'],
            userId: $user?->id,
        );

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
    'success' => true,

    'message' => match ($scan->status) {
        'VERIFIED' => 'Barcode received successfully.',
        'DUPLICATE' => 'Barcode was already scanned.',
        'RECEIVED_PENDING_VERIFICATION' =>
            'Barcode recorded and is pending PESO verification.',
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
    ],

    'received_count' => $receivedCount,
    'total_packages' => $totalPackages,
    'shipment_status' => $shipment?->status,
]);
    }
}