<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_ed_stock_units', function (Blueprint $table): void {
            $table->uuid('receipt_uuid')->nullable()->after('organization_id');
            $table->unique(
                ['organization_id', 'receipt_uuid'],
                'excel_ed_units_org_receipt_uuid_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('excel_ed_stock_units', function (Blueprint $table): void {
            $table->dropUnique('excel_ed_units_org_receipt_uuid_unique');
            $table->dropColumn('receipt_uuid');
        });
    }
};
