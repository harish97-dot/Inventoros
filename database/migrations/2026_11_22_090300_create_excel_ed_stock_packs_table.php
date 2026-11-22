<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_ed_stock_packs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('physical_stock_unit_id');
            $table->foreignId('excel_ed_product_profile_id');
            $table->foreignId('product_id');
            $table->foreignId('peso_product_mapping_id')->nullable();
            $table->foreignId('source_peso_item_id')->nullable();
            $table->string('manufacturer_name', 255);
            $table->string('manufacturer_code', 50)->nullable();
            $table->string('pack_identifier', 150)->nullable();
            $table->unsignedInteger('mtr');
            $table->unsignedInteger('original_quantity');
            $table->unsignedInteger('remaining_quantity');
            $table->string('quantity_source', 16)->default('manual');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'id'],
                'excel_ed_packs_org_id_ref_unique'
            );
            $table->index(
                ['organization_id', 'physical_stock_unit_id'],
                'excel_ed_packs_unit_index'
            );
            $table->index(
                [
                    'organization_id',
                    'excel_ed_product_profile_id',
                    'manufacturer_code',
                    'mtr',
                    'remaining_quantity',
                ],
                'excel_ed_packs_stock_lookup_index'
            );
            $table->index(
                ['organization_id', 'peso_product_mapping_id'],
                'excel_ed_packs_mapping_index'
            );
            $table->index(
                ['organization_id', 'source_peso_item_id'],
                'excel_ed_packs_source_peso_index'
            );

            $table->foreign('organization_id', 'excel_ed_packs_org_fk')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'physical_stock_unit_id', 'excel_ed_product_profile_id'],
                'excel_ed_packs_unit_profile_fk'
            )->references(['organization_id', 'id', 'excel_ed_product_profile_id'])
                ->on('excel_ed_stock_units')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'excel_ed_product_profile_id', 'product_id'],
                'excel_ed_packs_profile_product_fk'
            )->references(['organization_id', 'id', 'product_id'])
                ->on('excel_ed_product_profiles')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'peso_product_mapping_id', 'product_id'],
                'excel_ed_packs_mapping_product_fk'
            )->references(['organization_id', 'id', 'product_id'])
                ->on('peso_product_mappings')->restrictOnDelete();
            $table->foreign(['organization_id', 'source_peso_item_id'], 'excel_ed_packs_source_peso_fk')
                ->references(['organization_id', 'id'])->on('peso_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_ed_stock_packs');
    }
};
