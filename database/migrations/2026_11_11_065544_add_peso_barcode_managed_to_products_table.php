<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('peso_barcode_managed')
                ->default(false)
                ->after('tracking_type');

            $table->index('peso_barcode_managed');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['peso_barcode_managed']);
            $table->dropColumn('peso_barcode_managed');
        });
    }
};
