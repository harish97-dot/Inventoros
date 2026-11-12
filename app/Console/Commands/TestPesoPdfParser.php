<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Peso\PesoPdfParser;
use Illuminate\Console\Command;

class TestPesoPdfParser extends Command
{
    protected $signature = 'peso:test-parser {path}';

    protected $description = 'Test PESO PDF parsing';

    public function handle(PesoPdfParser $parser): int
    {
        $path = $this->argument('path');

        if (!is_file($path)) {
            $this->error("PDF not found: {$path}");
            return self::FAILURE;
        }

        $data = $parser->parse($path);

        $this->table(
            ['Field', 'Value'],
            [
                ['RE-12', $data['re12_number'] ?? 'NULL'],
                ['PESO Date', $data['peso_date'] ?? 'NULL'],
                ['Licence', $data['licence_number'] ?? 'NULL'],
                ['Packages', $data['total_packages'] ?? 'NULL'],
            ]
        );

        $this->newLine();

        $items = $data['items'] ?? [];

        foreach ($items as $item) {
            $this->line(
                'Product: ' .
                ($item['product_name'] ?? '') .
                ' | Size: ' .
                ($item['size_code'] ?? '') .
                ' | Barcodes: ' .
                count($item['barcodes'] ?? [])
            );
        }

        $this->newLine();

        $firstBarcode = $items[0]['barcodes'][0] ?? null;

        if ($firstBarcode === null) {
            $this->error('No barcode found.');
            return self::FAILURE;
        }

        $this->info("FIRST BARCODE: {$firstBarcode}");

        return self::SUCCESS;
    }
}