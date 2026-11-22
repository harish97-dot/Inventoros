<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_ed_stock_units', function (Blueprint $table): void {
            $table->string('physical_receipt_status', 24)->nullable()->after('status');
            $table->enum('re12_verification_status', ['pending', 'matched', 'discrepancy', 'not_applicable'])
                ->nullable()->after('physical_receipt_status');
            $table->unique(['organization_id', 'id'], 'excel_ed_units_org_id_ref_unique');
        });

        Schema::table('product_serials', function (Blueprint $table): void {
            $table->timestamp('physically_received_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable();
            $table->foreignId('receiving_warehouse_id')->nullable();
            $table->foreignId('receiving_licence_id')->nullable();
            $table->string('receiving_licence_number', 150)->nullable();
            $table->string('physical_receipt_status', 24)->nullable();
            $table->enum('re12_verification_status', ['pending', 'matched', 'discrepancy', 'not_applicable'])->nullable();
            $table->foreignId('source_peso_item_id')->nullable();

            $table->unique(['organization_id', 'id'], 'product_serials_org_id_ref_unique');
            $table->index(
                ['organization_id', 'receiving_warehouse_id', 'physical_receipt_status'],
                'product_serials_receipt_location_status_index'
            );
            $table->index(
                ['organization_id', 're12_verification_status'],
                'product_serials_re12_status_index'
            );
            $table->unique(
                ['organization_id', 'source_peso_item_id'],
                'product_serials_org_peso_item_unique'
            );

            $table->foreign('received_by_user_id', 'product_serials_received_by_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'receiving_warehouse_id'],
                'product_serials_receiving_warehouse_fk'
            )->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(
                ['receiving_warehouse_id', 'receiving_licence_id', 'receiving_licence_number'],
                'product_serials_receiving_licence_fk'
            )->references(['warehouse_id', 'id', 'licence_number'])
                ->on('warehouse_licences')->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'source_peso_item_id'],
                'product_serials_source_peso_fk'
            )->references(['organization_id', 'id'])->on('peso_items')->restrictOnDelete();
        });

        $this->createReceivingSnapshotGuards();
    }

    public function down(): void
    {
        $this->dropReceivingSnapshotGuards();

        Schema::table('product_serials', function (Blueprint $table): void {
            $table->dropForeign('product_serials_received_by_user_fk');
            $table->dropForeign('product_serials_receiving_warehouse_fk');
            $table->dropForeign('product_serials_receiving_licence_fk');
            $table->dropForeign('product_serials_source_peso_fk');
            $table->dropUnique('product_serials_org_peso_item_unique');
            $table->dropIndex('product_serials_receipt_location_status_index');
            $table->dropIndex('product_serials_re12_status_index');
            $table->dropUnique('product_serials_org_id_ref_unique');
            $table->dropColumn([
                'physically_received_at',
                'received_by_user_id',
                'receiving_warehouse_id',
                'receiving_licence_id',
                'receiving_licence_number',
                'physical_receipt_status',
                're12_verification_status',
                'source_peso_item_id',
            ]);
        });

        Schema::table('excel_ed_stock_units', function (Blueprint $table): void {
            $table->dropUnique('excel_ed_units_org_id_ref_unique');
            $table->dropColumn(['physical_receipt_status', 're12_verification_status']);
        });
    }

    private function createReceivingSnapshotGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        $allNullOrSet = '(NEW.receiving_warehouse_id IS NULL AND NEW.receiving_licence_id IS NULL AND NEW.receiving_licence_number IS NULL) '
            .'OR (NEW.receiving_warehouse_id IS NOT NULL AND NEW.receiving_licence_id IS NOT NULL AND NEW.receiving_licence_number IS NOT NULL)';

        if ($driver === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $name = 'product_serials_snapshot_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON product_serials WHEN NOT ({$allNullOrSet}) BEGIN SELECT RAISE(ABORT, 'Receiving warehouse and licence snapshot must be complete or empty'); END");
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $name = 'product_serials_snapshot_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON product_serials FOR EACH ROW BEGIN IF NOT ({$allNullOrSet}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receiving warehouse and licence snapshot must be complete or empty'; END IF; END");
            }

            return;
        }

        throw new RuntimeException('Receiving snapshot guards require MySQL or SQLite.');
    }

    private function dropReceivingSnapshotGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_serials_snapshot_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS product_serials_snapshot_update');
    }
};
