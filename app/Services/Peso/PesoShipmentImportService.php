<?php

declare(strict_types=1);

namespace App\Services\Peso;

use App\Models\PesoItem;
use App\Models\PesoShipment;
use App\Models\PesoProductMapping;
use App\Models\Inventory\Product;
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
            $records = $item['barcode_records'] ?? array_map(
                fn (string $barcode): array => ['barcode' => $barcode, 'level' => 'L1', 'parent_barcode' => null],
                $item['barcodes'] ?? [],
            );
            $barcodeCount += count(array_filter($records, fn (array $record) => ($record['level'] ?? 'L1') === 'L1'));
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
                $mapping = PesoProductMapping::query()
                    ->where('organization_id', $organizationId)
                    ->where('manufacturer_name_key', PesoProductMapping::exactNameKey($productName))
                    ->where('re12_code', $sizeCode)
                    ->first();
                $mappedProduct = $mapping?->product_id === null
                    ? null
                    : Product::query()
                        ->where('organization_id', $organizationId)
                        ->find($mapping->product_id);
                // Partial products cannot enter stock with a default package quantity.
                // Their product_id is attached only after staff confirms each physical quantity.
                $productId = $mappedProduct !== null && ! $mappedProduct->partial_barcode_sales
                    ? $mappedProduct->id
                    : null;
                $itemsByBarcode = [];
                $records = $item['barcode_records'] ?? array_map(
                    fn (string $barcode): array => ['barcode' => $barcode, 'level' => 'L1', 'parent_barcode' => null],
                    $item['barcodes'] ?? [],
                );

                foreach ($records as $record) {
                    $barcode = trim((string) ($record['barcode'] ?? ''));
                    if ($barcode === '') continue;
                    $level = in_array($record['level'] ?? 'L1', ['L1', 'L2', 'L3'], true) ? $record['level'] : 'L1';
                    $parentId = isset($record['parent_barcode']) ? ($itemsByBarcode[$record['parent_barcode']] ?? null) : null;
                    $pesoItem = PesoItem::create([
                        'peso_shipment_id' => $shipment->id,
                        'organization_id' => $organizationId,
                        'warehouse_id' => $licence->warehouse_id,
                        'product_id' => $level === 'L1' ? $productId : null,
                        'product_variant_id' => $level === 'L1' ? $mapping?->product_variant_id : null,
                        'peso_product_mapping_id' => $mapping?->id,
                        'product_name' => $productName,
                        'size_code' => $sizeCode !== '' ? $sizeCode : null,
                        'barcode' => $barcode,
                        'barcode_level' => $level,
                        'parent_peso_item_id' => $parentId,
                        // This is source-document metadata for the product group,
                        // not a stock quantity. Preserve it on each barcode row;
                        // only L1 rows are eligible to book inventory.
                        'declared_quantity' => $item['declared_quantity'] ?? null,
                        'declared_unit' => $item['declared_unit'] ?? null,
                        'package_information' => $item['package_information'] ?? null,
                        'status' => 'expected',
                    ]);
                    $itemsByBarcode[$barcode] = $pesoItem->id;
                }
            }

            return $shipment->load('items');
        });
    }
}
