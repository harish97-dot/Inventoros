<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peso_product_mappings', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->nullOnDelete();
        });

        Schema::table('peso_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->nullOnDelete();
            $table->string('barcode_level', 2)->default('L1')->after('barcode');
            $table->foreignId('parent_peso_item_id')->nullable()->after('barcode_level')
                ->constrained('peso_items')->nullOnDelete();
            $table->decimal('declared_quantity', 14, 3)->nullable()->after('parent_peso_item_id');
            $table->string('declared_unit', 32)->nullable()->after('declared_quantity');
            $table->text('package_information')->nullable()->after('declared_unit');
            $table->index(['parent_peso_item_id', 'barcode_level'], 'peso_items_parent_level_index');
        });
    }

    public function down(): void
    {
        Schema::table('peso_items', function (Blueprint $table): void {
            $table->dropIndex('peso_items_parent_level_index');
            $table->dropConstrainedForeignId('parent_peso_item_id');
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn(['barcode_level', 'declared_quantity', 'declared_unit', 'package_information']);
        });

        Schema::table('peso_product_mappings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
