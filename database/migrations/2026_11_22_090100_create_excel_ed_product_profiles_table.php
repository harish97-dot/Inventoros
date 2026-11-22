<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_ed_product_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('product_id');
            $table->string('product_type', 16); // EXCEL | ED
            $table->timestamps();

            $table->unique(['organization_id', 'product_id'], 'excel_ed_profiles_org_product_unique');
            $table->unique(
                ['organization_id', 'id'],
                'excel_ed_profiles_org_id_ref_unique'
            );
            $table->unique(
                ['organization_id', 'id', 'product_id'],
                'excel_ed_profiles_org_id_product_ref_unique'
            );
            $table->index(['organization_id', 'product_type'], 'excel_ed_profiles_org_type_index');

            $table->foreign('organization_id', 'excel_ed_profiles_org_fk')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_id'], 'excel_ed_profiles_product_fk')
                ->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_ed_product_profiles');
    }
};
