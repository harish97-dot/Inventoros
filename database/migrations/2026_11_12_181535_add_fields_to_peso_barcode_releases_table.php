<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peso_barcode_releases', function (Blueprint $table) {
            $table->foreignId('peso_item_id')
                ->constrained('peso_items')
                ->cascadeOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('order_item_id')
                ->constrained('order_items')
                ->cascadeOnDelete();

            $table->string('barcode', 150);

            $table->unsignedInteger('old_quantity');

            $table->unsignedInteger('new_quantity');

            $table->foreignId('released_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('released_at');

            $table->string('reason', 500)->nullable();

            $table->index(['order_id', 'order_item_id']);
            $table->index(['peso_item_id']);
            $table->index(['barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('peso_barcode_releases', function (Blueprint $table) {
            $table->dropForeign(['peso_item_id']);
            $table->dropForeign(['order_id']);
            $table->dropForeign(['order_item_id']);
            $table->dropForeign(['released_by']);

            $table->dropIndex(['order_id', 'order_item_id']);
            $table->dropIndex(['peso_item_id']);
            $table->dropIndex(['barcode']);

            $table->dropColumn([
                'peso_item_id',
                'order_id',
                'order_item_id',
                'barcode',
                'old_quantity',
                'new_quantity',
                'released_by',
                'released_at',
                'reason',
            ]);
        });
    }
};
