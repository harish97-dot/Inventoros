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
use App\Services\Receiving\ExcelEdPhysicalStockReceivingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ExcelEdPhysicalStockReceivingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_box_receipt_creates_multiple_manually_confirmed_packs_and_receipt_movements(): void
    {
        $context = $this->context('EXCEL', 83);
        $firstMapping = $this->mapping($context, 'Maker A', 'DEZ');
        $secondMapping = $this->mapping($context, 'Maker B', 'DFD');
        $user = $context['actor'];

        $unit = $this->trustedReceive($context, [
            ...$this->receiptInput($context, 'BOX', 'OUTER-BOX-1'),
            'packs' => [
                ['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 9, 'quantity' => 50],
                ['manufacturer_name' => 'Maker B', 'manufacturer_code' => 'DFD', 'pack_identifier' => 'P-2', 'mtr' => 12, 'quantity' => 20],
            ],
        ]);

        $this->assertSame('BOX', $unit->stock_type);
        $this->assertSame('OUTER-BOX-1', $unit->main_barcode);
        $this->assertSame('received', $unit->physical_receipt_status);
        $this->assertSame('pending', $unit->re12_verification_status);
        $this->assertNotNull($unit->received_at);
        $this->assertSame($context['warehouse']->id, $unit->receiving_warehouse_id);
        $this->assertSame($context['licence']->id, $unit->receiving_licence_id);
        $this->assertSame(2, $unit->packs()->count());
        $this->assertDatabaseHas('excel_ed_stock_packs', [
            'physical_stock_unit_id' => $unit->id,
            'manufacturer_name' => 'Maker A',
            'manufacturer_code' => 'DEZ',
            'peso_product_mapping_id' => $firstMapping->id,
            'mtr' => 9,
            'original_quantity' => 50,
            'remaining_quantity' => 50,
            'quantity_source' => 'manual',
        ]);
        $this->assertDatabaseHas('excel_ed_stock_packs', [
            'physical_stock_unit_id' => $unit->id,
            'manufacturer_name' => 'Maker B',
            'manufacturer_code' => 'DFD',
            'peso_product_mapping_id' => $secondMapping->id,
            'pack_identifier' => 'P-2',
            'mtr' => 12,
            'original_quantity' => 20,
            'remaining_quantity' => 20,
            'quantity_source' => 'manual',
        ]);
        $this->assertSame(2, ExcelEdStockMovement::query()->where('movement_type', 'RECEIPT')->count());
        $this->assertDatabaseHas('excel_ed_stock_movements', [
            'physical_stock_pack_id' => $unit->packs()->where('manufacturer_code', 'DEZ')->value('id'),
            'quantity' => 50,
            'quantity_before' => 0,
            'quantity_after' => 50,
            'warehouse_id' => $context['warehouse']->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_loose_receipt_allows_null_barcode_and_code_less_pack_for_explicit_product(): void
    {
        $context = $this->context('ED');
        $unit = $this->trustedReceive($context, [
            ...$this->receiptInput($context, 'LOOSE', null),
            'packs' => [[
                'manufacturer_name' => 'SOLAR e-det',
                'manufacturer_code' => null,
                'pack_identifier' => null,
                'mtr' => 1,
                'quantity' => 20,
            ]],
        ]);

        $pack = $unit->packs()->sole();
        $this->assertNull($unit->main_barcode);
        $this->assertSame('received', $unit->physical_receipt_status);
        $this->assertSame('pending', $unit->re12_verification_status);
        $this->assertNotNull($unit->received_at);
        $this->assertSame($context['product']->id, $pack->product_id);
        $this->assertNull($pack->manufacturer_code);
        $this->assertNull($pack->productMapping);
        $this->assertSame(20, $pack->remaining_quantity);
        $this->assertSame('manual', $pack->quantity_source);
        $this->assertSame(1, ExcelEdStockMovement::query()->where('physical_stock_pack_id', $pack->id)->count());
    }

    public function test_loose_must_contain_exactly_one_pack_and_box_must_have_a_barcode(): void
    {
        $context = $this->context();
        $onePack = ['manufacturer_name' => 'Maker A', 'manufacturer_code' => null, 'mtr' => 9, 'quantity' => 5];

        try {
            $this->trustedReceive($context, [
                ...$this->receiptInput($context, 'LOOSE', null),
                'packs' => [$onePack, $onePack],
            ]);
            $this->fail('LOOSE must have exactly one pack.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('packs', $exception->errors());
        }

        try {
            $this->trustedReceive($context, [
                ...$this->receiptInput($context, 'BOX', null),
                'packs' => [$onePack],
            ]);
            $this->fail('BOX requires an outer barcode.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('main_barcode', $exception->errors());
        }

        $this->assertSame(0, ExcelEdStockUnit::query()->count());
        $this->assertSame(0, ExcelEdStockPack::query()->count());
    }

    public function test_wrong_organization_warehouse_and_licence_are_rejected(): void
    {
        $context = $this->context();
        $other = $this->context();
        $pack = ['manufacturer_name' => 'Maker A', 'manufacturer_code' => null, 'mtr' => 9, 'quantity' => 5];

        $sameOrgOtherWarehouse = Warehouse::factory()->create(['organization_id' => $context['organization']->id]);
        $sameOrgOtherLicence = WarehouseLicence::create([
            'warehouse_id' => $sameOrgOtherWarehouse->id,
            'licence_number' => 'LIC-'.uniqid('', true),
            'is_active' => true,
        ]);

        foreach ([
            [$other['organization']->id, $context['actor'], $this->receiptInput($context, 'LOOSE', null)],
            [$context['organization']->id, $context['actor'], [...$this->receiptInput($context, 'LOOSE', null), 'warehouse_id' => $other['warehouse']->id]],
            [$context['organization']->id, $context['actor'], [...$this->receiptInput($context, 'LOOSE', null), 'licence_id' => $sameOrgOtherLicence->id]],
        ] as [$trustedOrganizationId, $actor, $input]) {
            try {
                $this->service()->receive($trustedOrganizationId, $actor, [...$input, 'packs' => [$pack]]);
                $this->fail('Cross-organization or mismatched warehouse/licence input must be rejected.');
            } catch (ValidationException) {
                $this->assertSame(0, ExcelEdStockUnit::query()->count());
            }
        }
    }

    public function test_peso_source_must_match_organization_and_receiving_warehouse(): void
    {
        $context = $this->context();
        $other = $this->context();
        $foreignSource = $this->pesoItem($other);
        $pack = ['manufacturer_name' => 'Maker A', 'manufacturer_code' => null, 'mtr' => 9, 'quantity' => 5];

        $this->expectException(ValidationException::class);
        $this->trustedReceive($context, [
            ...$this->receiptInput($context, 'LOOSE', null),
            'source_peso_item_id' => $foreignSource->id,
            'packs' => [$pack],
        ]);
    }

    public function test_exact_mapping_must_resolve_to_the_receiving_internal_product(): void
    {
        $context = $this->context();
        $otherProduct = Product::factory()->create(['organization_id' => $context['organization']->id]);
        $this->mapping($context, 'Maker A', 'DEZ', $otherProduct);

        $this->expectException(ValidationException::class);
        $this->trustedReceive($context, [
            ...$this->receiptInput($context, 'BOX', 'BAD-MAP-BOX'),
            'packs' => [['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 9, 'quantity' => 5]],
        ]);
    }

    public function test_exact_mapping_from_another_organization_is_not_accepted(): void
    {
        $context = $this->context();
        $other = $this->context();
        $this->mapping($other, 'Maker A', 'DEZ');

        $this->expectException(ValidationException::class);
        $this->trustedReceive($context, [
            ...$this->receiptInput($context, 'BOX', 'CROSS-ORG-MAPPING'),
            'packs' => [['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 9, 'quantity' => 5]],
        ]);
    }

    public function test_duplicate_box_barcode_is_idempotent_for_same_receipt_and_rejects_conflicts(): void
    {
        $context = $this->context();
        $this->mapping($context, 'Maker A', 'DEZ');
        $input = [
            ...$this->receiptInput($context, 'BOX', 'RETRY-BOX'),
            'packs' => [['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 9, 'quantity' => 50]],
        ];
        $first = $this->trustedReceive($context, $input);
        $retry = $this->trustedReceive($context, $input);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, ExcelEdStockUnit::query()->count());
        $this->assertSame(1, ExcelEdStockPack::query()->count());
        $this->assertSame(1, ExcelEdStockMovement::query()->count());

        $input['packs'][0]['quantity'] = 51;
        try {
            $this->trustedReceive($context, $input);
            $this->fail('Reusing a barcode with conflicting receipt contents must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt', $exception->errors());
        }
    }

    public function test_same_uuid_with_identical_normalized_payload_returns_existing_box_and_loose_receipts(): void
    {
        $context = $this->context();
        $this->mapping($context, 'Maker A', 'DEZ');
        $boxInput = [
            ...$this->receiptInput($context, 'BOX', 'UUID-BOX'),
            'packs' => [['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => '9', 'quantity' => '12']],
        ];
        $service = $this->service();
        $firstBox = $this->trustedReceive($context, $boxInput);
        $normalizedRetry = $boxInput;
        $normalizedRetry['packs'][0]['mtr'] = 9;
        $normalizedRetry['packs'][0]['quantity'] = 12;
        $retriedBox = $this->trustedReceive($context, $normalizedRetry);

        $this->assertSame($firstBox->id, $retriedBox->id);
        $this->assertSame($boxInput['receipt_uuid'], $firstBox->receipt_uuid);

        $looseInput = [
            ...$this->receiptInput($context, 'LOOSE', null),
            'packs' => [['manufacturer_name' => 'Manual maker', 'mtr' => 9, 'quantity' => 3]],
        ];
        $firstLoose = $this->trustedReceive($context, $looseInput);
        $retriedLoose = $this->trustedReceive($context, $looseInput);

        $this->assertSame($firstLoose->id, $retriedLoose->id);
        $this->assertSame($looseInput['receipt_uuid'], $firstLoose->receipt_uuid);
        $this->assertSame(2, ExcelEdStockUnit::query()->count());
        $this->assertSame(2, ExcelEdStockPack::query()->count());
        $this->assertSame(2, ExcelEdStockMovement::query()->count());
    }

    public function test_receipt_uuid_is_required_and_validated(): void
    {
        $context = $this->context();
        $input = [
            ...$this->receiptInput($context, 'LOOSE', null),
            'packs' => [['manufacturer_name' => 'Manual maker', 'mtr' => 9, 'quantity' => 1]],
        ];
        unset($input['receipt_uuid']);

        try {
            $this->trustedReceive($context, $input);
            $this->fail('New receipts must include a UUID.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt_uuid', $exception->errors());
        }

        $input['receipt_uuid'] = 'not-a-uuid';
        try {
            $this->trustedReceive($context, $input);
            $this->fail('Malformed receipt UUIDs must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt_uuid', $exception->errors());
        }

        $this->assertSame(0, ExcelEdStockUnit::query()->count());
    }

    public function test_same_uuid_with_different_payload_is_rejected(): void
    {
        $context = $this->context();
        $this->mapping($context, 'Maker A', 'DEZ');
        $input = [
            ...$this->receiptInput($context, 'BOX', 'UUID-CONFLICT'),
            'packs' => [['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 9, 'quantity' => 12]],
        ];
        $this->trustedReceive($context, $input);
        $input['packs'][0]['quantity'] = 13;

        try {
            $this->trustedReceive($context, $input);
            $this->fail('A receipt UUID cannot be replayed with a different physical quantity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt', $exception->errors());
        }

        $this->assertSame(1, ExcelEdStockUnit::query()->count());
        $this->assertSame(1, ExcelEdStockPack::query()->count());
        $this->assertSame(1, ExcelEdStockMovement::query()->count());
    }

    public function test_receipt_uuid_unique_index_is_organization_scoped(): void
    {
        $first = $this->context();
        $second = $this->context();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $firstInput = [
            ...$this->receiptInput($first, 'LOOSE', null),
            'receipt_uuid' => $uuid,
            'packs' => [['manufacturer_name' => 'Maker A', 'mtr' => 9, 'quantity' => 1]],
        ];
        $secondInput = [
            ...$this->receiptInput($second, 'LOOSE', null),
            'receipt_uuid' => $uuid,
            'packs' => [['manufacturer_name' => 'Maker B', 'mtr' => 9, 'quantity' => 1]],
        ];

        $one = $this->trustedReceive($first, $firstInput);
        $two = $this->trustedReceive($second, $secondInput);

        $this->assertNotSame($one->id, $two->id);
        $this->assertSame($uuid, $one->receipt_uuid);
        $this->assertSame($uuid, $two->receipt_uuid);
        $this->assertSame(2, ExcelEdStockUnit::query()->where('receipt_uuid', $uuid)->count());
    }

    public function test_duplicate_barcode_with_new_uuid_cannot_create_another_box(): void
    {
        $context = $this->context();
        $this->mapping($context, 'Maker A', 'DEZ');
        $input = [
            ...$this->receiptInput($context, 'BOX', 'UNIQUE-BARCODE'),
            'packs' => [['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 9, 'quantity' => 12]],
        ];
        $this->trustedReceive($context, $input);
        $input['receipt_uuid'] = (string) \Illuminate\Support\Str::uuid();

        try {
            $this->trustedReceive($context, $input);
            $this->fail('A different UUID cannot bypass BOX barcode uniqueness.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt', $exception->errors());
        }

        $this->assertSame(1, ExcelEdStockUnit::query()->count());
        $this->assertSame(1, ExcelEdStockPack::query()->count());
        $this->assertSame(1, ExcelEdStockMovement::query()->count());
    }

    public function test_peso_source_with_new_uuid_cannot_create_another_receipt(): void
    {
        $context = $this->context();
        $this->mapping($context, 'Maker A', 'DEZ');
        $source = $this->pesoItem($context);
        $input = [
            ...$this->receiptInput($context, 'LOOSE', null),
            'source_peso_item_id' => $source->id,
            'packs' => [['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 9, 'quantity' => 10]],
        ];
        $this->trustedReceive($context, $input);
        $input['receipt_uuid'] = (string) \Illuminate\Support\Str::uuid();

        try {
            $this->trustedReceive($context, $input);
            $this->fail('A different UUID cannot bypass PESO source uniqueness.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt', $exception->errors());
        }

        $this->assertSame(1, ExcelEdStockUnit::query()->count());
        $this->assertSame(1, ExcelEdStockPack::query()->count());
        $this->assertSame(1, ExcelEdStockMovement::query()->count());
    }

    public function test_untrusted_actor_organization_is_rejected_before_receiving(): void
    {
        $context = $this->context();
        $other = $this->context();

        $this->expectException(ValidationException::class);
        $this->service()->receive(
            (int) $context['organization']->id,
            $other['actor'],
            [
                ...$this->receiptInput($context, 'LOOSE', null),
                'packs' => [['manufacturer_name' => 'Manual maker', 'mtr' => 9, 'quantity' => 1]],
            ],
        );
    }

    public function test_competing_unique_key_insert_cannot_duplicate_units_packs_or_receipt_movements(): void
    {
        $context = $this->context();
        $firstInput = [
            ...$this->receiptInput($context, 'LOOSE', null),
            'packs' => [['manufacturer_name' => 'Manual maker', 'mtr' => 9, 'quantity' => 4]],
        ];
        $unit = $this->trustedReceive($context, $firstInput);

        // This is the database-level guard a competing transaction hits if
        // it passes the service's preflight read before the first commit.
        try {
            ExcelEdStockUnit::create([
                'organization_id' => $context['organization']->id,
                'receipt_uuid' => $unit->receipt_uuid,
                'excel_ed_product_profile_id' => $context['profile']->id,
                'stock_type' => 'LOOSE',
                'main_barcode' => null,
                'warehouse_id' => $context['warehouse']->id,
                'receiving_warehouse_id' => $context['warehouse']->id,
                'receiving_licence_id' => $context['licence']->id,
                'receiving_licence_number' => $context['licence']->licence_number,
                'received_at' => now(),
                'status' => 'active',
            ]);
            $this->fail('The organization-scoped UUID unique index must reject a competing insert.');
        } catch (QueryException) {
            $this->assertSame(1, ExcelEdStockUnit::query()->count());
            $this->assertSame(1, ExcelEdStockPack::query()->count());
            $this->assertSame(1, ExcelEdStockMovement::query()->count());
        }
    }

    public function test_peso_source_is_an_idempotency_key_and_is_not_mutated(): void
    {
        $context = $this->context();
        $this->mapping($context, 'Maker A', 'DEZ');
        $source = $this->pesoItem($context);
        $sourceBefore = $source->fresh()->getAttributes();
        $input = [
            ...$this->receiptInput($context, 'LOOSE', null),
            'source_peso_item_id' => $source->id,
            'packs' => [['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 9, 'quantity' => 10]],
        ];

        $first = $this->trustedReceive($context, $input);
        $retry = $this->trustedReceive($context, $input);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, ExcelEdStockUnit::query()->count());
        $this->assertSame(1, ExcelEdStockMovement::query()->count());
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
    }

    public function test_non_integer_or_non_positive_quantities_are_rejected_without_rows(): void
    {
        $context = $this->context();
        $mapping = $this->mapping($context, 'Maker A', 'DEZ');
        foreach ([['mtr' => 0, 'quantity' => 2], ['mtr' => 9, 'quantity' => 0], ['mtr' => 1.5, 'quantity' => 2], ['mtr' => 9, 'quantity' => '2.5']] as $invalid) {
            try {
                $this->trustedReceive($context, [
                    ...$this->receiptInput($context, 'BOX', 'INVALID-'.uniqid()),
                    'packs' => [[
                        'manufacturer_name' => 'Maker A',
                        'manufacturer_code' => 'DEZ',
                        'peso_product_mapping_id' => $mapping->id,
                        ...$invalid,
                    ]],
                ]);
                $this->fail('Invalid operational quantities must fail validation.');
            } catch (ValidationException) {
                $this->assertSame(0, ExcelEdStockUnit::query()->count());
            }
        }
    }

    public function test_receipt_transaction_rolls_back_unit_pack_and_movement_on_database_failure(): void
    {
        $context = $this->context();
        $mapping = $this->mapping($context, 'Maker A', 'DEZ');
        // Keep the actor object in memory but remove its database row. The
        // movement's real user FK then fails at insert time, exercising the
        // receipt transaction rollback without creating/dropping test DDL.
        $context['actor']->delete();

        try {
            $this->trustedReceive($context, [
                ...$this->receiptInput($context, 'BOX', 'ROLLBACK-BOX'),
                'packs' => [['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'peso_product_mapping_id' => $mapping->id, 'mtr' => 9, 'quantity' => 7]],
            ]);
            $this->fail('The movement insert should violate the actor foreign key.');
        } catch (QueryException) {
            $this->assertSame(0, ExcelEdStockUnit::query()->count());
            $this->assertSame(0, ExcelEdStockPack::query()->count());
            $this->assertSame(0, ExcelEdStockMovement::query()->count());
        }

    }

    public function test_available_stock_is_derived_from_remaining_packs_scoped_by_product_mtr_and_warehouse(): void
    {
        $context = $this->context();
        $otherWarehouse = Warehouse::factory()->create(['organization_id' => $context['organization']->id]);
        $this->mapping($context, 'Maker A', 'DEZ');
        $unit = $this->trustedReceive($context, [
            ...$this->receiptInput($context, 'BOX', 'AVAILABLE-BOX'),
            'packs' => [
                ['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 9, 'quantity' => 30],
                ['manufacturer_name' => 'Maker A', 'manufacturer_code' => 'DEZ', 'mtr' => 12, 'quantity' => 8],
            ],
        ]);
        $unit->packs()->where('mtr', 9)->firstOrFail()->recordBalanceMovement('ADJUSTMENT', 5, 25);

        $service = $this->service();
        $this->assertSame(25, $service->availableStock($context['organization']->id, $context['product']->id, 9, $context['warehouse']->id));
        $this->assertSame(8, $service->availableStock($context['organization']->id, $context['product']->id, 12, $context['warehouse']->id));
        $this->assertSame(0, $service->availableStock($context['organization']->id, $context['product']->id, 9, $otherWarehouse->id));
    }

    public function test_receiving_does_not_change_normal_product_stock_or_peso_source(): void
    {
        $context = $this->context('ED', 143);
        $this->mapping($context, 'SOLAR e-det', 'DBW');
        $source = $this->pesoItem($context);
        $sourceBefore = $source->fresh()->getAttributes();

        $this->trustedReceive($context, [
            ...$this->receiptInput($context, 'BOX', 'SOLAR-OUTER'),
            'source_peso_item_id' => $source->id,
            'packs' => [['manufacturer_name' => 'SOLAR e-det', 'manufacturer_code' => 'DBW', 'mtr' => 1, 'quantity' => 20]],
        ]);

        $this->assertSame(143, $context['product']->fresh()->stock);
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
    }

    private function context(string $type = 'EXCEL', int $stock = 0): array
    {
        $organization = Organization::factory()->create();
        $product = Product::factory()->create(['organization_id' => $organization->id, 'stock' => $stock]);
        $profile = ExcelEdProductProfile::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'product_type' => $type,
        ]);
        $warehouse = Warehouse::factory()->create(['organization_id' => $organization->id]);
        $licence = WarehouseLicence::create([
            'warehouse_id' => $warehouse->id,
            'licence_number' => 'LIC-'.uniqid('', true),
            'is_active' => true,
        ]);
        $actor = User::factory()->create(['organization_id' => $organization->id]);

        return compact('organization', 'product', 'profile', 'warehouse', 'licence', 'actor');
    }

    private function receiptInput(array $context, string $stockType, ?string $barcode): array
    {
        return [
            'receipt_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'product_profile_id' => $context['profile']->id,
            'stock_type' => $stockType,
            'warehouse_id' => $context['warehouse']->id,
            'licence_id' => $context['licence']->id,
            'main_barcode' => $barcode,
        ];
    }

    private function mapping(array $context, string $manufacturer, string $code, ?Product $product = null): PesoProductMapping
    {
        return PesoProductMapping::create([
            'organization_id' => $context['organization']->id,
            'manufacturer_name' => $manufacturer,
            'manufacturer_name_key' => PesoProductMapping::exactNameKey($manufacturer),
            're12_code' => $code,
            'internal_product_name' => $context['profile']->product_type,
            'product_id' => ($product ?? $context['product'])->id,
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
            'product_name' => 'Manufacturer name is not used to infer quantity',
            'size_code' => 'DBW',
            'barcode' => 'PESO-'.uniqid(),
            'status' => 'expected',
            'barcode_level' => 'L1',
            'original_quantity' => 1,
            'remaining_quantity' => 1,
        ]);
    }

    private function service(): ExcelEdPhysicalStockReceivingService
    {
        return app(ExcelEdPhysicalStockReceivingService::class);
    }

    private function trustedReceive(array $context, array $input, ?User $actor = null): ExcelEdStockUnit
    {
        unset($input['organization_id'], $input['user_id']);

        return $this->service()->receive(
            (int) $context['organization']->id,
            $actor ?? $context['actor'],
            $input,
        );
    }
}
