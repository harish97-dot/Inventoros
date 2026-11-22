<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peso_items', function (Blueprint $table): void {
            $table->string('quantity_source', 16)->nullable()->after('base_unit');
        });
    }

    public function down(): void
    {
        Schema::table('peso_items', function (Blueprint $table): void {
            $table->dropColumn('quantity_source');
        });
    }
};
