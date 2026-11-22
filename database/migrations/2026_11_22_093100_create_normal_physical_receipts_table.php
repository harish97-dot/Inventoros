<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normal_physical_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->uuid('receipt_uuid');
            $table->foreignId('product_id');
            $table->foreignId('product_variant_id')->nullable();
            $table->unsignedInteger('original_quantity');
            $table->string('unit', 32);
            $table->string('package_type', 32)->nullable();
            $table->foreignId('warehouse_id');
            $table->foreignId('receiving_licence_id');
            $table->string('receiving_licence_number', 150);
            $table->foreignId('received_by_user_id');
            $table->timestamp('received_at');
            $table->foreignId('supplier_id')->nullable();
            $table->string('supplier_reference', 150)->nullable();
            $table->string('delivery_reference', 150)->nullable();
            $table->string('physical_receipt_status', 24)->nullable();
            $table->enum('re12_verification_status', ['pending', 'matched', 'discrepancy', 'not_applicable'])->nullable();
            $table->foreignId('stock_adjustment_id');
            $table->timestamps();

            $table->unique(['organization_id', 'receipt_uuid'], 'normal_receipts_org_uuid_unique');
            $table->unique(['organization_id', 'id'], 'normal_receipts_org_id_ref_unique');
            $table->unique(['organization_id', 'product_id', 'id'], 'normal_receipts_org_product_id_ref_unique');
            $table->unique(['organization_id', 'stock_adjustment_id'], 'normal_receipts_org_adjustment_unique');
            $table->index(['organization_id', 'warehouse_id', 'physical_receipt_status'], 'normal_receipts_location_status_index');
            $table->index(['organization_id', 're12_verification_status'], 'normal_receipts_re12_status_index');

            $table->foreign('organization_id', 'normal_receipts_org_fk')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_id'], 'normal_receipts_product_fk')
                ->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_id', 'product_variant_id'], 'normal_receipts_variant_fk')
                ->references(['organization_id', 'product_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'], 'normal_receipts_warehouse_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(
                ['warehouse_id', 'receiving_licence_id', 'receiving_licence_number'],
                'normal_receipts_licence_fk'
            )->references(['warehouse_id', 'id', 'licence_number'])->on('warehouse_licences')->restrictOnDelete();
            $table->foreign(['organization_id', 'received_by_user_id'], 'normal_receipts_actor_fk')
                ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['organization_id', 'supplier_id'], 'normal_receipts_supplier_fk')
                ->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'product_id', 'stock_adjustment_id'],
                'normal_receipts_adjustment_fk'
            )->references(['organization_id', 'product_id', 'id'])->on('stock_adjustments')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'product_variant_id', 'stock_adjustment_id'],
                'normal_receipts_variant_adjustment_fk'
            )->references(['organization_id', 'product_variant_id', 'id'])->on('stock_adjustments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normal_physical_receipts');
    }
};
