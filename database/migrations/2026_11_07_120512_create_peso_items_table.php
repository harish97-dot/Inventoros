<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peso_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('peso_shipment_id')
                ->constrained('peso_shipments')
                ->cascadeOnDelete();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('warehouse_id')
                ->nullable()
                ->constrained('warehouses')
                ->nullOnDelete();

            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            $table->string('product_name', 255);

            $table->string('size_code', 50)->nullable();

            /*
             * Actual L1/manufacturer barcode extracted from the
             * official PESO document.
             */
            $table->string('barcode', 150);

            $table->string('status', 30)->default('expected');

            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['organization_id', 'barcode'],
                'peso_items_org_barcode_unique'
            );

            $table->index(['peso_shipment_id', 'status']);
            $table->index(['warehouse_id', 'product_id']);
            $table->index(['barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peso_items');
    }
};