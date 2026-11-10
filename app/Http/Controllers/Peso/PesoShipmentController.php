<?php

declare(strict_types=1);

namespace App\Http\Controllers\Peso;

use App\Http\Controllers\Controller;
use App\Http\Requests\Peso\UploadPesoRequest;
use App\Services\Peso\PesoShipmentImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class PesoShipmentController extends Controller
{
    public function __construct(
        private readonly PesoShipmentImportService $importService,
    ) {
    }

    public function create(): Response
    {
        return Inertia::render('Peso/Upload');
    }

    public function store(UploadPesoRequest $request): RedirectResponse
{
    $file = $request->file('re12_pdf');

    $tempPath = $file->getRealPath();

    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($tempPath);
    $text = $pdf->getText();

    dd([
        'original_name' => $file->getClientOriginalName(),
        'mime_type' => $file->getMimeType(),
        'size' => $file->getSize(),
        'text_length' => strlen($text),
        'text_preview' => substr($text, 0, 10000),
    ]);

    $path = $file->store('peso');

        try {
            $shipment = $this->importService->import(
                Storage::disk('local')->path($path),
                (int) auth()->user()->organization_id,
            );

            return redirect()
                ->route('peso.shipments.show', $shipment->id)
                ->with('success', 'PESO RE-12 imported successfully.');
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    public function show(int $id): Response
    {
        $shipment = \App\Models\PesoShipment::query()
            ->with(['warehouse', 'items'])
            ->findOrFail($id);

        return Inertia::render('Peso/Show', [
            'shipment' => $shipment,
        ]);
    }
}