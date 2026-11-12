<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peso_items', function (Blueprint $table) {
            $table->foreignId('order_item_id')
                ->nullable()
                ->after('product_id')
                ->constrained('order_items')
                ->nullOnDelete();

            $table->index(['order_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('peso_items', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropIndex(['order_item_id', 'status']);
            $table->dropColumn('order_item_id');
        });
    }
};