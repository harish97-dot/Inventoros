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
        // 1. Extract and clean ALL barcodes globally first.
        // PDF parser inserts spaces within codes (e.g. "CDA S3016561").
        // Removing all whitespace guarantees clean 27-char chunks.
        $cleanText = strtoupper(preg_replace('/\s+/', '', $text));

        // Find every 27-character PESO L1 barcode starting with IN
        preg_match_all('/IN[A-Z0-9]{25}/', $cleanText, $barcodeMatches);
        $allBarcodes = array_values(array_unique($barcodeMatches[0] ?? []));

        if (empty($allBarcodes)) {
            return [];
        }

        // 2. Group barcodes by their internal Brand Code & Size Code
        // Format: IN(2) + Plant(3) + Date(6) + Brand(5: e.g. B0099, B0100) + Size(3: CAA, CDA) + Rest(8)
        $barcodesByGroup = [];
        foreach ($allBarcodes as $barcode) {
            $brandCode = substr($barcode, 11, 5); // e.g. B0099
            $brandNum  = ltrim($brandCode, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0'); // e.g. 99 or 100
            $sizeCode  = substr($barcode, 16, 3); // e.g. CAA or CDA

            $barcodesByGroup[$brandNum . '_' . $sizeCode][] = $barcode;
        }

        // 3. Extract the declared product names from the table header
        // Product entries are separated by numbers followed by names and classification e.g. (99), (100)
        $products = $this->parseProductTable($text);

        // 4. Combine products with barcodes
        $items = [];
        foreach ($products as $prod) {
            $key = $prod['brand_id'] . '_' . $prod['size_code'];
            $matchedBarcodes = $barcodesByGroup[$key] ?? [];

            // If matched, take them and remove from pool
            unset($barcodesByGroup[$key]);

            $items[] = [
                'product_name'      => $prod['name'],
                'size_code'         => $prod['size_code'],
                'expected_l1_count' => count($matchedBarcodes),
                'barcodes'          => $matchedBarcodes,
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
            ];
        }

        return $items;
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
        $pattern = '/(?:\n|^)(\d+)\s*\n+(.*?)\s*\(\s*Cl[^\)]+\)\s*\(\s*(\d+)\s*\)\s*\n+([A-Z]{3})\b/s';

        $products = [];
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rawName  = preg_replace('/\s+/', ' ', trim($m[2]));
                $brandId  = trim($m[3]);
                $sizeCode = strtoupper(trim($m[4]));

                // Clean name: e.g. "IDEAL Power 90" or "IDEAL Boost"
                $products[] = [
                    'name'      => $rawName,
                    'brand_id'  => $brandId,
                    'size_code' => $sizeCode,
                ];
            }
        }

        return $products;
    }
}