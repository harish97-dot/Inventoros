<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = \Illuminate\Support\Facades\DB::table('warehouse_licences')
    ->select([
        'id',
        'warehouse_id',
        'licence_number',
        'is_active',
    ])
    ->get();

if ($rows->isEmpty()) {
    echo "warehouse_licences is EMPTY" . PHP_EOL;
    exit;
}

foreach ($rows as $row) {
    echo "ID: {$row->id}"
        . " | Warehouse: {$row->warehouse_id}"
        . " | Licence: {$row->licence_number}"
        . " | Active: " . ($row->is_active ? 'YES' : 'NO')
        . PHP_EOL;
}