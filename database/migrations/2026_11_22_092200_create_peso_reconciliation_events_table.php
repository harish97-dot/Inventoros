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
        Schema::create('peso_reconciliation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('peso_item_id');
            $table->foreignId('product_serial_id')->nullable();
            $table->foreignId('excel_ed_stock_unit_id')->nullable();
            $table->foreignId('excel_ed_stock_pack_id')->nullable();
            $table->string('outcome', 24); // matched | discrepancy
            $table->json('discrepancy_details')->nullable();
            $table->foreignId('actor_user_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(
                ['organization_id', 'peso_item_id', 'occurred_at'],
                'peso_recon_events_source_time_index'
            );
            $table->index(
                ['organization_id', 'product_serial_id', 'occurred_at'],
                'peso_recon_events_serial_time_index'
            );
            $table->index(
                ['organization_id', 'excel_ed_stock_unit_id', 'occurred_at'],
                'peso_recon_events_unit_time_index'
            );
            $table->index(
                ['organization_id', 'excel_ed_stock_pack_id', 'occurred_at'],
                'peso_recon_events_pack_time_index'
            );

            $table->foreign('organization_id', 'peso_recon_events_org_fk')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['organization_id', 'peso_item_id'], 'peso_recon_events_peso_item_fk')
                ->references(['organization_id', 'id'])->on('peso_items')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_serial_id'], 'peso_recon_events_serial_fk')
                ->references(['organization_id', 'id'])->on('product_serials')->restrictOnDelete();
            $table->foreign(['organization_id', 'excel_ed_stock_unit_id'], 'peso_recon_events_unit_fk')
                ->references(['organization_id', 'id'])->on('excel_ed_stock_units')->restrictOnDelete();
            $table->foreign(['organization_id', 'excel_ed_stock_pack_id'], 'peso_recon_events_pack_fk')
                ->references(['organization_id', 'id'])->on('excel_ed_stock_packs')->restrictOnDelete();
            $table->foreign('actor_user_id', 'peso_recon_events_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        $this->createEventGuards();
    }

    public function down(): void
    {
        $this->dropEventGuards();
        Schema::dropIfExists('peso_reconciliation_events');
    }

    private function createEventGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER peso_recon_events_validate_insert
                BEFORE INSERT ON peso_reconciliation_events
                WHEN ((NEW.product_serial_id IS NOT NULL) + (NEW.excel_ed_stock_unit_id IS NOT NULL) + (NEW.excel_ed_stock_pack_id IS NOT NULL)) > 1
                    OR NEW.outcome NOT IN ('matched', 'discrepancy')
                    OR (NEW.outcome = 'matched' AND ((NEW.product_serial_id IS NOT NULL) + (NEW.excel_ed_stock_unit_id IS NOT NULL) + (NEW.excel_ed_stock_pack_id IS NOT NULL)) != 1)
                BEGIN
                    SELECT RAISE(ABORT, 'Matched events require one receipt identity; events cannot reference multiple identities');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER peso_recon_events_validate_update
                BEFORE UPDATE ON peso_reconciliation_events
                WHEN ((NEW.product_serial_id IS NOT NULL) + (NEW.excel_ed_stock_unit_id IS NOT NULL) + (NEW.excel_ed_stock_pack_id IS NOT NULL)) > 1
                    OR NEW.outcome NOT IN ('matched', 'discrepancy')
                    OR (NEW.outcome = 'matched' AND ((NEW.product_serial_id IS NOT NULL) + (NEW.excel_ed_stock_unit_id IS NOT NULL) + (NEW.excel_ed_stock_pack_id IS NOT NULL)) != 1)
                BEGIN
                    SELECT RAISE(ABORT, 'Matched events require one receipt identity; events cannot reference multiple identities');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER peso_recon_events_append_only_update
                BEFORE UPDATE ON peso_reconciliation_events
                BEGIN
                    SELECT RAISE(ABORT, 'Reconciliation events are append-only');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER peso_recon_events_append_only_delete
                BEFORE DELETE ON peso_reconciliation_events
                BEGIN
                    SELECT RAISE(ABORT, 'Reconciliation events are append-only');
                END
                SQL);

            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER peso_recon_events_validate_insert
                BEFORE INSERT ON peso_reconciliation_events FOR EACH ROW
                BEGIN
                    IF ((NEW.product_serial_id IS NOT NULL) + (NEW.excel_ed_stock_unit_id IS NOT NULL) + (NEW.excel_ed_stock_pack_id IS NOT NULL)) > 1
                        OR NEW.outcome NOT IN ('matched', 'discrepancy')
                        OR (NEW.outcome = 'matched' AND ((NEW.product_serial_id IS NOT NULL) + (NEW.excel_ed_stock_unit_id IS NOT NULL) + (NEW.excel_ed_stock_pack_id IS NOT NULL)) <> 1) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Matched events require one receipt identity; events cannot reference multiple identities';
                    END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER peso_recon_events_validate_update
                BEFORE UPDATE ON peso_reconciliation_events FOR EACH ROW
                BEGIN
                    IF ((NEW.product_serial_id IS NOT NULL) + (NEW.excel_ed_stock_unit_id IS NOT NULL) + (NEW.excel_ed_stock_pack_id IS NOT NULL)) > 1
                        OR NEW.outcome NOT IN ('matched', 'discrepancy')
                        OR (NEW.outcome = 'matched' AND ((NEW.product_serial_id IS NOT NULL) + (NEW.excel_ed_stock_unit_id IS NOT NULL) + (NEW.excel_ed_stock_pack_id IS NOT NULL)) <> 1) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Matched events require one receipt identity; events cannot reference multiple identities';
                    END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER peso_recon_events_append_only_update
                BEFORE UPDATE ON peso_reconciliation_events FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation events are append-only';
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER peso_recon_events_append_only_delete
                BEFORE DELETE ON peso_reconciliation_events FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation events are append-only';
                END
                SQL);

            return;
        }

        throw new RuntimeException('Reconciliation event guards require MySQL or SQLite.');
    }

    private function dropEventGuards(): void
    {
        foreach ([
            'peso_recon_events_validate_insert',
            'peso_recon_events_validate_update',
            'peso_recon_events_append_only_update',
            'peso_recon_events_append_only_delete',
        ] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }
};
