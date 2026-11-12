<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Receiving\ReceivingService;
use Illuminate\Console\Command;

class TestReceivingScan extends Command
{
    protected $signature = 'receiving:test {barcode} {warehouseId=2}';

    protected $description = 'Test a physical receiving barcode scan';

    public function handle(ReceivingService $service): int
    {
        $barcode = $this->argument('barcode');
        $warehouseId = (int) $this->argument('warehouseId');

        $this->info("Testing barcode: {$barcode}");
        $this->info("Warehouse ID: {$warehouseId}");

        $scan = $service->scan(
            organizationId: 1,
            warehouseId: $warehouseId,
            barcode: $barcode,
            userId: null
        );

        $this->newLine();
        $this->info('Receiving scan created successfully.');

        $this->table(
            ['Field', 'Value'],
            [
                ['Scan ID', $scan->id],
                ['Barcode', $scan->barcode],
                ['Status', $scan->status],
                ['Warehouse ID', $scan->warehouse_id],
                ['PESO Item ID', $scan->peso_item_id ?? 'NULL'],
                ['PESO Shipment ID', $scan->peso_shipment_id ?? 'NULL'],
                ['Notes', $scan->notes ?? ''],
            ]
        );

        return self::SUCCESS;
    }
}