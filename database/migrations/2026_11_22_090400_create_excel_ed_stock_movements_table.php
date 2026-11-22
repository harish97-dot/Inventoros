<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_ed_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('physical_stock_pack_id');
            $table->string('movement_type', 24);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');
            $table->foreignId('warehouse_id')->nullable();
            $table->foreignId('from_warehouse_id')->nullable();
            $table->foreignId('to_warehouse_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('occurred_at');
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'physical_stock_pack_id', 'occurred_at'],
                'excel_ed_movements_pack_time_index'
            );
            $table->index(
                ['organization_id', 'movement_type', 'occurred_at'],
                'excel_ed_movements_type_time_index'
            );
            $table->index(
                ['reference_type', 'reference_id'],
                'excel_ed_movements_reference_index'
            );

            $table->foreign('organization_id', 'excel_ed_movements_org_fk')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'physical_stock_pack_id'],
                'excel_ed_movements_pack_fk'
            )->references(['organization_id', 'id'])
                ->on('excel_ed_stock_packs')->restrictOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'], 'excel_ed_movements_warehouse_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'from_warehouse_id'],
                'excel_ed_movements_from_warehouse_fk'
            )->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'to_warehouse_id'],
                'excel_ed_movements_to_warehouse_fk'
            )->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign('user_id', 'excel_ed_movements_user_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_ed_stock_movements');
    }
};
