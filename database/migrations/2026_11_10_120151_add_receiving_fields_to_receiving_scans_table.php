<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("receiving_scans", function (Blueprint $table) {
            $table->foreignId("organization_id")
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId("warehouse_id")
                ->constrained("warehouses")
                ->cascadeOnDelete();

            $table->string("barcode", 150);

            $table->string("status", 40)
                ->default("RECEIVED_PENDING_VERIFICATION");

            $table->foreignId("scanned_by")
                ->nullable()
                ->constrained("users")
                ->nullOnDelete();

            $table->timestamp("scanned_at");

            $table->foreignId("peso_item_id")
                ->nullable()
                ->constrained("peso_items")
                ->nullOnDelete();

            $table->foreignId("peso_shipment_id")
                ->nullable()
                ->constrained("peso_shipments")
                ->nullOnDelete();

            $table->text("notes")->nullable();

            $table->index(["organization_id", "barcode"]);
            $table->index(["warehouse_id", "scanned_at"]);
            $table->index(["warehouse_id", "status"]);
            $table->index(["peso_shipment_id", "status"]);
            $table->index(["barcode", "scanned_at"]);
        });
    }

    public function down(): void
    {
        Schema::table("receiving_scans", function (Blueprint $table) {
            $table->dropForeign(["organization_id"]);
            $table->dropForeign(["warehouse_id"]);
            $table->dropForeign(["scanned_by"]);
            $table->dropForeign(["peso_item_id"]);
            $table->dropForeign(["peso_shipment_id"]);

            $table->dropIndex(["organization_id", "barcode"]);
            $table->dropIndex(["warehouse_id", "scanned_at"]);
            $table->dropIndex(["warehouse_id", "status"]);
            $table->dropIndex(["peso_shipment_id", "status"]);
            $table->dropIndex(["barcode", "scanned_at"]);

            $table->dropColumn([
                "organization_id",
                "warehouse_id",
                "barcode",
                "status",
                "scanned_by",
                "scanned_at",
                "peso_item_id",
                "peso_shipment_id",
                "notes",
            ]);
        });
    }
};
