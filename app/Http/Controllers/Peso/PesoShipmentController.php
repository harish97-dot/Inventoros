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
use App\Models\Product;
use Illuminate\Http\Request;

final class PesoShipmentController extends Controller
{
    public function __construct(
    private readonly PesoShipmentImportService $importService,
    private readonly PesoReceivingReconciliationService $reconciliationService,
) {
}

    public function create(): Response
    {
        return Inertia::render("Peso/Upload");
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
            ->with(["warehouse", "items"])
            ->findOrFail($id);

        return Inertia::render("Peso/Show", [
            "shipment" => $shipment,
        ]);
    }

    public function receiveAll(int $id): RedirectResponse
    {
        $shipment = PesoShipment::findOrFail($id);

        DB::transaction(function () use ($shipment) {
            PesoItem::where("peso_shipment_id", $shipment->id)->update([
                "status" => "received",
                "updated_at" => now(),
            ]);

            $total = PesoItem::where("peso_shipment_id", $shipment->id)->count();

            $shipment->update([
                "status" => "received",
                "received_packages" => $total,
            ]);
        });

        return redirect()->route("peso.shipments.show", $shipment->id)
            ->with("success", "All cartons received into Godown stock successfully.");
    }

public function mapProduct(Request $request, int $id): RedirectResponse
{
    $validated = $request->validate([
        'product_id' => ['required', 'integer', 'exists:products,id'],
    ]);

    $shipment = PesoShipment::findOrFail($id);

    DB::transaction(function () use ($shipment, $validated) {
        PesoItem::where('peso_shipment_id', $shipment->id)
            ->update([
                'product_id' => (int) $validated['product_id'],
                'updated_at' => now(),
            ]);
    });

    return redirect()
        ->route('peso.shipments.show', $shipment->id)
        ->with('success', 'PESO product mapping updated successfully.');
}
}
