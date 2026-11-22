<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\PesoBarcodeAllocation;
use App\Models\PesoItem;
use App\Models\PesoProductMapping;
use App\Models\PesoShipment;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLicence;
use App\Services\Loading\LoadingService;
use App\Services\OrderService;
use App\Services\Peso\PesoPdfParser;
use App\Support\ApprovalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Runs only when REAL_PESO_PDF_DIR points at untouched, real RE-12 PDFs.
 * Manually entered quantities in this test are explicitly test fixture values,
 * not quantities claimed to have been extracted from the source PDF.
 */
final class RealPesoPdfMappingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    public static function realPdfFiles(): array
    {
        $directory = getenv('REAL_PESO_PDF_DIR');
        $files = $directory ? glob(rtrim($directory, '/') . '/test*.pdf') : [];
        if (! $files) {
            return ['real PDFs not configured' => [null]];
        }

        $cases = [];
        foreach ($files as $path) {
            $cases[basename($path)] = [$path];
        }

        return $cases;
    }

    #[DataProvider('realPdfFiles')]
    public function test_real_pdf_upload_mapping_quantity_receiving_stock_and_partial_order_flow(?string $pdfPath): void
    {
        if ($pdfPath === null || ! is_file($pdfPath)) {
            $this->markTestSkipped('Set REAL_PESO_PDF_DIR to a directory containing original test*.pdf files.');
        }

        SystemSetting::set('installed', true, 'boolean');
        $organization = Organization::create([
            'name' => 'Real PESO PDF Test',
            'email' => 'real-peso-' . uniqid() . '@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $receiver = $this->user($organization, 'receiver');
        $approver = $this->user($organization, 'approver');

        $godown1 = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Godown 1',
            'code' => 'REAL-GD1',
            'is_active' => true,
        ]);
        $godown2 = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Godown 2',
            'code' => 'REAL-GD2',
            'is_active' => true,
        ]);
        WarehouseLicence::create([
            'warehouse_id' => $godown1->id,
            'licence_number' => 'E/HQ/TG/22/759(E48397)',
            'is_active' => true,
        ]);
        WarehouseLicence::create([
            'warehouse_id' => $godown2->id,
            'licence_number' => 'E/HQ/TG/22/619(E49505)',
            'is_active' => true,
        ]);

        $parsed = app(PesoPdfParser::class)->parse($pdfPath);
        $this->assertNotEmpty($parsed['re12_number']);
        $this->assertNotEmpty($parsed['licence_number']);
        $this->assertGreaterThan(0, $parsed['total_packages']);
        $expectedL1Barcodes = collect($parsed['items'])->flatMap(fn (array $group) => $group['barcodes'])->values();
        $expectedRecords = collect($parsed['items'])->flatMap(fn (array $group) => $group['barcode_records'] ?? array_map(
            fn (string $barcode) => ['barcode' => $barcode, 'level' => 'L1', 'parent_barcode' => null],
            $group['barcodes'],
        ))->values();
        $this->assertSame($parsed['total_packages'], $expectedL1Barcodes->count(), 'Package rows validate against L1 package barcodes, not trace children.');

        $response = $this->actingAs($receiver)->post(route('peso.upload.store'), [
            're12_pdf' => UploadedFile::fake()->createWithContent(basename($pdfPath), file_get_contents($pdfPath)),
        ]);
        $response->assertRedirect()->assertSessionHasNoErrors();

        $shipment = PesoShipment::query()->where('re12_number', $parsed['re12_number'])->firstOrFail();
        $items = PesoItem::query()->where('peso_shipment_id', $shipment->id)->get();
        $this->assertSame($parsed['total_packages'], (int) $shipment->total_packages);
        $this->assertCount($expectedRecords->count(), $items);
        $this->assertSame(
            $expectedRecords->pluck('barcode')->sort()->values()->all(),
            $items->pluck('barcode')->sort()->values()->all(),
            'Every imported manufacturer barcode must exactly match the PDF parser output.',
        );
        foreach ($expectedRecords->where('level', 'L2') as $childRecord) {
            $child = $items->firstWhere('barcode', $childRecord['barcode']);
            $this->assertSame('L2', $child->barcode_level);
            $this->assertSame($childRecord['parent_barcode'], $child->parentBarcode?->barcode);
            $this->assertNull($child->product_id, 'Trace-level barcodes must not become additional stock packages.');
        }
        foreach ($parsed['items'] as $group) {
            foreach ($group['barcode_records'] as $record) {
                $pesoItem = $items->firstWhere('barcode', $record['barcode']);
                $this->assertNotNull($pesoItem);
                $this->assertSame($group['product_name'], $pesoItem->product_name);
                $this->assertSame($group['size_code'], $pesoItem->size_code);
                $this->assertEquals(
                    $group['declared_quantity'] === null ? null : (float) $group['declared_quantity'],
                    $pesoItem->declared_quantity === null ? null : (float) $pesoItem->declared_quantity,
                );
                $this->assertSame($group['declared_unit'], $pesoItem->declared_unit);
                $this->assertSame($group['package_information'], $pesoItem->package_information);
            }
        }
        $warehouse = $parsed['licence_number'] === 'E/HQ/TG/22/759(E48397)' ? $godown1 : $godown2;
        $wrongWarehouse = $warehouse->is($godown1) ? $godown2 : $godown1;
        $this->assertSame((int) $warehouse->id, (int) $shipment->warehouse_id);

        $location = ProductLocation::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'name' => 'Real PDF test bin',
            'code' => 'REAL-PDF-BIN',
            'is_active' => true,
        ]);

        $itemProductIds = [];
        $barcodeQuantities = [];
        $productsByGroup = [];
        foreach ($parsed['items'] as $index => $group) {
            $product = Product::create([
                'organization_id' => $organization->id,
                'sku' => 'REAL-PDF-' . $index,
                'name' => $group['product_name'] . ' ' . $group['size_code'] . ' test mapping',
                'price' => 10,
                'currency' => 'USD',
                'stock' => 0,
                'min_stock' => 0,
                'is_active' => true,
                'location_id' => $location->id,
                // First group exercises configurable partial-barcode handling in a disposable catalog row.
                'base_inventory_unit' => $index === 0 ? 'm' : 'package',
                'partial_barcode_sales' => $index === 0,
                'minimum_sale_quantity' => $index === 0 ? 375 : 1,
                'sale_increment' => $index === 0 ? 375 : 1,
            ]);
            $productsByGroup[] = $product;

            $groupItems = $items->where('product_name', $group['product_name'])
                ->where('size_code', $group['size_code']);
            $this->assertCount(count($group['barcodes']), $groupItems->where('barcode_level', 'L1'));
            foreach ($groupItems as $item) {
                $itemProductIds[$item->id] = $product->id;
                if ($index === 0 && $item->barcode_level === 'L1') {
                    // Explicit manual fixture quantity; the PDF is not asserted to contain this value.
                    $barcodeQuantities[$item->id] = 750;
                }
            }
        }

        $invalidQuantities = $barcodeQuantities;
        $firstBarcodeId = array_key_first($barcodeQuantities);
        $invalidQuantities[$firstBarcodeId] = 500;
        $this->actingAs($receiver)->post(route('peso.shipments.map-product', $shipment->id), [
            'item_product_ids' => $itemProductIds,
            'barcode_quantities' => $invalidQuantities,
        ])->assertSessionHasErrors(["barcode_quantities.{$firstBarcodeId}"]);
        $this->assertNull(PesoItem::query()->findOrFail($firstBarcodeId)->product_id, 'Invalid original quantity must not partially save a mapping.');

        $this->actingAs($receiver)->post(route('peso.shipments.map-product', $shipment->id), [
            'item_product_ids' => $itemProductIds,
            'barcode_quantities' => $barcodeQuantities,
        ])->assertRedirect(route('peso.shipments.show', $shipment->id));

        $items = PesoItem::query()->where('peso_shipment_id', $shipment->id)->get();
        foreach ($parsed['items'] as $index => $group) {
            $mappedGroup = $items->where('product_name', $group['product_name'])
                ->where('size_code', $group['size_code'])
                ->where('barcode_level', 'L1')
                ->where('product_id', $productsByGroup[$index]->id);
            $this->assertCount(count($group['barcodes']), $mappedGroup, 'Each RE-12 name/size group must remain mapped independently.');
            if ($index > 0) {
                $this->assertSame('package', $mappedGroup->first()->quantity_source);
            }
        }
        $firstGroupProduct = $productsByGroup[0];
        $firstGroupItem = $items->firstWhere('product_id', $firstGroupProduct->id);
        $this->assertNotNull($firstGroupItem);
        $this->assertSame('manual', $firstGroupItem->fresh()->quantity_source);
        $this->assertSame(750, (int) $firstGroupItem->fresh()->original_quantity);
        $this->assertSame(750, (int) $firstGroupItem->fresh()->remaining_quantity);
        $this->assertSame('m', $firstGroupItem->fresh()->base_unit);

        $this->postJson(route('receiving.scan'), [
            'warehouse_id' => $wrongWarehouse->id,
            'barcode' => $firstGroupItem->barcode,
        ])->assertStatus(422)->assertJsonPath('scan.status', 'REJECTED');
        $this->assertSame('expected', $firstGroupItem->fresh()->status);

        $this->postJson(route('receiving.scan'), [
            'warehouse_id' => $warehouse->id,
            'barcode' => $firstGroupItem->barcode,
        ])->assertOk()->assertJsonPath('scan.status', 'VERIFIED');

        $this->assertSame(750, (int) $firstGroupProduct->fresh()->stock);
        $this->assertSame(750, (int) ProductLocationStock::query()
            ->where('product_id', $firstGroupProduct->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
        $this->assertSame(1, StockAdjustment::query()
            ->where('type', 'peso_receipt')
            ->where('reference_type', PesoItem::class)
            ->where('reference_id', $firstGroupItem->id)
            ->count());
        $this->assertSame(750, (int) PesoItem::query()
            ->where('product_id', $firstGroupProduct->id)
            ->where('status', 'received')
            ->sum('remaining_quantity'));

        $this->postJson(route('receiving.scan'), [
            'warehouse_id' => $warehouse->id,
            'barcode' => $firstGroupItem->barcode,
        ])->assertOk()->assertJsonPath('scan.status', 'DUPLICATE');
        $this->assertSame(750, (int) $firstGroupProduct->fresh()->stock);
        $this->assertSame(1, StockAdjustment::query()
            ->where('type', 'peso_receipt')
            ->where('reference_type', PesoItem::class)
            ->where('reference_id', $firstGroupItem->id)
            ->count());

        $settings = $organization->settings ?? [];
        $settings[ApprovalSettings::KEY] = array_merge(
            $settings[ApprovalSettings::KEY] ?? [],
            ['orders_enabled' => true],
        );
        $organization->forceFill(['settings' => $settings])->save();

        $order = app(OrderService::class)->create([
            'customer_name' => 'Real PDF workflow customer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'items' => [[
                'product_id' => $firstGroupProduct->id,
                'quantity' => 375,
                'unit_price' => 10,
            ]],
        ], $receiver);
        $orderItem = $order->items()->firstOrFail();
        $this->assertSame(375, (int) $firstGroupProduct->fresh()->stock);
        $this->assertSame(375, (int) ProductLocationStock::query()
            ->where('product_id', $firstGroupProduct->id)
            ->where('location_id', $location->id)
            ->value('quantity'));

        app(LoadingService::class)->scan(
            (int) $organization->id,
            (int) $warehouse->id,
            (int) $orderItem->id,
            $firstGroupItem->barcode,
            (int) $receiver->id,
        );
        $this->assertSame(375, (int) $firstGroupItem->fresh()->remaining_quantity);
        $this->assertSame(375, (int) $firstGroupProduct->fresh()->stock);
        $this->assertSame(375, (int) ProductLocationStock::query()
            ->where('product_id', $firstGroupProduct->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
        $this->assertSame(375, (int) PesoBarcodeAllocation::query()
            ->where('peso_item_id', $firstGroupItem->id)
            ->where('event_type', 'allocated')
            ->sum('quantity'));

        $approved = app(OrderService::class)->approve($order, $approver);
        $this->assertSame(OrderApprovalStatus::APPROVED, $approved->approval_status);
        $this->assertSame(OrderStatus::SHIPPED, $approved->status);
        $this->assertSame(375, (int) $firstGroupProduct->fresh()->stock);
        $this->assertSame(375, (int) ProductLocationStock::query()
            ->where('product_id', $firstGroupProduct->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
        $this->assertSame(375, (int) PesoItem::query()
            ->where('product_id', $firstGroupProduct->id)
            ->where('status', 'received')
            ->sum('remaining_quantity'));
    }

    public function test_approved_manufacturer_aliases_share_one_internal_product_while_unconfirmed_groups_stay_unmapped(): void
    {
        $directory = getenv('REAL_PESO_PDF_DIR');
        if (! $directory || ! is_file($directory . '/test1.pdf') || ! is_file($directory . '/test8.pdf')) {
            $this->markTestSkipped('Set REAL_PESO_PDF_DIR to the directory with the original test1.pdf and test8.pdf.');
        }

        SystemSetting::set('installed', true, 'boolean');
        $organization = Organization::create([
            'name' => 'Approved PESO Alias Test',
            'email' => 'peso-alias-' . uniqid() . '@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $receiver = $this->user($organization, 'receiver');
        $godown1 = Warehouse::create(['organization_id' => $organization->id, 'name' => 'Godown 1', 'code' => 'ALIAS-GD1', 'is_active' => true]);
        $godown2 = Warehouse::create(['organization_id' => $organization->id, 'name' => 'Godown 2', 'code' => 'ALIAS-GD2', 'is_active' => true]);
        WarehouseLicence::create(['warehouse_id' => $godown1->id, 'licence_number' => 'E/HQ/TG/22/759(E48397)', 'is_active' => true]);
        WarehouseLicence::create(['warehouse_id' => $godown2->id, 'licence_number' => 'E/HQ/TG/22/619(E49505)', 'is_active' => true]);
        $location1 = ProductLocation::create(['organization_id' => $organization->id, 'warehouse_id' => $godown1->id, 'name' => 'Alias bin 1', 'code' => 'ALIAS-BIN-1', 'is_active' => true]);
        $location2 = ProductLocation::create(['organization_id' => $organization->id, 'warehouse_id' => $godown2->id, 'name' => 'Alias bin 2', 'code' => 'ALIAS-BIN-2', 'is_active' => true]);
        $booster = Product::create([
            'organization_id' => $organization->id, 'sku' => 'TEST-BOOSTER', 'name' => 'BOOSTER',
            'price' => 10, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'is_active' => true,
            'location_id' => $location1->id, 'base_inventory_unit' => 'Nos', 'peso_barcode_managed' => true,
            'partial_barcode_sales' => false, 'minimum_sale_quantity' => 1, 'sale_increment' => 1,
        ]);
        $dCord = Product::create([
            'organization_id' => $organization->id, 'sku' => 'TEST-D-CORD', 'name' => 'D-Cord',
            'price' => 10, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'is_active' => true,
            'location_id' => $location2->id, 'base_inventory_unit' => 'm', 'peso_barcode_managed' => true,
            'partial_barcode_sales' => true, 'minimum_sale_quantity' => 375, 'sale_increment' => 375,
        ]);

        foreach ([['IDEAL Boost', 'CDA', 'BOOSTER', $booster->id], ['Kelvex-200', 'CDA', 'BOOSTER', $booster->id], ['IDEAL Cord-10', 'FAE', 'D-Cord', $dCord->id]] as [$name, $code, $internalName, $productId]) {
            PesoProductMapping::create([
                'organization_id' => $organization->id,
                'manufacturer_name' => $name,
                'manufacturer_name_key' => PesoProductMapping::exactNameKey($name),
                're12_code' => $code,
                'internal_product_name' => $internalName,
                'product_id' => $productId,
            ]);
        }

        $shipments = [];
        foreach (['test1.pdf', 'test8.pdf'] as $pdfName) {
            $this->actingAs($receiver)->post(route('peso.upload.store'), [
                're12_pdf' => UploadedFile::fake()->createWithContent($pdfName, file_get_contents($directory . '/' . $pdfName)),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $parsed = app(PesoPdfParser::class)->parse($directory . '/' . $pdfName);
            $shipments[$pdfName] = PesoShipment::query()->where('re12_number', $parsed['re12_number'])->firstOrFail();
        }

        $idealBoost = PesoItem::query()->where('peso_shipment_id', $shipments['test1.pdf']->id)->where('product_name', 'IDEAL Boost')->firstOrFail();
        $kelvex = PesoItem::query()->where('peso_shipment_id', $shipments['test8.pdf']->id)->where('product_name', 'Kelvex-200')->firstOrFail();
        $idealCord = PesoItem::query()->where('peso_shipment_id', $shipments['test1.pdf']->id)->where('size_code', 'FAE')->firstOrFail();
        $unconfirmed = PesoItem::query()->where('peso_shipment_id', $shipments['test1.pdf']->id)->where('product_name', 'IDEAL Power 90')->firstOrFail();
        $this->assertSame('IDEAL Boost', $idealBoost->product_name);
        $this->assertSame('Kelvex-200', $kelvex->product_name);
        $this->assertSame('IDEAL Cord-10', $idealCord->product_name, 'PESO import must retain the exact parser-emitted manufacturer name.');
        $this->assertSame($booster->id, $idealBoost->product_id);
        $this->assertSame($booster->id, $kelvex->product_id);
        $this->assertNull($idealCord->product_id, 'Partial product waits for manual physical quantity confirmation.');
        $this->assertNull($unconfirmed->product_id, 'Unconfirmed PDF groups remain unmapped.');

        $kelvexItems = PesoItem::query()->where('peso_shipment_id', $shipments['test8.pdf']->id)->get();
        $wrongMapping = $kelvexItems->mapWithKeys(fn (PesoItem $item) => [$item->id => $dCord->id])->all();
        $this->actingAs($receiver)->post(route('peso.shipments.map-product', $shipments['test8.pdf']->id), [
            'item_product_ids' => $wrongMapping,
            'barcode_quantities' => $kelvexItems->mapWithKeys(fn (PesoItem $item) => [$item->id => 375])->all(),
        ])->assertSessionHasErrors('item_product_ids');
        $this->assertSame($booster->id, $kelvex->fresh()->product_id, 'A saved manufacturer alias cannot be remapped to the wrong internal product.');

        $test1Items = PesoItem::query()->where('peso_shipment_id', $shipments['test1.pdf']->id)->get();
        $groupProductIds = [];
        $quantities = [];
        foreach ($test1Items as $item) {
            $groupProductIds[$item->id] = match ([$item->product_name, $item->size_code]) {
                ['IDEAL Boost', 'CDA'] => $booster->id,
                ['IDEAL Cord-10', 'FAE'] => $dCord->id,
                default => null,
            };
            if ($groupProductIds[$item->id] === $dCord->id) $quantities[$item->id] = 750; // Test-entered fixture value.
        }
        $this->actingAs($receiver)->post(route('peso.shipments.map-product', $shipments['test1.pdf']->id), [
            'item_product_ids' => $groupProductIds,
            'barcode_quantities' => $quantities,
        ])->assertRedirect(route('peso.shipments.show', $shipments['test1.pdf']->id));
        $this->assertNull($unconfirmed->fresh()->product_id);
        $this->assertSame('manual', $idealCord->fresh()->quantity_source);
        $this->assertSame(750, (int) $idealCord->fresh()->remaining_quantity);

        $this->postJson(route('receiving.scan'), ['warehouse_id' => $idealCord->warehouse_id, 'barcode' => $idealCord->barcode])
            ->assertOk()->assertJsonPath('scan.status', 'VERIFIED');
        $this->assertSame(750, (int) $dCord->fresh()->stock);
        $this->postJson(route('receiving.scan'), ['warehouse_id' => $idealCord->warehouse_id, 'barcode' => $idealCord->barcode])
            ->assertOk()->assertJsonPath('scan.status', 'DUPLICATE');
        $this->assertSame(750, (int) $dCord->fresh()->stock, 'Duplicate receiving must not increase D-Cord stock twice.');

        foreach ([$idealBoost, $kelvex] as $item) {
            $this->postJson(route('receiving.scan'), ['warehouse_id' => $item->warehouse_id, 'barcode' => $item->barcode])
                ->assertOk()->assertJsonPath('scan.status', 'VERIFIED');
            $this->postJson(route('receiving.scan'), ['warehouse_id' => $item->warehouse_id, 'barcode' => $item->barcode])
                ->assertOk()->assertJsonPath('scan.status', 'DUPLICATE');
        }
        $this->assertSame(2, (int) $booster->fresh()->stock);
        $this->assertSame(2, StockAdjustment::query()->where('product_id', $booster->id)->where('type', 'peso_receipt')->count());
    }

    public function test_real_test9_preserves_dbw_quantity_and_barcode_hierarchy_without_booking_stock(): void
    {
        $directory = getenv('REAL_PESO_PDF_DIR');
        $path = $directory ? rtrim($directory, '/') . '/test9.pdf' : null;
        if (! $path || ! is_file($path)) {
            $this->markTestSkipped('Set REAL_PESO_PDF_DIR to the directory containing the untouched test9.pdf.');
        }

        $parsed = app(PesoPdfParser::class)->parse($path);
        $this->assertSame('RE-12/2026/724316', $parsed['re12_number']);
        $this->assertSame('2026-11-18', $parsed['peso_date']);
        $this->assertSame('E/HQ/TG/22/619(E49505)', $parsed['licence_number']);
        $this->assertCount(1, $parsed['items']);
        $group = $parsed['items'][0];
        $this->assertSame('SOLAR e-det', $group['product_name']);
        $this->assertSame('DBW', $group['size_code']);
        $this->assertSame('20.000', $group['declared_quantity']);
        $this->assertSame('Nos', $group['declared_unit']);
        $this->assertCount(1, collect($group['barcode_records'])->where('level', 'L1'));
        $this->assertCount(2, collect($group['barcode_records'])->where('level', 'L2'));
        $l1Barcode = collect($group['barcode_records'])->firstWhere('level', 'L1')['barcode'];
        $this->assertSame(
            [$l1Barcode, $l1Barcode],
            collect($group['barcode_records'])->where('level', 'L2')->pluck('parent_barcode')->all(),
        );
        $this->assertSame(0, Product::query()->count(), 'Parsing a PDF must not create products or book stock.');
    }

    private function user(Organization $organization, string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role . '-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);
    }
}
