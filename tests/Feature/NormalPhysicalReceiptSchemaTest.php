<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductSerial;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLicence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NormalPhysicalReceiptSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_schema_and_legacy_product_serial_fields_are_nullable_and_scoped(): void
    {
        $receiptColumns = collect(Schema::getColumns('normal_physical_receipts'))->keyBy('name');
        foreach ([
            'receipt_uuid', 'product_id', 'product_variant_id', 'original_quantity', 'unit', 'package_type',
            'warehouse_id', 'receiving_licence_id', 'receiving_licence_number',
            'received_by_user_id', 'received_at', 'supplier_id', 'supplier_reference', 'delivery_reference',
            'physical_receipt_status', 're12_verification_status', 'stock_adjustment_id',
        ] as $name) {
            $this->assertArrayHasKey($name, $receiptColumns, "Missing receipt column {$name}.");
        }
        $this->assertTrue($receiptColumns['product_variant_id']['nullable']);
        $this->assertTrue($receiptColumns['package_type']['nullable']);
        $this->assertTrue($receiptColumns['supplier_id']['nullable']);
        $this->assertTrue($receiptColumns['supplier_reference']['nullable']);
        $this->assertTrue($receiptColumns['delivery_reference']['nullable']);
        $this->assertFalse($receiptColumns['stock_adjustment_id']['nullable']);

        $serialColumns = collect(Schema::getColumns('product_serials'))->keyBy('name');
        $this->assertArrayHasKey('normal_physical_receipt_id', $serialColumns);
        $this->assertTrue($serialColumns['normal_physical_receipt_id']['nullable']);

        $receiptIndexes = collect(Schema::getIndexes('normal_physical_receipts'))->keyBy('name');
        $this->assertTrue($receiptIndexes['normal_receipts_org_uuid_unique']['unique']);
        $this->assertSame(['organization_id', 'receipt_uuid'], $receiptIndexes['normal_receipts_org_uuid_unique']['columns']);
        $serialIndexes = collect(Schema::getIndexes('product_serials'))->keyBy('name');
        $this->assertTrue($serialIndexes['product_serials_organization_id_serial_number_unique']['unique'] ?? false);

        $this->assertContains(['organization_id', 'product_id', 'normal_physical_receipt_id'], array_column(
            Schema::getForeignKeys('product_serials'), 'columns'
        ));
    }

    public function test_receipt_uuid_is_unique_per_organization_and_delivery_references_are_optional(): void
    {
        $first = $this->context(8);
        $second = $this->context(13);
        $uuid = (string) Str::uuid();

        $this->insertReceipt($first, $uuid);
        $this->insertReceipt($second, $uuid);

        $this->assertSame(2, DB::table('normal_physical_receipts')->where('receipt_uuid', $uuid)->count());
        $this->assertSame(8, $first['product']->fresh()->stock);
        $this->assertSame(13, $second['product']->fresh()->stock);

        try {
            $this->insertReceipt($first, $uuid);
            $this->fail('Receipt UUID must be unique inside one organization.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('normal_physical_receipts')->where('organization_id', $first['organization']->id)->count());
        }
    }

    public function test_receipt_rejects_cross_organization_product_warehouse_licence_and_actor(): void
    {
        $local = $this->context();
        $foreign = $this->context();
        $base = $this->receiptRow($local, (string) Str::uuid());
        $foreignSupplierId = DB::table('suppliers')->insertGetId([
            'organization_id' => $foreign['organization']->id,
            'name' => 'Foreign supplier',
            'currency' => 'USD',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['product_id' => $foreign['product']->id],
            ['warehouse_id' => $foreign['warehouse']->id],
            ['warehouse_id' => $foreign['warehouse']->id, 'receiving_licence_id' => $foreign['licence']->id,
                'receiving_licence_number' => $foreign['licence']->licence_number],
            ['received_by_user_id' => $foreign['actor']->id],
            ['supplier_id' => $foreignSupplierId],
        ] as $overrides) {
            try {
                DB::table('normal_physical_receipts')->insert([...$base, ...$overrides]);
                $this->fail('Cross-organization receipt references must be rejected by composite foreign keys.');
            } catch (QueryException) {
                $this->assertSame(0, DB::table('normal_physical_receipts')->where('receipt_uuid', $base['receipt_uuid'])->count());
            }
        }
    }

    public function test_variant_and_stock_adjustment_must_match_the_receipt_product_and_organization(): void
    {
        $context = $this->context();
        $other = $this->context();
        $variantId = $this->insertVariant($context);
        $adjustmentId = $this->insertAdjustment($context, $variantId);
        $receipt = array_replace($this->receiptRow($context, (string) Str::uuid()), [
            'product_variant_id' => $variantId,
            'stock_adjustment_id' => $adjustmentId,
        ]);
        DB::table('normal_physical_receipts')->insert($receipt);

        $wrongVariant = array_replace($this->receiptRow($context, (string) Str::uuid()), [
            'product_variant_id' => $this->insertVariant($other),
        ]);
        $wrongAdjustment = array_replace($this->receiptRow($context, (string) Str::uuid()), [
            'stock_adjustment_id' => $this->insertAdjustment($other),
        ]);

        foreach ([$wrongVariant, $wrongAdjustment] as $invalidRow) {
            try {
                DB::table('normal_physical_receipts')->insert($invalidRow);
                $this->fail('Variant and stock adjustment links must belong to the receipt product and organization.');
            } catch (QueryException) {
                $this->assertSame(1, DB::table('normal_physical_receipts')->where('organization_id', $context['organization']->id)->count());
            }
        }
    }

    public function test_product_serials_can_link_to_receipt_without_breaking_legacy_barcode_rows(): void
    {
        $context = $this->context(8);
        $now = now();

        // Legacy serial row shape remains valid because the new FK is nullable.
        DB::table('product_serials')->insert([
            'organization_id' => $context['organization']->id,
            'product_id' => $context['product']->id,
            'serial_number' => 'LEGACY-'.Str::uuid(),
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $receiptId = $this->insertReceipt($context, (string) Str::uuid());
        $barcode = 'DIRECT-'.Str::uuid();
        DB::table('product_serials')->insert([
            'organization_id' => $context['organization']->id,
            'product_id' => $context['product']->id,
            'normal_physical_receipt_id' => $receiptId,
            'serial_number' => $barcode,
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertDatabaseHas('product_serials', [
            'organization_id' => $context['organization']->id,
            'normal_physical_receipt_id' => $receiptId,
            'serial_number' => $barcode,
        ]);

        $otherProduct = Product::factory()->create(['organization_id' => $context['organization']->id]);
        try {
            DB::table('product_serials')->insert([
                'organization_id' => $context['organization']->id,
                'product_id' => $otherProduct->id,
                'normal_physical_receipt_id' => $receiptId,
                'serial_number' => 'WRONG-PRODUCT-'.Str::uuid(),
                'status' => 'available',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('A barcode identity must belong to the same internal product as its receipt.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('product_serials')->where('normal_physical_receipt_id', $receiptId)->count());
        }

        try {
            DB::table('product_serials')->insert([
                'organization_id' => $context['organization']->id,
                'product_id' => $context['product']->id,
                'normal_physical_receipt_id' => $receiptId,
                'serial_number' => $barcode,
                'status' => 'available',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('The existing organization-scoped barcode uniqueness must prevent duplicate serial booking.');
        } catch (QueryException) {
            $this->assertSame(1, ProductSerial::query()->where('serial_number', $barcode)->count());
        }

        $this->assertSame(8, $context['product']->fresh()->stock);
    }

    private function context(int $stock = 0): array
    {
        $organization = Organization::factory()->create();
        $product = Product::factory()->create(['organization_id' => $organization->id, 'stock' => $stock]);
        $warehouse = Warehouse::factory()->create(['organization_id' => $organization->id]);
        $licence = WarehouseLicence::create([
            'warehouse_id' => $warehouse->id,
            'licence_number' => 'LIC-'.Str::uuid(),
            'is_active' => true,
        ]);
        $actor = User::factory()->create(['organization_id' => $organization->id]);

        return compact('organization', 'product', 'warehouse', 'licence', 'actor');
    }

    private function receiptRow(array $context, string $uuid): array
    {
        $now = now();

        return [
            'organization_id' => $context['organization']->id,
            'receipt_uuid' => $uuid,
            'product_id' => $context['product']->id,
            'product_variant_id' => null,
            'original_quantity' => 5,
            'unit' => 'Nos',
            'package_type' => null,
            'warehouse_id' => $context['warehouse']->id,
            'receiving_licence_id' => $context['licence']->id,
            'receiving_licence_number' => $context['licence']->licence_number,
            'received_by_user_id' => $context['actor']->id,
            'received_at' => $now,
            'supplier_id' => null,
            'supplier_reference' => null,
            'delivery_reference' => null,
            'physical_receipt_status' => 'received',
            're12_verification_status' => 'pending',
            'stock_adjustment_id' => $this->insertAdjustment($context),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function insertReceipt(array $context, string $uuid): int
    {
        return (int) DB::table('normal_physical_receipts')->insertGetId($this->receiptRow($context, $uuid));
    }

    private function insertVariant(array $context): int
    {
        return (int) DB::table('product_variants')->insertGetId([
            'organization_id' => $context['organization']->id,
            'product_id' => $context['product']->id,
            'option_values' => json_encode(['Size' => 'Test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAdjustment(array $context, ?int $variantId = null): int
    {
        return (int) DB::table('stock_adjustments')->insertGetId([
            'organization_id' => $context['organization']->id,
            'product_id' => $context['product']->id,
            'product_variant_id' => $variantId,
            'user_id' => $context['actor']->id,
            'type' => 'direct_receipt_test',
            'quantity_before' => 0,
            'quantity_after' => 5,
            'adjustment_quantity' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
