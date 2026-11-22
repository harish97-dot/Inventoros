<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add candidate keys needed by tenant-scoped composite foreign keys.
     * These indexes do not change existing records or quantities.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'products_org_id_ref_unique');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'warehouses_org_id_ref_unique');
        });

        Schema::table('warehouse_licences', function (Blueprint $table): void {
            $table->unique(['warehouse_id', 'id'], 'warehouse_licences_warehouse_id_ref_unique');
            $table->unique(
                ['warehouse_id', 'id', 'licence_number'],
                'warehouse_licences_receipt_ref_unique'
            );
        });

        Schema::table('peso_items', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'peso_items_org_id_ref_unique');
        });

        Schema::table('peso_product_mappings', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'id', 'product_id'],
                'peso_product_maps_org_id_product_ref_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('peso_product_mappings', function (Blueprint $table): void {
            $table->dropUnique('peso_product_maps_org_id_product_ref_unique');
        });

        Schema::table('peso_items', function (Blueprint $table): void {
            $table->dropUnique('peso_items_org_id_ref_unique');
        });

        Schema::table('warehouse_licences', function (Blueprint $table): void {
            $table->dropUnique('warehouse_licences_receipt_ref_unique');
            $table->dropUnique('warehouse_licences_warehouse_id_ref_unique');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropUnique('warehouses_org_id_ref_unique');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_org_id_ref_unique');
        });
    }
};
