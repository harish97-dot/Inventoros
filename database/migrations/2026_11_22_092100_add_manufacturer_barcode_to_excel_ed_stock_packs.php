<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_ed_stock_packs', function (Blueprint $table): void {
            $table->string('manufacturer_barcode', 150)->nullable()->after('pack_identifier');
            $table->unique(
                ['organization_id', 'manufacturer_barcode'],
                'excel_ed_packs_org_manufacturer_barcode_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('excel_ed_stock_packs', function (Blueprint $table): void {
            $table->dropUnique('excel_ed_packs_org_manufacturer_barcode_unique');
            $table->dropColumn('manufacturer_barcode');
        });
    }
};
