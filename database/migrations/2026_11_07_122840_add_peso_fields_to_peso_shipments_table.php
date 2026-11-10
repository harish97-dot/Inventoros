<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peso_shipments', function (Blueprint $table) {
            $table->string('re12_number', 100)
                ->nullable()
                ->after('id');

            $table->date('peso_date')
                ->nullable()
                ->after('re12_number');

            $table->string('licence_number', 150)
                ->nullable()
                ->after('peso_date');

            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('licence_number')
                ->constrained('warehouses')
                ->nullOnDelete();

            $table->string('pdf_path', 500)
                ->nullable()
                ->after('warehouse_id');

            $table->string('status', 40)
                ->default('uploaded')
                ->after('pdf_path');

            $table->unsignedInteger('total_packages')
                ->default(0)
                ->after('status');

            $table->unsignedInteger('received_packages')
                ->default(0)
                ->after('total_packages');

            $table->text('notes')
                ->nullable()
                ->after('received_packages');

            $table->index(['licence_number']);
            $table->index(['warehouse_id', 'status']);
            $table->index(['re12_number']);
        });
    }

    public function down(): void
    {
        Schema::table('peso_shipments', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);

            $table->dropIndex(['licence_number']);
            $table->dropIndex(['warehouse_id', 'status']);
            $table->dropIndex(['re12_number']);

            $table->dropColumn([
                're12_number',
                'peso_date',
                'licence_number',
                'warehouse_id',
                'pdf_path',
                'status',
                'total_packages',
                'received_packages',
                'notes',
            ]);
        });
    }
};