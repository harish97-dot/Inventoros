<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peso_product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('manufacturer_name', 255);
            $table->char('manufacturer_name_key', 64);
            $table->string('re12_code', 32);
            $table->string('internal_product_name', 255);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'manufacturer_name_key', 're12_code'],
                'peso_product_mappings_exact_group_unique'
            );
            $table->index(['organization_id', 'product_id']);
        });

        Schema::table('peso_items', function (Blueprint $table): void {
            $table->foreignId('peso_product_mapping_id')
                ->nullable()
                ->after('product_id')
                ->constrained('peso_product_mappings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('peso_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('peso_product_mapping_id');
        });

        Schema::dropIfExists('peso_product_mappings');
    }
};
