<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add candidate keys required by tenant-scoped receipt foreign keys. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'users_org_id_receipt_ref_unique');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'suppliers_org_id_receipt_ref_unique');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['organization_id', 'product_id', 'id'], 'variants_org_product_id_receipt_ref_unique');
        });

        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'stock_adjustments_org_id_receipt_ref_unique');
            $table->unique(
                ['organization_id', 'product_id', 'id'],
                'stock_adjustments_org_product_id_receipt_ref_unique'
            );
            $table->unique(
                ['organization_id', 'product_variant_id', 'id'],
                'stock_adjustments_org_variant_id_receipt_ref_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->dropUnique('stock_adjustments_org_variant_id_receipt_ref_unique');
            $table->dropUnique('stock_adjustments_org_product_id_receipt_ref_unique');
            $table->dropUnique('stock_adjustments_org_id_receipt_ref_unique');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique('variants_org_product_id_receipt_ref_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_org_id_receipt_ref_unique');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique('suppliers_org_id_receipt_ref_unique');
        });
    }
};
