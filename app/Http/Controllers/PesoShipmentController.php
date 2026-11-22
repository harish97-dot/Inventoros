<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PesoItem;
use App\Models\PesoShipment;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PesoShipmentController extends Controller
{
    /**
     * PESO dashboard / uploaded RE-12 list.
     */
    public function index(Request $request): Response
    {
        $organizationId = (int) ($request->user()?->organization_id ?? 1);

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
     * Uploaded RE-12 PDF page.
     */
    public function index(Request $request): Response
{
    $organizationId = (int) ($request->user()?->organization_id ?? 1);

    $shipments = PesoShipment::query()
        ->whereHas('items', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })
        ->with('warehouse')
        ->withCount([
            'items as total_items',
            'items as received_items' => function ($query) {
                $query->where('status', 'received');
            },
            'items as pending_items' => function ($query) {
                $query->where('status', 'expected');
            },
        ])
        ->orderByDesc('id')
        ->get();

    $totalBarcodes = $shipments->sum('total_items');
    $receivedBarcodes = $shipments->sum('received_items');
    $pendingBarcodes = $shipments->sum('pending_items');

    return Inertia::render('Peso/Index', [
        'shipments' => $shipments,
        'summary' => [
            'shipments' => $shipments->count(),
            'total_barcodes' => $totalBarcodes,
            'received_barcodes' => $receivedBarcodes,
            'pending_barcodes' => $pendingBarcodes,
        ],
    ]);
}

public function pendingReceiving(Request $request): Response
{
    $organizationId = (int) ($request->user()?->organization_id ?? 1);

    $items = PesoItem::query()
        ->where('organization_id', $organizationId)
        ->where('status', 'expected')
        ->with([
            'shipment',
            'warehouse',
        ])
        ->orderBy('peso_shipment_id')
        ->orderBy('id')
        ->get();

    return Inertia::render('Peso/PendingReceiving', [
        'items' => $items,
        'count' => $items->count(),
    ]);
}
    public function create(Request $request): Response
    {
        $organizationId = (int) ($request->user()?->organization_id ?? 1);

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

        return Inertia::render('Peso/Upload', [
            'shipments' => $shipments,
        ]);
    }

    /**
     * Pending barcode receiving page.
     */
    public function pendingReceiving(Request $request): Response
    {
        $organizationId = (int) ($request->user()?->organization_id ?? 1);

        $items = PesoItem::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'expected')
            ->with([
                'shipment:id,re12_number,peso_date,licence_number,warehouse_id',
                'warehouse:id,name',
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
            ]);

        return Inertia::render('Peso/PendingReceiving', [
            'items' => $items,
        ]);
    }

    /**
     * Show one RE-12 shipment.
     */
    public function show(Request $request, int $id): Response
    {
        $organizationId = (int) ($request->user()?->organization_id ?? 1);

        $shipment = PesoShipment::query()
            ->whereKey($id)
            ->whereHas('items', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->with([
                'warehouse:id,name',
                'items' => function ($query) use ($organizationId) {
                    $query
                        ->where('organization_id', $organizationId)
                        ->orderBy('id');
                },
            ])
            ->firstOrFail();

        return Inertia::render('Peso/Show', [
            'shipment' => $shipment,
        ]);
    }
}
