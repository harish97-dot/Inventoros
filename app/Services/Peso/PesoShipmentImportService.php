<?php

declare(strict_types=1);

namespace App\Services\Peso;

use App\Models\PesoItem;
use App\Models\PesoShipment;
use App\Models\WarehouseLicence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PesoShipmentImportService
{
    public function __construct(
        private readonly PesoPdfParser $parser,
    ) {
    }

    public function import(string $pdfPath, int $organizationId): PesoShipment
    {
        $data = $this->parser->parse($pdfPath);

        $licenceNumber = trim($data['licence_number'] ?? '');

        if ($licenceNumber === '') {
            throw ValidationException::withMessages([
                're12_pdf' => 'Could not detect a consignee licence number from the PESO PDF.',
            ]);
        }

        $licence = WarehouseLicence::query()
            ->where('licence_number', $licenceNumber)
            ->where('is_active', true)
            ->first();

        if ($licence === null) {
            throw ValidationException::withMessages([
                're12_pdf' =>
                    "Licence '{$licenceNumber}' is not configured in the system. " .
                    'Please configure the licence-to-Godown mapping before importing this PDF.',
            ]);
        }

        $items = $data['items'] ?? [];

        if ($items === []) {
            throw ValidationException::withMessages([
                're12_pdf' => 'No PESO product/barcode items were extracted from the PDF.',
            ]);
        }

        $barcodeCount = 0;

        foreach ($items as $item) {
            $barcodeCount += count($item['barcodes'] ?? []);
        }

        $expectedPackages = (int) ($data['total_packages'] ?? 0);

        if ($barcodeCount !== $expectedPackages) {
            throw ValidationException::withMessages([
                're12_pdf' =>
                    "PESO PDF package count is {$expectedPackages}, but {$barcodeCount} " .
                    'barcodes were extracted. Import was stopped.',
            ]);
        }

        return DB::transaction(function () use (
            $data,
            $licence,
            $organizationId,
            $pdfPath,
            $items
        ): PesoShipment {
            $existing = PesoShipment::query()
                ->where('re12_number', $data['re12_number'])
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    're12_pdf' =>
                        "RE-12 '{$data['re12_number']}' has already been imported.",
                ]);
            }

            $shipment = PesoShipment::create([
                're12_number' => $data['re12_number'],
                'peso_date' => $data['peso_date'],
                'licence_number' => $licence->licence_number,
                'warehouse_id' => $licence->warehouse_id,
                'pdf_path' => $pdfPath,
                'status' => 'ready_for_receiving',
                'total_packages' => $data['total_packages'],
                'received_packages' => 0,
            ]);

            foreach ($items as $item) {
                $productName = trim((string) ($item['product_name'] ?? ''));
                $sizeCode = trim((string) ($item['size_code'] ?? ''));

                foreach ($item['barcodes'] as $barcode) {
                    PesoItem::create([
                        'peso_shipment_id' => $shipment->id,
                        'organization_id' => $organizationId,
                        'warehouse_id' => $licence->warehouse_id,
                        'product_id' => null,
                        'product_name' => $productName,
                        'size_code' => $sizeCode !== '' ? $sizeCode : null,
                        'barcode' => trim((string) $barcode),
                        'status' => 'expected',
                    ]);
                }
            }

            return $shipment->load('items');
        });
    }
}