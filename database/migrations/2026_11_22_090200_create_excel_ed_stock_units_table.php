<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_ed_stock_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('excel_ed_product_profile_id');
            $table->string('stock_type', 8); // BOX | LOOSE
            $table->string('main_barcode', 150)->nullable();
            $table->foreignId('warehouse_id'); // Current location
            $table->foreignId('receiving_warehouse_id'); // Receipt-time location snapshot
            $table->foreignId('receiving_licence_id');
            $table->string('receiving_licence_number', 150);
            $table->foreignId('source_peso_item_id')->nullable();
            $table->timestamp('received_at');
            $table->string('status', 24)->default('active');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'id', 'excel_ed_product_profile_id'],
                'excel_ed_units_org_id_profile_unique'
            );
            $table->unique(['organization_id', 'main_barcode'], 'excel_ed_units_org_barcode_unique');
            $table->unique(
                ['organization_id', 'source_peso_item_id'],
                'excel_ed_units_org_peso_item_unique'
            );
            $table->index(
                [
                    'organization_id',
                    'excel_ed_product_profile_id',
                    'warehouse_id',
                    'stock_type',
                    'status',
                ],
                'excel_ed_units_stock_lookup_index'
            );
            $table->index(['organization_id', 'receiving_licence_id'], 'excel_ed_units_licence_index');

            $table->foreign('organization_id', 'excel_ed_units_org_fk')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'excel_ed_product_profile_id'],
                'excel_ed_units_profile_fk'
            )->references(['organization_id', 'id'])
                ->on('excel_ed_product_profiles')->restrictOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'], 'excel_ed_units_current_warehouse_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'receiving_warehouse_id'],
                'excel_ed_units_receiving_warehouse_fk'
            )->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(
                ['receiving_warehouse_id', 'receiving_licence_id', 'receiving_licence_number'],
                'excel_ed_units_receiving_licence_fk'
            )->references(['warehouse_id', 'id', 'licence_number'])
                ->on('warehouse_licences')->restrictOnDelete();
            $table->foreign(['organization_id', 'source_peso_item_id'], 'excel_ed_units_source_peso_fk')
                ->references(['organization_id', 'id'])->on('peso_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_ed_stock_units');
    }
};
