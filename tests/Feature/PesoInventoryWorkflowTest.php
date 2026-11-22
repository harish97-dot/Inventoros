<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Order\Order;
use App\Models\PesoBarcodeAllocation;
use App\Models\PesoItem;
use App\Models\PesoShipment;
use App\Models\ReceivingScan;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\System\SystemSetting;
use App\Services\Loading\LoadingService;
use App\Services\OrderService;
use App\Services\Receiving\ReceivingService;
use App\Services\Receiving\PesoReceivingReconciliationService;
use App\Support\ApprovalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PesoInventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $receiver;
    private User $approver;
    private Warehouse $warehouse;
    private ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'PESO Workflow Org',
            'email' => 'peso-workflow@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $this->receiver = $this->user('peso-receiver@example.test');
        $this->approver = $this->user('peso-approver@example.test');
        $this->warehouse = Warehouse::create([
            'organization_id' => $this->organization->id,
            'name' => 'PESO Godown',
            'code' => 'PESO-GODOWN',
            'is_active' => true,
        ]);
        $this->location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'name' => 'PESO Godown Stock',
            'code' => 'PESO-BIN',
            'is_active' => true,
        ]);

        $settings = $this->organization->settings ?? [];
        $settings[ApprovalSettings::KEY] = array_merge(
            $settings[ApprovalSettings::KEY] ?? [],
            ['orders_enabled' => true],
        );
        $this->organization->forceFill(['settings' => $settings])->save();
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Workflow User',
            'email' => $email,
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
    }

    private function product(string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => strtoupper(str_replace(' ', '-', $name)),
            'name' => $name,
            'price' => 10,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
            'location_id' => $this->location->id,
        ], $overrides));
    }

    private function expectedBarcode(Product $product, string $barcode, int $quantity): PesoItem
    {
        $shipment = PesoShipment::create([
            'warehouse_id' => $this->warehouse->id,
            'status' => 'ready_for_receiving',
            'total_packages' => 1,
            'received_packages' => 0,
        ]);

        return PesoItem::create([
            'peso_shipment_id' => $shipment->id,
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'barcode' => $barcode,
            'status' => 'expected',
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'base_unit' => $product->partial_barcode_sales ? $product->base_inventory_unit : null,
        ]);
    }

    private function createOrder(Product $product, int $quantity): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Workflow Customer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 10,
            ]],
        ], $this->receiver);
    }

    public function test_excel_receiving_order_loading_and_approval_book_stock_once(): void
    {
        $product = $this->product('Excel', [
            'base_inventory_unit' => 'Nos',
            'partial_barcode_sales' => true,
            'minimum_sale_quantity' => 1,
            'sale_increment' => 1,
        ]);
        $barcode = $this->expectedBarcode($product, 'EXCEL-540', 540);

        $this->actingAs($this->receiver)
            ->postJson(route('receiving.scan'), [
                'warehouse_id' => $this->warehouse->id,
                'barcode' => 'EXCEL-540',
            ])
            ->assertOk()
            ->assertJsonPath('scan.product_name', 'Excel')
            ->assertJsonPath('scan.original_quantity', 540)
            ->assertJsonPath('scan.remaining_quantity', 540)
            ->assertJsonPath('scan.base_unit', 'Nos');

        $scan = ReceivingScan::query()->where('barcode', 'EXCEL-540')->firstOrFail();

        $barcode->refresh();
        $this->assertSame('VERIFIED', $scan->status);
        $this->assertSame('received', $barcode->status);
        $this->assertSame(540, (int) $barcode->original_quantity);
        $this->assertSame(540, (int) $barcode->remaining_quantity);
        $this->assertSame(540, (int) $product->fresh()->stock);
        $this->assertSame(540, (int) ProductLocationStock::query()->where('product_id', $product->id)->where('location_id', $this->location->id)->value('quantity'));

        $this->postJson(route('receiving.scan'), [
            'warehouse_id' => $this->warehouse->id,
            'barcode' => 'EXCEL-540',
        ])->assertOk()->assertJsonPath('scan.status', 'DUPLICATE');
        $this->assertSame(540, (int) $product->fresh()->stock, 'Duplicate receiving scans must not duplicate stock.');

        $order = $this->createOrder($product, 250);
        $orderItem = $order->items()->firstOrFail();
        $this->assertSame(OrderApprovalStatus::PENDING, $order->approval_status);
        $this->assertSame(290, (int) $product->fresh()->stock);
        $this->assertSame(290, (int) ProductLocationStock::query()->where('product_id', $product->id)->where('location_id', $this->location->id)->value('quantity'));
        $this->assertSame(540, (int) $barcode->fresh()->remaining_quantity);
        $this->assertDatabaseMissing('peso_barcode_allocations', ['order_item_id' => $orderItem->id]);

        app(LoadingService::class)->scan(
            (int) $this->organization->id,
            (int) $this->warehouse->id,
            (int) $orderItem->id,
            'EXCEL-540',
            (int) $this->receiver->id,
        );

        $this->assertSame('EXCEL-540', $barcode->fresh()->barcode);
        $this->assertSame(290, (int) $barcode->fresh()->remaining_quantity);
        $this->assertSame(250, (int) PesoBarcodeAllocation::query()->where('order_item_id', $orderItem->id)->where('event_type', 'allocated')->sum('quantity'));
        $this->assertSame(290, (int) $product->fresh()->stock);
        $this->assertSame(290, (int) ProductLocationStock::query()->where('product_id', $product->id)->where('location_id', $this->location->id)->value('quantity'));

        $approved = app(OrderService::class)->approve($order, $this->approver);
        $this->assertSame(OrderApprovalStatus::APPROVED, $approved->approval_status);
        $this->assertSame(OrderStatus::SHIPPED, $approved->status);
        $this->assertSame(290, (int) $product->fresh()->stock);
        $this->assertSame(290, (int) ProductLocationStock::query()->where('product_id', $product->id)->where('location_id', $this->location->id)->value('quantity'));
        $this->assertSame(290, (int) $barcode->fresh()->remaining_quantity);
    }

    public function test_non_partial_peso_product_keeps_one_barcode_per_order_unit_behavior(): void
    {
        $product = $this->product('TEST Kelvex-200', ['peso_barcode_managed' => true]);
        $firstBarcode = $this->expectedBarcode($product, 'KELVEX-1', 1);
        $secondBarcode = $this->expectedBarcode($product, 'KELVEX-2', 1);

        $receiving = app(ReceivingService::class);
        $receiving->scan((int) $this->organization->id, (int) $this->warehouse->id, 'KELVEX-1', (int) $this->receiver->id);
        $receiving->scan((int) $this->organization->id, (int) $this->warehouse->id, 'KELVEX-2', (int) $this->receiver->id);
        $this->assertSame(2, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) ProductLocationStock::query()->where('product_id', $product->id)->where('location_id', $this->location->id)->value('quantity'));

        $order = $this->createOrder($product, 2);
        $items = $order->items()->get();
        app(LoadingService::class)->scan((int) $this->organization->id, (int) $this->warehouse->id, (int) $items[0]->id, 'KELVEX-1');
        app(LoadingService::class)->scan((int) $this->organization->id, (int) $this->warehouse->id, (int) $items[0]->id, 'KELVEX-2');

        try {
            app(LoadingService::class)->scan((int) $this->organization->id, (int) $this->warehouse->id, (int) $items[0]->id, 'KELVEX-1');
            $this->fail('A package barcode already assigned to an order must not be assigned again.');
        } catch (ValidationException) {
            $this->assertSame(0, (int) $product->fresh()->stock);
        }

        $this->assertSame((int) $items[0]->id, (int) $firstBarcode->fresh()->order_item_id);
        $this->assertSame((int) $items[0]->id, (int) $secondBarcode->fresh()->order_item_id);
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) ProductLocationStock::query()->where('product_id', $product->id)->where('location_id', $this->location->id)->value('quantity'));

        $approved = app(OrderService::class)->approve($order, $this->approver);
        $this->assertSame(OrderApprovalStatus::APPROVED, $approved->approval_status);
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertDatabaseMissing('peso_barcode_allocations', ['peso_item_id' => $firstBarcode->id]);
    }

    public function test_receipt_scanned_before_manifest_is_stocked_when_reconciled_and_mapped(): void
    {
        $product = $this->product('Excel Pre-Manifest', [
            'base_inventory_unit' => 'Nos',
            'partial_barcode_sales' => true,
            'minimum_sale_quantity' => 1,
            'sale_increment' => 1,
        ]);

        $this->actingAs($this->receiver)
            ->postJson(route('receiving.scan'), [
                'warehouse_id' => $this->warehouse->id,
                'barcode' => 'PRE-MANIFEST-540',
            ])
            ->assertOk()
            ->assertJsonPath('scan.status', 'RECEIVED_PENDING_VERIFICATION');
        $this->assertSame(0, (int) $product->fresh()->stock);

        $shipment = PesoShipment::create([
            'warehouse_id' => $this->warehouse->id,
            'status' => 'ready_for_receiving',
            'total_packages' => 1,
            'received_packages' => 0,
        ]);
        $item = PesoItem::create([
            'peso_shipment_id' => $shipment->id,
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'product_name' => $product->name,
            'barcode' => 'PRE-MANIFEST-540',
            'status' => 'expected',
            'original_quantity' => 1,
            'remaining_quantity' => 1,
        ]);

        app(PesoReceivingReconciliationService::class)->reconcileShipment((int) $shipment->id);
        $this->assertSame('received', $item->fresh()->status);
        $this->assertSame(0, (int) $product->fresh()->stock, 'An unmapped barcode is not booked to an unknown product.');

        $this->actingAs($this->receiver)
            ->post(route('peso.shipments.map-product', $shipment->id), [
                'product_id' => $product->id,
                'barcode_quantities' => [$item->id => 540],
            ])
            ->assertRedirect(route('peso.shipments.show', $shipment->id));

        $this->assertSame(540, (int) $product->fresh()->stock);
        $this->assertSame(540, (int) ProductLocationStock::query()->where('product_id', $product->id)->where('location_id', $this->location->id)->value('quantity'));
        $this->assertSame(540, (int) $item->fresh()->original_quantity);
        $this->assertSame('Nos', $item->fresh()->base_unit);
    }

    public function test_partial_product_loading_rejects_wrong_godown_product_unreceived_missing_and_consumed_codes(): void
    {
        $product = $this->product('Excel Safety', [
            'base_inventory_unit' => 'Nos',
            'partial_barcode_sales' => true,
            'minimum_sale_quantity' => 1,
            'sale_increment' => 1,
            'stock' => 1000,
        ]);
        $otherProduct = $this->product('Other Excel', [
            'base_inventory_unit' => 'Nos',
            'partial_barcode_sales' => true,
            'minimum_sale_quantity' => 1,
            'sale_increment' => 1,
            'stock' => 1000,
        ]);
        $order = $this->createOrder($product, 100);
        $item = $order->items()->firstOrFail();
        $wrongProduct = $this->expectedBarcode($otherProduct, 'WRONG-PRODUCT', 100);
        $notReceived = $this->expectedBarcode($product, 'NOT-RECEIVED', 100);
        $consumed = $this->expectedBarcode($product, 'CONSUMED', 100);
        $consumed->update(['status' => 'received', 'received_at' => now(), 'remaining_quantity' => 0]);

        $cases = [
            [$this->warehouse->id + 10000, 'WRONG-PRODUCT'],
            [$this->warehouse->id, 'WRONG-PRODUCT'],
            [$this->warehouse->id, 'NOT-RECEIVED'],
            [$this->warehouse->id, 'MISSING'],
            [$this->warehouse->id, 'CONSUMED'],
        ];

        foreach ($cases as [$warehouseId, $barcode]) {
            try {
                app(LoadingService::class)->scan((int) $this->organization->id, (int) $warehouseId, (int) $item->id, $barcode);
                $this->fail("Barcode {$barcode} in warehouse {$warehouseId} must be rejected.");
            } catch (ValidationException) {
                $this->assertSame(100, (int) $item->fresh()->quantity);
            }
        }

        $this->assertDatabaseMissing('peso_barcode_allocations', ['peso_item_id' => $wrongProduct->id]);
        $this->assertDatabaseMissing('peso_barcode_allocations', ['peso_item_id' => $notReceived->id]);
        $this->assertDatabaseMissing('peso_barcode_allocations', ['peso_item_id' => $consumed->id]);
    }
}
