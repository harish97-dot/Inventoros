<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$licences = [
    [
        'warehouse_id' => 1,
        'licence_number' => 'E/HQ/TG/22/759(E48397)',
        'is_active' => true,
    ],
    [
        'warehouse_id' => 2,
        'licence_number' => 'E/HQ/TG/22/619(E49505)',
        'is_active' => true,
    ],
];

foreach ($licences as $licence) {
    \Illuminate\Support\Facades\DB::table('warehouse_licences')->insert([
        ...$licence,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo "Added: {$licence['licence_number']} -> Warehouse {$licence['warehouse_id']}" . PHP_EOL;
}
