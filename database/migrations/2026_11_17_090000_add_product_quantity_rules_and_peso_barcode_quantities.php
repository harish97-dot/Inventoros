<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('base_inventory_unit', 32)->default('unit');
            $table->boolean('partial_barcode_sales')->default(false)->index();
            $table->unsignedInteger('minimum_sale_quantity')->default(1);
            $table->unsignedInteger('sale_increment')->default(1);
            $table->string('billing_unit', 32)->nullable();
            $table->json('billing_conversion')->nullable();
            $table->json('packaging_conversions')->nullable();
        });

        Schema::table('peso_items', function (Blueprint $table): void {
            // Existing PESO barcode rows continue to represent one package.
            $table->unsignedInteger('original_quantity')->default(1);
            $table->unsignedInteger('remaining_quantity')->default(1);
            $table->string('base_unit', 32)->nullable();
        });

        Schema::create('peso_barcode_allocations', function (Blueprint $table): void {
            $table->id();
            // Keep audit history intact: source records cannot be deleted while referenced.
            $table->foreignId('peso_item_id')->constrained('peso_items')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->string('event_type', 16); // allocated | released
            $table->unsignedInteger('quantity');
            // Deliberately no worker/user column: receiving and loading use shared phones.
            $table->timestamp('occurred_at');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['peso_item_id', 'event_type']);
            $table->index(['order_item_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peso_barcode_allocations');

        Schema::table('peso_items', function (Blueprint $table): void {
            $table->dropColumn(['original_quantity', 'remaining_quantity', 'base_unit']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['partial_barcode_sales']);
            $table->dropColumn([
                'base_inventory_unit',
                'partial_barcode_sales',
                'minimum_sale_quantity',
                'sale_increment',
                'billing_unit',
                'billing_conversion',
                'packaging_conversions',
            ]);
        });
    }
};
