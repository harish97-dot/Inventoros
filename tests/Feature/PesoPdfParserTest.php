<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Peso\PesoPdfParser;
use Tests\TestCase;

final class PesoPdfParserTest extends TestCase
{
    public function test_nested_class_parentheses_and_repeated_product_rows_parse_to_exact_barcode_groups(): void
    {
        $text = <<<'PDFTEXT'
FORM RE-12 - Annexure-L1
Pass No: RE-12/2026/704223 Date: 07/11/2026
This pass covers 9 packages containing following explosives :
1
Kelvev-I(Cl
6(3))(1629)
DDN
L1 Barcode
INKEG051126G1629DDNG8004782 INKEG051126G1629DDNG8004783 INKEG051126G1629DDNG8004784
2
Kelvev-I(Cl
6(3))(1629)
DDN
L1 Barcode
INKEG051126G1629DDNG8004785 INKEG051126G1629DDNG8004786
3
Kelvev-I(Cl
6(3))(1629)
DDJ
L1 Barcode
INKEG210826G1629DDJG8001583 INKEG051126G1629DDJG8004796
4
Kelvev-I(Cl
6(3))(1629)
DDJ
L1 Barcode
INKEG210826G1629DDJG8001586 INKEG051126G1629DDJG8004788
Consignee Licence Number :E/HQ/TG/22/619(E49505)
PDFTEXT;

        $data = app(PesoPdfParser::class)->parseText($text);

        $this->assertSame('RE-12/2026/704223', $data['re12_number']);
        $this->assertSame('2026-11-07', $data['peso_date']);
        $this->assertSame('E/HQ/TG/22/619(E49505)', $data['licence_number']);
        $this->assertSame(9, $data['total_packages']);
        $this->assertCount(2, $data['items']);
        $this->assertSame('Kelvev-I', $data['items'][0]['product_name']);
        $this->assertSame('DDN', $data['items'][0]['size_code']);
        $this->assertCount(5, $data['items'][0]['barcodes']);
        $this->assertSame('INKEG051126G1629DDNG8004782', $data['items'][0]['barcodes'][0]);
        $this->assertSame('DDJ', $data['items'][1]['size_code']);
        $this->assertCount(4, $data['items'][1]['barcodes']);
        $this->assertSame(9, array_sum(array_map(fn (array $item) => count($item['barcodes']), $data['items'])));
    }
}
