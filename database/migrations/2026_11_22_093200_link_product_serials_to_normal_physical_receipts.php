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
        Schema::table('product_serials', function (Blueprint $table): void {
            $table->foreignId('normal_physical_receipt_id')->nullable()->after('source_peso_item_id');
            $table->index(
                ['organization_id', 'normal_physical_receipt_id'],
                'product_serials_normal_receipt_index'
            );
            $table->foreign(
                ['organization_id', 'product_id', 'normal_physical_receipt_id'],
                'product_serials_normal_receipt_fk'
            )->references(['organization_id', 'product_id', 'id'])
                ->on('normal_physical_receipts')->restrictOnDelete();
        });

        // SQLite rebuilds a table when adding a composite foreign key and
        // drops its triggers as a side effect. Restore the existing snapshot
        // guards verbatim so test/dev migrations preserve production rules.
        $this->restoreProductSerialSnapshotGuards();
    }

    public function down(): void
    {
        Schema::table('product_serials', function (Blueprint $table): void {
            $table->dropForeign('product_serials_normal_receipt_fk');
            $table->dropIndex('product_serials_normal_receipt_index');
            $table->dropColumn('normal_physical_receipt_id');
        });

        $this->restoreProductSerialSnapshotGuards();
    }

    private function restoreProductSerialSnapshotGuards(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $allNullOrSet = '(NEW.receiving_warehouse_id IS NULL AND NEW.receiving_licence_id IS NULL AND NEW.receiving_licence_number IS NULL) '
            .'OR (NEW.receiving_warehouse_id IS NOT NULL AND NEW.receiving_licence_id IS NOT NULL AND NEW.receiving_licence_number IS NOT NULL)';

        DB::unprepared('DROP TRIGGER IF EXISTS product_serials_snapshot_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS product_serials_snapshot_update');
        DB::unprepared("CREATE TRIGGER product_serials_snapshot_insert BEFORE INSERT ON product_serials WHEN NOT ({$allNullOrSet}) BEGIN SELECT RAISE(ABORT, 'Receiving warehouse and licence snapshot must be complete or empty'); END");
        DB::unprepared("CREATE TRIGGER product_serials_snapshot_update BEFORE UPDATE ON product_serials WHEN NOT ({$allNullOrSet}) BEGIN SELECT RAISE(ABORT, 'Receiving warehouse and licence snapshot must be complete or empty'); END");
    }
};
