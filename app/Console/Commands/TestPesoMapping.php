<?php

namespace App\Console\Commands;

use App\Models\PesoItem;
use Illuminate\Console\Command;

class TestPesoMapping extends Command
{
    protected $signature = 'peso:test-mapping {shipment_id} {product_id}';

    protected $description = 'Test PESO shipment product mapping';

    public function handle(): int
    {
        $shipmentId = (int) $this->argument('shipment_id');
        $productId = (int) $this->argument('product_id');

        $count = PesoItem::where('peso_shipment_id', $shipmentId)
            ->update([
                'product_id' => $productId,
                'updated_at' => now(),
            ]);

        $this->info("Updated {$count} PESO items.");

        return self::SUCCESS;
    }
}