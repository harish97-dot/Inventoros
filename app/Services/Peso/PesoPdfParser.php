<?php

declare(strict_types=1);

namespace App\Services\Peso;

use RuntimeException;
use Smalot\PdfParser\Parser;

final class PesoPdfParser
{
    public function parse(string $pdfPath): array
    {
        if (!is_file($pdfPath)) {
            throw new RuntimeException("PDF not found: {$pdfPath}");
        }

        $parser = new Parser();
        $pdf = $parser->parseFile($pdfPath);
        $text = $pdf->getText();

        return $this->parseText($text);
    }

    public function parseText(string $text): array
    {
        return [
            're12_number'    => $this->extractRe12Number($text),
            'peso_date'      => $this->extractDate($text),
            'licence_number' => $this->extractLicenceNumber($text),
            'total_packages' => $this->extractTotalPackages($text),
            'items'          => $this->extractItems($text),
        ];
    }

    private function extractRe12Number(string $text): ?string
    {
        if (preg_match('/RE-12\/\d{4}\/\d+/i', $text, $match)) {
            return strtoupper(trim($match[0]));
        }

        return null;
    }

    private function extractDate(string $text): ?string
    {
        if (preg_match('/Date:\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i', $text, $match)) {
            return sprintf(
                '%04d-%02d-%02d',
                (int) $match[3],
                (int) $match[2],
                (int) $match[1]
            );
        }

        return null;
    }

    private function extractLicenceNumber(string $text): ?string
    {
        if (preg_match('/Consignee.*?Licence Number\s*:?\s*(E\/HQ\/TG\/\d+\/\d+\([A-Z0-9]+\))/is', $text, $match)) {
            return strtoupper(trim($match[1]));
        }

        if (preg_match('/E\/HQ\/TG\/\d+\/\d+\([A-Z0-9]+\)/i', $text, $match)) {
            return strtoupper(trim($match[0]));
        }

        return null;
    }

    private function extractTotalPackages(string $text): int
    {
        if (preg_match('/This\s+pass\s+covers\s+(\d+)\s+packages/i', $text, $match)) {
            return (int) $match[1];
        }

        return 0;
    }

