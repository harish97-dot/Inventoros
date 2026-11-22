<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\ExcelEdProductProfile;
use App\Models\Inventory\ExcelEdStockMovement;
use App\Models\Inventory\ExcelEdStockPack;
use App\Models\Inventory\ExcelEdStockUnit;
use App\Models\Inventory\Product;
use App\Models\PesoItem;
use App\Models\PesoProductMapping;
use App\Models\PesoShipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLicence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class ExcelEdPhysicalInventoryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_profile_is_unique_per_organization_and_resolves_product_type(): void
    {
        $context = $this->inventoryContext();
        $profile = $context['profile'];

        $this->assertSame($context['product']->id, $profile->product->id);
        $this->assertSame('EXCEL', $profile->product_type);
        $this->assertSame($context['organization']->id, $profile->organization->id);

        try {
            ExcelEdProductProfile::create([
                'organization_id' => $context['organization']->id,
                'product_id' => $context['product']->id,
                'product_type' => 'EXCEL',
            ]);
            $this->fail('A second profile for the same organization/product should violate the unique key.');
        } catch (QueryException) {
            $this->assertSame(1, ExcelEdProductProfile::query()->count());
        }

        $otherProduct = Product::factory()->create([
            'organization_id' => $context['organization']->id,
            'stock' => 0,
        ]);
        $otherProfile = ExcelEdProductProfile::create([
            'organization_id' => $context['organization']->id,
            'product_id' => $otherProduct->id,
            'product_type' => 'ED',
        ]);
        $this->assertSame('ED', $otherProfile->product_type);
    }

    public function test_stock_unit_resolves_product_and_type_through_its_profile(): void
    {
        $context = $this->inventoryContext('ED');
        $unit = $this->stockUnit($context, 'LOOSE', null);

        $this->assertSame($context['profile']->id, $unit->productProfile->id);
        $this->assertSame($context['product']->id, $unit->productProfile->product->id);
        $this->assertSame('ED', $unit->productProfile->product_type);
        $this->assertFalse(array_key_exists('product_id', $unit->getAttributes()));
        $this->assertFalse(array_key_exists('product_type', $unit->getAttributes()));
    }

    public function test_box_and_loose_units_are_distinct_and_loose_barcodes_can_be_null(): void
    {
        $context = $this->inventoryContext();
        $box = $this->stockUnit($context, 'BOX', 'BOX-100');
        $loose = $this->stockUnit($context, 'LOOSE', null);
        $anotherLoose = $this->stockUnit($context, 'LOOSE', null);

        $this->assertSame('BOX', $box->stock_type);
        $this->assertSame('BOX-100', $box->main_barcode);
        $this->assertSame('LOOSE', $loose->stock_type);
        $this->assertNull($loose->main_barcode);
        $this->assertNull($anotherLoose->main_barcode);
    }

    public function test_barcode_uniqueness_is_organization_scoped_and_peso_source_is_unique_per_unit(): void
    {
        $first = $this->inventoryContext();
        $second = $this->inventoryContext();
        $this->stockUnit($first, 'BOX', 'SAME-BOX-CODE');
        $this->stockUnit($second, 'BOX', 'SAME-BOX-CODE');

        $pesoItem = $this->pesoItem($first);
        $this->stockUnit($first, 'BOX', $pesoItem->barcode, $pesoItem);

        try {
            $this->stockUnit($first, 'BOX', 'SECOND-BARCODE-SAME-PESO', $pesoItem);
            $this->fail('A PESO item may identify only one receiving unit.');
        } catch (QueryException) {
            $this->assertSame(3, ExcelEdStockUnit::query()->count());
        }

        $this->expectException(QueryException::class);
        $this->stockUnit($first, 'BOX', 'SAME-BOX-CODE');
    }

    public function test_box_can_have_multiple_historical_packs_from_one_peso_item(): void
    {
        $context = $this->inventoryContext();
        $pesoItem = $this->pesoItem($context);
        $unit = $this->stockUnit($context, 'BOX', $pesoItem->barcode, $pesoItem);
        $mapping = $this->mapping($context, 'Kelvev-I', 'DEZ');
        $secondMapping = $this->mapping($context, 'Kelvev-I', 'DFD');

        $firstPack = $this->stockPack($context, $unit, $mapping, [
            'manufacturer_name' => 'Kelvev-I',
            'manufacturer_code' => 'DEZ',
            'mtr' => 9,
            'original_quantity' => 50,
            'remaining_quantity' => 50,
            'source_peso_item_id' => $pesoItem->id,
        ]);
        $secondPack = $this->stockPack($context, $unit, $secondMapping, [
            'manufacturer_name' => 'Kelvev-I',
            'manufacturer_code' => 'DFD',
            'mtr' => 12,
            'original_quantity' => 60,
            'remaining_quantity' => 60,
            'source_peso_item_id' => $pesoItem->id,
        ]);

        $this->assertCount(2, $unit->packs()->get());
        $this->assertSame($pesoItem->id, $firstPack->sourcePesoItem->id);
        $this->assertSame($pesoItem->id, $secondPack->sourcePesoItem->id);
        $this->assertSame('Kelvev-I', $firstPack->manufacturer_name);
        $this->assertSame('DEZ', $firstPack->manufacturer_code);
        $this->assertSame('DFD', $secondPack->manufacturer_code);
        $this->assertSame($mapping->id, $firstPack->productMapping->id);
    }

    public function test_unit_pack_and_movement_relationships_resolve_their_foreign_records(): void
    {
        $context = $this->inventoryContext();
        $pesoItem = $this->pesoItem($context);
        $unit = $this->stockUnit($context, 'BOX', $pesoItem->barcode, $pesoItem);
        $mapping = $this->mapping($context, 'Kelvev-I', 'DEZ');
        $pack = $this->stockPack($context, $unit, $mapping);
        $user = User::factory()->create();

        $movement = ExcelEdStockMovement::create([
            'organization_id' => $context['organization']->id,
            'physical_stock_pack_id' => $pack->id,
            'movement_type' => 'TRANSFER',
            'quantity' => 1,
            'quantity_before' => 50,
            'quantity_after' => 50,
            'warehouse_id' => $context['warehouse']->id,
            'from_warehouse_id' => $context['warehouse']->id,
            'to_warehouse_id' => $context['warehouse']->id,
            'user_id' => $user->id,
            'occurred_at' => now(),
        ]);

        $this->assertSame($context['warehouse']->id, $unit->currentWarehouse->id);
        $this->assertSame($context['warehouse']->id, $unit->receivingWarehouse->id);
        $this->assertSame($context['licence']->id, $unit->receivingLicence->id);
        $this->assertSame($pesoItem->id, $unit->sourcePesoItem->id);
        $this->assertSame($context['product']->id, $pack->product->id);
        $this->assertSame($user->id, $movement->user->id);
        $this->assertSame($context['warehouse']->id, $movement->warehouse->id);
        $this->assertSame($context['warehouse']->id, $movement->fromWarehouse->id);
        $this->assertSame($context['warehouse']->id, $movement->toWarehouse->id);
    }

    public function test_pack_mapping_must_match_its_organization_and_internal_product(): void
    {
        $context = $this->inventoryContext();
        $unit = $this->stockUnit($context, 'BOX', 'MAPPING-BOX');

        $differentProduct = Product::factory()->create([
            'organization_id' => $context['organization']->id,
            'stock' => 0,
        ]);
        $wrongProductMapping = $this->mapping($context, 'Other maker', 'XYZ', $differentProduct);

        try {
            $this->stockPack($context, $unit, $wrongProductMapping);
            $this->fail('The mapping product must agree with the stock unit profile product.');
        } catch (ValidationException) {
            $this->assertSame(0, ExcelEdStockPack::query()->count());
        }

        $otherOrganization = $this->inventoryContext();
        $crossOrganizationMapping = $this->mapping($otherOrganization, 'Other maker', 'ABC');

        try {
            $this->stockPack($context, $unit, $crossOrganizationMapping);
            $this->fail('A mapping from another organization must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(0, ExcelEdStockPack::query()->count());
        }

        $this->expectException(QueryException::class);
        DB::table('excel_ed_stock_packs')->insert([
            'organization_id' => $context['organization']->id,
            'physical_stock_unit_id' => $unit->id,
            'excel_ed_product_profile_id' => $context['profile']->id,
            'product_id' => $context['product']->id,
            'peso_product_mapping_id' => $crossOrganizationMapping->id,
            'manufacturer_name' => 'Other maker',
            'manufacturer_code' => 'ABC',
            'mtr' => 9,
            'original_quantity' => 50,
            'remaining_quantity' => 50,
            'quantity_source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_manufacturer_name_and_code_must_match_mapping_exactly(): void
    {
        $context = $this->inventoryContext();
        $unit = $this->stockUnit($context, 'LOOSE', null);
        $mapping = $this->mapping($context, 'IDEAL Boost', 'CDA');

        $this->expectException(ValidationException::class);
        $this->stockPack($context, $unit, $mapping, [
            'manufacturer_name' => 'IDEAL Boost ',
            'manufacturer_code' => 'CDA',
        ]);
    }

    public function test_pack_keeps_manufacturer_fields_and_quantities_as_whole_numbers(): void
    {
        $context = $this->inventoryContext();
        $unit = $this->stockUnit($context, 'LOOSE', null);
        $mapping = $this->mapping($context, 'Kelvev-I', 'DEZ');
        $pack = $this->stockPack($context, $unit, $mapping, [
            'manufacturer_name' => 'Kelvev-I',
            'manufacturer_code' => 'DEZ',
            'pack_identifier' => null,
            'mtr' => 9,
            'original_quantity' => 50,
            'remaining_quantity' => 50,
        ]);

        $this->assertSame('Kelvev-I', $pack->manufacturer_name);
        $this->assertSame('DEZ', $pack->manufacturer_code);
        $this->assertNull($pack->pack_identifier);
        $this->assertSame(9, $pack->mtr);
        $this->assertSame(50, $pack->original_quantity);
        $this->assertSame(50, $pack->remaining_quantity);

        foreach (['mtr', 'original_quantity', 'remaining_quantity'] as $column) {
            $this->assertSame('integer', Schema::getColumnType('excel_ed_stock_packs', $column));
        }
    }

    public function test_fractional_mtr_and_quantity_and_remaining_over_original_are_rejected(): void
    {
        $context = $this->inventoryContext();
        $unit = $this->stockUnit($context, 'LOOSE', null);
        $mapping = $this->mapping($context, 'Kelvev-I', 'DEZ');

        try {
            $this->stockPack($context, $unit, $mapping, ['mtr' => 9.5]);
            $this->fail('Mtr must be a whole number.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mtr', $exception->errors());
        }

        try {
            $this->stockPack($context, $unit, $mapping, ['original_quantity' => 2.5]);
            $this->fail('Pack quantities must be whole numbers.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('original_quantity', $exception->errors());
        }

        try {
            $this->stockPack($context, $unit, $mapping, [
                'original_quantity' => 10,
                'remaining_quantity' => 11,
            ]);
            $this->fail('Remaining quantity must not exceed original quantity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remaining_quantity', $exception->errors());
        }
    }

    public function test_persisted_balance_changes_require_a_movement_and_movement_rows_are_append_only(): void
    {
        $context = $this->inventoryContext();
        $unit = $this->stockUnit($context, 'LOOSE', null);
        $pack = $this->stockPack($context, $unit, null, [
            'manufacturer_name' => 'Kelvev-I',
            'manufacturer_code' => null,
            'original_quantity' => 50,
            'remaining_quantity' => 50,
        ]);

        $pack->remaining_quantity = 40;
        try {
            $pack->save();
            $this->fail('A persisted pack balance cannot be changed without a movement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remaining_quantity', $exception->errors());
        }

        $movement = $pack->recordBalanceMovement('ADJUSTMENT', 10, 40, reason: 'Test adjustment');
        $this->assertSame($pack->id, $movement->stockPack->id);
        $this->assertSame($context['organization']->id, $movement->organization_id);
        $this->assertSame(50, $movement->quantity_before);
        $this->assertSame(40, $movement->quantity_after);
        $this->assertSame(40, $pack->fresh()->remaining_quantity);

        try {
            $movement->update(['notes' => 'rewritten']);
            $this->fail('Movement history cannot be updated.');
        } catch (LogicException) {
            $this->assertDatabaseHas('excel_ed_stock_movements', [
                'id' => $movement->id,
                'notes' => null,
            ]);
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_movement_foreign_key_scopes_pack_to_its_organization(): void
    {
        $context = $this->inventoryContext();
        $unit = $this->stockUnit($context, 'LOOSE', null);
        $pack = $this->stockPack($context, $unit, null);
        $otherOrganization = $this->inventoryContext();

        $this->expectException(QueryException::class);
        ExcelEdStockMovement::create([
            'organization_id' => $otherOrganization['organization']->id,
            'physical_stock_pack_id' => $pack->id,
            'movement_type' => 'ADJUSTMENT',
            'quantity' => 1,
            'quantity_before' => 50,
            'quantity_after' => 49,
            'occurred_at' => now(),
        ]);
    }

    public function test_pack_movements_do_not_change_normal_product_stock_or_peso_records(): void
    {
        $context = $this->inventoryContext('EXCEL', 137);
        $pesoItem = $this->pesoItem($context)->fresh();
        $pesoBefore = $pesoItem->getAttributes();
        $unit = $this->stockUnit($context, 'BOX', $pesoItem->barcode, $pesoItem);
        $mapping = $this->mapping($context, 'Kelvev-I', 'DEZ');
        $pack = $this->stockPack($context, $unit, $mapping, [
            'source_peso_item_id' => $pesoItem->id,
        ]);

        $pack->recordBalanceMovement('ADJUSTMENT', 1, 49, reason: 'Isolated model test');

        $this->assertSame(137, $context['product']->fresh()->stock);
        $this->assertSame($pesoBefore, $pesoItem->fresh()->getAttributes());
    }

    private function inventoryContext(string $productType = 'EXCEL', int $productStock = 0): array
    {
        $organization = Organization::factory()->create();
        $product = Product::factory()->create([
            'organization_id' => $organization->id,
            'stock' => $productStock,
        ]);
        $profile = ExcelEdProductProfile::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'product_type' => $productType,
        ]);
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $licence = WarehouseLicence::create([
            'warehouse_id' => $warehouse->id,
            'licence_number' => 'LIC-'.$organization->id.'-'.uniqid(),
            'is_active' => true,
        ]);

        return compact('organization', 'product', 'profile', 'warehouse', 'licence');
    }

    private function stockUnit(
        array $context,
        string $stockType,
        ?string $barcode,
        ?PesoItem $source = null,
    ): ExcelEdStockUnit {
        return ExcelEdStockUnit::create([
            'organization_id' => $context['organization']->id,
            'excel_ed_product_profile_id' => $context['profile']->id,
            'stock_type' => $stockType,
            'main_barcode' => $barcode,
            'warehouse_id' => $context['warehouse']->id,
            'receiving_warehouse_id' => $context['warehouse']->id,
            'receiving_licence_id' => $context['licence']->id,
            'receiving_licence_number' => $context['licence']->licence_number,
            'source_peso_item_id' => $source?->id,
            'received_at' => now(),
            'status' => 'active',
        ]);
    }

    private function stockPack(
        array $context,
        ExcelEdStockUnit $unit,
        ?PesoProductMapping $mapping,
        array $overrides = [],
    ): ExcelEdStockPack {
        $attributes = array_merge([
            'organization_id' => $context['organization']->id,
            'physical_stock_unit_id' => $unit->id,
            'excel_ed_product_profile_id' => $context['profile']->id,
            'product_id' => $context['product']->id,
            'peso_product_mapping_id' => $mapping?->id,
            'source_peso_item_id' => null,
            'manufacturer_name' => $mapping?->manufacturer_name ?? 'Kelvev-I',
            'manufacturer_code' => $mapping?->re12_code,
            'pack_identifier' => null,
            'mtr' => 9,
            'original_quantity' => 50,
            'quantity_source' => 'manual',
        ], $overrides);

        // Test fixture for an initial balance; persisted balance updates use
        // recordBalanceMovement() and cannot be mass assigned.
        $remaining = $attributes['remaining_quantity'] ?? $attributes['original_quantity'];
        unset($attributes['remaining_quantity']);

        $pack = new ExcelEdStockPack($attributes);
        $pack->forceFill(['remaining_quantity' => $remaining]);
        $pack->save();

        return $pack;
    }

    private function mapping(
        array $context,
        string $manufacturer,
        string $code,
        ?Product $product = null,
    ): PesoProductMapping {
        $product ??= $context['product'];

        return PesoProductMapping::create([
            'organization_id' => $context['organization']->id,
            'manufacturer_name' => $manufacturer,
            'manufacturer_name_key' => PesoProductMapping::exactNameKey($manufacturer),
            're12_code' => $code,
            'internal_product_name' => $context['profile']->product_type,
            'product_id' => $product->id,
        ]);
    }

    private function pesoItem(array $context): PesoItem
    {
        $shipment = PesoShipment::create([
            're12_number' => 'TEST-'.uniqid(),
            'warehouse_id' => $context['warehouse']->id,
            'licence_number' => $context['licence']->licence_number,
        ]);

        return PesoItem::create([
            'peso_shipment_id' => $shipment->id,
            'organization_id' => $context['organization']->id,
            'warehouse_id' => $context['warehouse']->id,
            'product_name' => 'Kelvev-I',
            'size_code' => 'DEZ',
            'barcode' => 'PESO-'.uniqid(),
            'status' => 'expected',
            'barcode_level' => 'L1',
            'original_quantity' => 1,
            'remaining_quantity' => 1,
        ]);
    }
}
