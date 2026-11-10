<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$warehouses = \App\Models\Warehouse::query()
    ->get(['id', 'name']);

foreach ($warehouses as $warehouse) {
    echo "ID: {$warehouse->id} | Name: {$warehouse->name}" . PHP_EOL;
}