    private function extractItems(string $text): array
    {
        $barcodeRecords = $this->extractBarcodeRecords($text);

        if ($barcodeRecords === []) {
            return [];
        }

        // L1 codes contain the brand and size identity used in the product table.
        $barcodesByGroup = [];
        $recordsByGroup = [];
        $barcodeGroup = [];
        foreach ($barcodeRecords as $index => $record) {
            $barcode = $record['barcode'];
            if ($record['level'] !== 'L1') {
                $parent = $record['parent_barcode'] ?? null;
                $group = $parent !== null ? ($barcodeGroup[$parent] ?? null) : null;
                if ($group !== null) {
                    $recordsByGroup[$group][] = $record;
                    $barcodeGroup[$barcode] = $group;
                }
                continue;
            }

            $brandCode = substr($barcode, 11, 5); // e.g. B0099
            $brandNum  = ltrim($brandCode, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0'); // e.g. 99 or 100
            $sizeCode  = substr($barcode, 16, 3); // e.g. CAA or CDA
            $key = $brandNum . '_' . $sizeCode;
            $barcodeGroup[$barcode] = $key;
            $barcodesByGroup[$key][] = $barcode;
            $recordsByGroup[$key][] = $record;
        }

        // 3. Extract the declared product names from the table header
        // Product entries are separated by numbers followed by names and classification e.g. (99), (100)
        $products = $this->parseProductTable($text);

        // 4. Combine products with barcodes
        $items = [];
        $productsByGroup = [];
        foreach ($products as $prod) {
            $key = $prod['brand_id'] . '_' . $prod['size_code'];
            $productsByGroup[$key] = $prod;
        }

        foreach ($productsByGroup as $key => $prod) {
            $matchedBarcodes = $barcodesByGroup[$key] ?? [];

            // If matched, take them and remove from pool
            unset($barcodesByGroup[$key]);

            $items[] = [
                'product_name'      => $prod['name'],
                'size_code'         => $prod['size_code'],
                'expected_l1_count' => count($matchedBarcodes),
                'barcodes'          => $matchedBarcodes,
                'barcode_records'   => $recordsByGroup[$key] ?? [],
                'declared_quantity' => $prod['declared_quantity'],
                'declared_unit'     => $prod['declared_unit'],
                'package_information' => $prod['package_information'],
            ];
        }

        // Fallback for any leftover barcodes if table parsing missed an item
        foreach ($barcodesByGroup as $key => $codes) {
            [$brandNum, $sizeCode] = explode('_', $key);
            $items[] = [
                'product_name'      => "Product #{$brandNum}",
                'size_code'         => $sizeCode,
                'expected_l1_count' => count($codes),
                'barcodes'          => $codes,
                'barcode_records'   => $recordsByGroup[$key] ?? [],
                'declared_quantity' => null,
                'declared_unit'     => null,
                'package_information' => null,
            ];
        }

        return $items;
    }

    /**
     * Read barcode strings within their printed L1/L2/L3 sections. Older
     * manifests without level headings retain the historical L1 extraction.
     * Child levels inherit the most recently printed parent barcode.
     *
     * @return array<int, array{barcode:string,level:string,parent_barcode:?string}>
     */
    private function extractBarcodeRecords(string $text): array
    {
        $records = [];
        $lastL1 = null;
        $lastL2 = null;
        $sectionPattern = '/(?:^|\n)\s*L([123])\s+Barcode\b([\s\S]*?)(?=\n\s*L[123]\s+Barcode\b|\n\s*Police Station\b|\n\s*Signature\b|$)/i';

        if (preg_match_all($sectionPattern, $text, $sections, PREG_SET_ORDER)) {
            foreach ($sections as $section) {
                $level = 'L' . $section[1];
                $block = $section[2];
                if ($level === 'L1') {
                    // L1 barcodes are 27 characters. Joining whitespace here
                    // recovers PDF text extraction line wraps within a code.
                    $cleanBlock = strtoupper(preg_replace('/\s+/', '', $block));
                    preg_match_all('/IN[A-Z0-9]{25}/', $cleanBlock, $codes);
                } else {
                    // L2/L3 identifiers have their own lengths. Keep tokens
                    // separated so adjacent child codes are never concatenated.
                    preg_match_all('/\bIN[A-Z0-9]+\b/i', $block, $codes);
                }

                foreach (array_values(array_unique($codes[0] ?? [])) as $code) {
                    $code = strtoupper($code);
                    $parent = $level === 'L1' ? null : ($level === 'L2' ? $lastL1 : ($lastL2 ?? $lastL1));
                    $records[] = ['barcode' => $code, 'level' => $level, 'parent_barcode' => $parent];
                    if ($level === 'L1') $lastL1 = $code;
                    if ($level === 'L2') $lastL2 = $code;
                }
            }
        }

        if ($records !== []) {
            return $records;
        }

        $cleanText = strtoupper(preg_replace('/\s+/', '', $text));
        preg_match_all('/IN[A-Z0-9]{25}/', $cleanText, $matches);

        return array_map(
            fn (string $barcode): array => ['barcode' => $barcode, 'level' => 'L1', 'parent_barcode' => null],
            array_values(array_unique($matches[0] ?? [])),
        );
    }

    private function parseProductTable(string $text): array
    {
        // Normalizes line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Find table entries:
        // Starts with sequence number line, explosive name, (Cl ...)(BrandId), followed by Size Code line
        // Example:
        // 1\nIDEAL Power\n90(Cl 2(0))(99)\nCAA
        // 2\nIDEAL Boost(Cl\n2(0))(100)\nCDA
        // The class field may itself contain parentheses (for example
        // "Cl 6(3)"). Match through the class field to the following brand
        // ID instead of stopping at its first closing parenthesis.
        $pattern = '/(?:\n|^)(\d+)\s*\n+(.+?)\s*\(\s*Cl\b[\s\S]*?\)\s*\(\s*(\d+)\s*\)\s*\n+([A-Z]{3})\b/s';

        $products = [];
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $index => $m) {
                $rawName = trim($m[2][0]);
                // PDF line wraps after a hyphen are layout artifacts (e.g.
                // "SOLAR e-\ndet"). Join the split word before collapsing
                // remaining whitespace so the saved source name stays faithful.
                $rawName = preg_replace('/-[\t ]*\R[\t ]*/u', '-', $rawName);
                $rawName = preg_replace('/\s+/u', ' ', trim($rawName));
                $brandId  = trim($m[3][0]);
                $sizeCode = strtoupper(trim($m[4][0]));
                $rowEnd = $m[0][1] + strlen($m[0][0]);
                $tailEnd = $index + 1 < count($matches) ? $matches[$index + 1][0][1] : strlen($text);
                if (preg_match('/(?:^|\n)\s*\d+\s*\n+/', $text, $nextRow, PREG_OFFSET_CAPTURE, $rowEnd)) {
                    $tailEnd = min($tailEnd, $nextRow[0][1]);
                }
                $labelPosition = stripos($text, 'L1 Barcode', $rowEnd);
                if ($labelPosition !== false) $tailEnd = min($tailEnd, $labelPosition);
                $tail = trim(substr($text, $rowEnd, $tailEnd - $rowEnd));
                $packageInformation = preg_replace('/\s+/u', ' ', $tail);
                $declaredQuantity = null;
                $declaredUnit = null;
                if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(Nos?\.?|Mtrs?\.?|Meters?|Kg\.?|Kgs?\.?)/i', $tail, $measurements, PREG_SET_ORDER)) {
                    $declared = end($measurements);
                    $declaredQuantity = str_replace(',', '', $declared[1]);
                    $declaredUnit = rtrim($declared[2], '.');
                }

                // Clean name: e.g. "IDEAL Power 90" or "IDEAL Boost"
                $products[] = [
                    'name'      => $rawName,
                    'brand_id'  => $brandId,
                    'size_code' => $sizeCode,
                    'declared_quantity' => $declaredQuantity,
                    'declared_unit' => $declaredUnit,
                    'package_information' => $packageInformation !== '' ? $packageInformation : null,
                ];
            }
        }

        return $products;
    }
}
