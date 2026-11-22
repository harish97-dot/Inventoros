<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidOrderItemException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\PesoBarcodeAllocation;
use App\Models\PesoItem;
use App\Models\PesoShipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Loading\LoadingService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PartialBarcodeQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $user;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Partial Barcode Org',
            'email' => 'partial@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $this->user = User::create([
            'name' => 'Partial Test User',
            'email' => 'partial-user@example.test',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
        $this->warehouse = Warehouse::create([
            'organization_id' => $this->organization->id,
            'name' => 'Partial Warehouse',
            'code' => 'PARTIAL',
            'is_active' => true,
        ]);
    }

    private function product(string $name, string $unit, int $stock, int $minimum, int $increment): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'sku' => strtoupper(str_replace(' ', '-', $name)),
            'name' => $name,
            'price' => 10,
            'currency' => 'USD',
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
            'base_inventory_unit' => $unit,
            'partial_barcode_sales' => true,
            'minimum_sale_quantity' => $minimum,
            'sale_increment' => $increment,
        ]);
    }

    private function order(Product $product, int $quantity)
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Test Customer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 10,
            ]],
        ], $this->user);
    }

    private function pesoBarcode(Product $product, int $quantity, string $barcode): PesoItem
    {
        $shipment = PesoShipment::create([
            'warehouse_id' => $this->warehouse->id,
            'status' => 'received',
            'total_packages' => 1,
            'received_packages' => 1,
        ]);

        return PesoItem::create([
            'peso_shipment_id' => $shipment->id,
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'barcode' => $barcode,
            'status' => 'received',
            'received_at' => now(),
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'base_unit' => $product->base_inventory_unit,
        ]);
    }

    public function test_excel_can_allocate_repeatedly_and_rejects_an_allocation_above_barcode_balance(): void
    {
        $product = $this->product('Excel', 'Nos', 1000, 1, 1);
        $barcode = $this->pesoBarcode($product, 540, 'ABC123');

        foreach ([[250, 290], [100, 190]] as [$quantity, $expectedRemaining]) {
            $order = $this->order($product, $quantity);
            $item = $order->items()->firstOrFail();
            app(LoadingService::class)->scan(
                (int) $this->organization->id,
                (int) $this->warehouse->id,
                (int) $item->id,
                'ABC123',
            );
            $this->assertSame($expectedRemaining, (int) $barcode->fresh()->remaining_quantity);
        }

        $this->assertSame(190, (int) $barcode->fresh()->remaining_quantity);
        $this->assertSame(350, (int) PesoBarcodeAllocation::query()->where('event_type', 'allocated')->sum('quantity'));

        $oversizedOrder = $this->order($product, 200);
        $oversizedItem = $oversizedOrder->items()->firstOrFail();
        try {
            app(LoadingService::class)->scan(
                (int) $this->organization->id,
                (int) $this->warehouse->id,
                (int) $oversizedItem->id,
                'ABC123',
            );
            $this->fail('An allocation greater than the barcode balance should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('barcode', $exception->errors());
        }

        $this->assertSame('ABC123', $barcode->fresh()->barcode);
        $this->assertSame(190, (int) $barcode->fresh()->remaining_quantity);
    }

    public function test_order_reduction_releases_only_reduced_excel_quantity_to_same_barcode(): void
    {
        $product = $this->product('Excel Reduction', 'Nos', 1000, 1, 1);
        $order = $this->order($product, 250);
        $item = $order->items()->firstOrFail();
        $barcode = $this->pesoBarcode($product, 540, 'ABC-REDUCE');

        app(LoadingService::class)->scan((int) $this->organization->id, (int) $this->warehouse->id, (int) $item->id, 'ABC-REDUCE');
        app(OrderService::class)->updatePesoBarcodeOrderItems($order, [[
            'id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'unit_price' => 10,
            'release_barcode_ids' => [],
        ]], $this->user);

        $this->assertSame('ABC-REDUCE', $barcode->fresh()->barcode);
        $this->assertSame(440, (int) $barcode->fresh()->remaining_quantity);
        $this->assertSame(900, (int) $product->fresh()->stock);
        $this->assertSame(100, (int) PesoBarcodeAllocation::query()->where('event_type', 'allocated')->sum('quantity') - PesoBarcodeAllocation::query()->where('event_type', 'released')->sum('quantity'));
        $this->assertDatabaseHas('peso_barcode_releases', [
            'peso_item_id' => $barcode->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'barcode' => 'ABC-REDUCE',
            'old_quantity' => 250,
            'new_quantity' => 100,
            'released_by' => $this->user->id,
        ]);
    }

    public function test_d_cord_accepts_only_complete_roll_increments_and_allocates_meter_quantities(): void
    {
        $product = $this->product('D-Cord', 'm', 3000, 375, 375);

        try {
            $this->order($product, 374);
            $this->fail('A D-Cord order below one roll should be rejected.');
        } catch (InvalidOrderItemException) {
            $this->assertSame(3000, (int) $product->fresh()->stock);
        }

        $barcode = $this->pesoBarcode($product, 1500, 'XYZ123');
        $order = $this->order($product, 375);

        app(LoadingService::class)->scan(
            (int) $this->organization->id,
            (int) $this->warehouse->id,
            (int) $order->items()->firstOrFail()->id,
            'XYZ123',
        );

        $this->assertSame(1125, (int) $barcode->fresh()->remaining_quantity);

        $secondOrder = $this->order($product, 750);
        app(LoadingService::class)->scan(
            (int) $this->organization->id,
            (int) $this->warehouse->id,
            (int) $secondOrder->items()->firstOrFail()->id,
            'XYZ123',
        );

        $this->assertSame(375, (int) $barcode->fresh()->remaining_quantity);
        $this->assertDatabaseHas('peso_barcode_allocations', [
            'peso_item_id' => $barcode->id,
            'order_item_id' => $secondOrder->items()->firstOrFail()->id,
            'event_type' => 'allocated',
            'quantity' => 750,
        ]);

        foreach ([100, 374, 500] as $invalidQuantity) {
            try {
                $this->order($product, $invalidQuantity);
                $this->fail("D-Cord quantity {$invalidQuantity} must be rejected.");
            } catch (InvalidOrderItemException) {
                $this->assertSame(375, (int) $barcode->fresh()->remaining_quantity);
            }
        }

        $thirdOrder = $this->order($product, 375);
        app(LoadingService::class)->scan(
            (int) $this->organization->id,
            (int) $this->warehouse->id,
            (int) $thirdOrder->items()->firstOrFail()->id,
            'XYZ123',
        );
        $this->assertSame(0, (int) $barcode->fresh()->remaining_quantity);
    }

    public function test_connectors_can_be_sold_partially_from_the_same_barcode(): void
    {
        $product = $this->product('Connectors', 'Nos', 20, 1, 1);
        $barcode = $this->pesoBarcode($product, 10, 'CONN123');
        $order = $this->order($product, 4);

        app(LoadingService::class)->scan(
            (int) $this->organization->id,
            (int) $this->warehouse->id,
            (int) $order->items()->firstOrFail()->id,
            'CONN123',
        );

        $this->assertSame('CONN123', $barcode->fresh()->barcode);
        $this->assertSame(6, (int) $barcode->fresh()->remaining_quantity);
    }

    public function test_partial_barcode_quantity_must_be_positive_and_match_base_unit(): void
    {
        $product = $this->product('Validated Excel', 'Nos', 20, 1, 1);
        $shipment = PesoShipment::create([
            'warehouse_id' => $this->warehouse->id,
            'status' => 'received',
            'total_packages' => 1,
            'received_packages' => 1,
        ]);

        try {
            PesoItem::create([
                'peso_shipment_id' => $shipment->id,
                'organization_id' => $this->organization->id,
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $product->id,
                'barcode' => 'BAD-UNIT',
                'status' => 'received',
                'original_quantity' => 10,
                'remaining_quantity' => 10,
                'base_unit' => 'm',
            ]);
            $this->fail('A barcode quantity in a non-product unit should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('barcode_quantity', $exception->errors());
        }

        foreach ([[0, 0], [10, 11]] as [$original, $remaining]) {
            try {
                PesoItem::create([
                    'peso_shipment_id' => $shipment->id,
                    'organization_id' => $this->organization->id,
                    'warehouse_id' => $this->warehouse->id,
                    'product_id' => $product->id,
                    'barcode' => "BAD-QUANTITY-{$original}-{$remaining}",
                    'status' => 'received',
                    'original_quantity' => $original,
                    'remaining_quantity' => $remaining,
                    'base_unit' => 'Nos',
                ]);
                $this->fail('A nonpositive original quantity or remaining quantity above original should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('barcode_quantity', $exception->errors());
            }
        }
    }
}
