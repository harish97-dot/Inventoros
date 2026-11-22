<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\ExcelEdProductProfile;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductSerial;
use App\Models\PesoItem;
use App\Models\PesoShipment;
use App\Models\Warehouse;
use App\Models\WarehouseLicence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PhysicalReceivingReconciliationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_ed_receipt_statuses_are_nullable_and_separate_from_stock_lifecycle(): void
    {
        $unitColumns = collect(Schema::getColumns('excel_ed_stock_units'))->keyBy('name');

        $this->assertArrayHasKey('status', $unitColumns);
        $this->assertArrayHasKey('physical_receipt_status', $unitColumns);
        $this->assertArrayHasKey('re12_verification_status', $unitColumns);
        $this->assertTrue($unitColumns['physical_receipt_status']['nullable']);
        $this->assertTrue($unitColumns['re12_verification_status']['nullable']);
    }

    public function test_normal_receipt_identity_fields_are_nullable_and_have_scoped_indexes(): void
    {
        $columns = collect(Schema::getColumns('product_serials'))->keyBy('name');
        foreach ([
            'physically_received_at',
            'received_by_user_id',
            'receiving_warehouse_id',
            'receiving_licence_id',
            'receiving_licence_number',
            'physical_receipt_status',
            're12_verification_status',
            'source_peso_item_id',
        ] as $name) {
            $this->assertArrayHasKey($name, $columns);
            $this->assertTrue($columns[$name]['nullable'], "{$name} must be nullable for legacy rows.");
        }

        $indexes = collect(Schema::getIndexes('product_serials'))->keyBy('name');
        $this->assertArrayHasKey('product_serials_org_id_ref_unique', $indexes);
        $this->assertArrayHasKey('product_serials_org_peso_item_unique', $indexes);
        $this->assertTrue($indexes['product_serials_org_peso_item_unique']['unique']);

        $foreignKeys = Schema::getForeignKeys('product_serials');
        $this->assertContains([
            'organization_id', 'receiving_warehouse_id',
        ], array_column($foreignKeys, 'columns'));
        $this->assertContains([
            'receiving_warehouse_id', 'receiving_licence_id', 'receiving_licence_number',
        ], array_column($foreignKeys, 'columns'));
        $this->assertContains([
            'organization_id', 'source_peso_item_id',
        ], array_column($foreignKeys, 'columns'));
        $receivingActorForeignKey = collect($foreignKeys)->firstWhere('columns', ['received_by_user_id']);
        $this->assertNotNull($receivingActorForeignKey);
        $this->assertSame('restrict', strtolower($receivingActorForeignKey['on_delete']));
    }

    public function test_pack_barcode_is_distinct_from_pack_identifier_and_organization_unique(): void
    {
        $columns = collect(Schema::getColumns('excel_ed_stock_packs'))->keyBy('name');
        $this->assertArrayHasKey('pack_identifier', $columns);
        $this->assertArrayHasKey('manufacturer_barcode', $columns);
        $this->assertTrue($columns['manufacturer_barcode']['nullable']);

        $indexes = collect(Schema::getIndexes('excel_ed_stock_packs'))->keyBy('name');
        $this->assertArrayHasKey('excel_ed_packs_org_manufacturer_barcode_unique', $indexes);
        $this->assertTrue($indexes['excel_ed_packs_org_manufacturer_barcode_unique']['unique']);
        $this->assertSame(
            ['organization_id', 'manufacturer_barcode'],
            $indexes['excel_ed_packs_org_manufacturer_barcode_unique']['columns']
        );
    }

    public function test_nullable_unique_receipt_and_barcode_references_allow_multiple_nulls(): void
    {
        $organization = Organization::factory()->create();
        $product = Product::factory()->create(['organization_id' => $organization->id]);
        $profile = ExcelEdProductProfile::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'product_type' => 'EXCEL',
        ]);
        $warehouse = Warehouse::factory()->create(['organization_id' => $organization->id]);
        $licence = WarehouseLicence::create([
            'warehouse_id' => $warehouse->id,
            'licence_number' => 'LIC-'.uniqid('', true),
            'is_active' => true,
        ]);
        $now = now();

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertTrue(
                DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'product_serials_snapshot_insert')->exists(),
                'The migration-defined ProductSerial snapshot guard must exist in each isolated test database.'
            );
        }

        $unitIds = [];
        foreach ([1, 2] as $index) {
            DB::table('excel_ed_stock_units')->insert([
                'organization_id' => $organization->id,
                'excel_ed_product_profile_id' => $profile->id,
                'stock_type' => 'LOOSE',
                'main_barcode' => null,
                'receipt_uuid' => null,
                'warehouse_id' => $warehouse->id,
                'receiving_warehouse_id' => $warehouse->id,
                'receiving_licence_id' => $licence->id,
                'receiving_licence_number' => $licence->licence_number,
                'source_peso_item_id' => null,
                'received_at' => $now,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $unitIds[] = (int) DB::getPdo()->lastInsertId();

            DB::table('product_serials')->insert([
                'organization_id' => $organization->id,
                'product_id' => $product->id,
                'warehouse_id' => null,
                'serial_number' => 'TEST-NULL-'.$index,
                'status' => 'available',
                'source_peso_item_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('excel_ed_stock_packs')->insert([
                'organization_id' => $organization->id,
                'physical_stock_unit_id' => $unitIds[array_key_last($unitIds)],
                'excel_ed_product_profile_id' => $profile->id,
                'product_id' => $product->id,
                'manufacturer_name' => 'Test manufacturer',
                'manufacturer_code' => null,
                'pack_identifier' => null,
                'manufacturer_barcode' => null,
                'source_peso_item_id' => null,
                'mtr' => 9,
                'original_quantity' => 1,
                'remaining_quantity' => 1,
                'quantity_source' => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('product_serials')->insert([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'serial_number' => 'FULL-SNAPSHOT',
            'status' => 'available',
            'physically_received_at' => $now,
            'receiving_warehouse_id' => $warehouse->id,
            'receiving_licence_id' => $licence->id,
            'receiving_licence_number' => $licence->licence_number,
            'physical_receipt_status' => 'received',
            're12_verification_status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame(2, DB::table('excel_ed_stock_units')->whereNull('receipt_uuid')->count());
        $this->assertSame(2, DB::table('excel_ed_stock_units')->whereNull('main_barcode')->count());
        $this->assertSame(2, DB::table('excel_ed_stock_units')->whereNull('source_peso_item_id')->count());
        $this->assertSame(3, DB::table('product_serials')->whereNull('source_peso_item_id')->count());
        $this->assertSame(2, DB::table('excel_ed_stock_packs')->whereNull('manufacturer_barcode')->count());

        try {
            DB::table('product_serials')->insert([
                'organization_id' => $organization->id,
                'product_id' => $product->id,
                'serial_number' => 'PARTIAL-SNAPSHOT',
                'status' => 'available',
                'receiving_licence_number' => 'LIC-WITHOUT-WAREHOUSE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('A partial warehouse/licence snapshot must be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('snapshot must be complete or empty', $exception->getMessage());
        }

        try {
            DB::table('excel_ed_stock_units')->where('id', $unitIds[0])->update([
                're12_verification_status' => 'unknown',
            ]);
            $this->fail('Only the four defined RE-12 verification states may be stored.');
        } catch (QueryException) {
            $this->assertDatabaseHas('excel_ed_stock_units', [
                'id' => $unitIds[0],
                're12_verification_status' => null,
            ]);
        }
    }

    public function test_reconciliation_events_require_one_scoped_physical_identity_and_preserve_history_references(): void
    {
        $columns = collect(Schema::getColumns('peso_reconciliation_events'))->keyBy('name');
        foreach ([
            'organization_id',
            'peso_item_id',
            'product_serial_id',
            'excel_ed_stock_unit_id',
            'excel_ed_stock_pack_id',
            'outcome',
            'discrepancy_details',
            'actor_user_id',
            'occurred_at',
        ] as $name) {
            $this->assertArrayHasKey($name, $columns);
        }

        foreach (Schema::getForeignKeys('peso_reconciliation_events') as $foreignKey) {
            $foreignName = (string) ($foreignKey['name'] ?? 'foreign key');
            $this->assertSame('restrict', strtolower($foreignKey['on_delete']), $foreignName);
        }

        $indexes = collect(Schema::getIndexes('peso_reconciliation_events'))->keyBy('name');
        $this->assertArrayHasKey('peso_recon_events_source_time_index', $indexes);
        $this->assertSame(
            ['organization_id', 'peso_item_id', 'occurred_at'],
            $indexes['peso_recon_events_source_time_index']['columns']
        );
    }

    public function test_reconciliation_event_guards_reject_ambiguous_identity_and_are_append_only(): void
    {
        $triggerNames = [
                'peso_recon_events_validate_insert',
                'peso_recon_events_validate_update',
                'peso_recon_events_append_only_update',
                'peso_recon_events_append_only_delete',
            ];
        $triggerCount = DB::connection()->getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereIn('name', $triggerNames)->count()
            : DB::table('information_schema.triggers')
                ->where('trigger_schema', DB::connection()->getDatabaseName())
                ->whereIn('trigger_name', $triggerNames)
                ->count();
        $this->assertSame(4, $triggerCount);

        try {
            DB::table('peso_reconciliation_events')->insert([
                'organization_id' => 1,
                'peso_item_id' => 1,
                'outcome' => 'matched',
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('An event without exactly one physical receipt identity must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('one receipt identity', $exception->getMessage());
        }

        try {
            DB::table('peso_reconciliation_events')->insert([
                'organization_id' => 1,
                'peso_item_id' => 1,
                'product_serial_id' => 1,
                'excel_ed_stock_unit_id' => 1,
                'outcome' => 'discrepancy',
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('An event cannot reference more than one physical identity.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('cannot reference multiple identities', $exception->getMessage());
        }

        $organization = Organization::factory()->create();
        $product = Product::factory()->create(['organization_id' => $organization->id]);
        $warehouse = Warehouse::factory()->create(['organization_id' => $organization->id]);
        $shipment = PesoShipment::create([
            're12_number' => 'TEST-'.uniqid(),
            'warehouse_id' => $warehouse->id,
            'licence_number' => 'TEST-LICENCE',
        ]);
        $item = PesoItem::create([
            'peso_shipment_id' => $shipment->id,
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'product_name' => 'Manufacturer',
            'size_code' => 'DBW',
            'barcode' => 'TEST-'.uniqid(),
            'status' => 'expected',
            'barcode_level' => 'L1',
            'original_quantity' => 1,
            'remaining_quantity' => 1,
        ]);
        $serial = ProductSerial::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'serial_number' => 'SERIAL-'.uniqid(),
            'status' => 'available',
        ]);
        $eventId = DB::table('peso_reconciliation_events')->insertGetId([
            'organization_id' => $organization->id,
            'peso_item_id' => $item->id,
            'product_serial_id' => $serial->id,
            'outcome' => 'matched',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            fn () => DB::table('peso_reconciliation_events')->where('id', $eventId)->update(['outcome' => 'discrepancy']),
            fn () => DB::table('peso_reconciliation_events')->where('id', $eventId)->delete(),
        ] as $mutate) {
            try {
                $mutate();
                $this->fail('Reconciliation history must reject updates and deletes.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }

        $this->assertDatabaseHas('peso_reconciliation_events', [
            'id' => $eventId,
            'outcome' => 'matched',
            'product_serial_id' => $serial->id,
        ]);
    }
}
