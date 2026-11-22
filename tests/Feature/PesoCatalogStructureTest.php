<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductOption;
use App\Models\Inventory\ProductVariant;
use App\Models\PesoProductMapping;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PesoCatalogStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_size_code_variants_keep_independent_zero_stock_and_exact_manufacturer_mappings(): void
    {
        $organization = Organization::create([
            'name' => 'Catalog Structure Test',
            'email' => 'catalog-' . uniqid() . '@example.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $excel = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'EXCEL-TEST',
            'name' => 'Excel',
            'price' => 0,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
            'base_inventory_unit' => 'Nos',
            'partial_barcode_sales' => true,
            'minimum_sale_quantity' => 1,
            'sale_increment' => 1,
            'has_variants' => true,
        ]);

        ProductOption::create([
            'product_id' => $excel->id,
            'name' => 'Manufacturer',
            'position' => 0,
            'values' => ['Kelvev-I'],
        ]);
        ProductOption::create([
            'product_id' => $excel->id,
            'name' => 'RE-12 Code',
            'position' => 1,
            'values' => ['DDN', 'DDJ'],
        ]);

        $variants = collect(['DDN', 'DDJ'])->mapWithKeys(function (string $code) use ($excel, $organization) {
            $variant = ProductVariant::create([
                'product_id' => $excel->id,
                'organization_id' => $organization->id,
                'title' => 'Kelvev-I / ' . $code,
                'option_values' => ['Manufacturer' => 'Kelvev-I', 'RE-12 Code' => $code],
                'stock' => 0,
                'min_stock' => 0,
                'is_active' => true,
                'position' => $code === 'DDN' ? 0 : 1,
            ]);

            return [$code => $variant];
        });

        foreach ($variants as $code => $variant) {
            $name = 'Kelvev-I';
            PesoProductMapping::create([
                'organization_id' => $organization->id,
                'manufacturer_name' => $name,
                'manufacturer_name_key' => PesoProductMapping::exactNameKey($name),
                're12_code' => $code,
                'internal_product_name' => 'EXCEL',
                'product_id' => $excel->id,
                'product_variant_id' => $variant->id,
            ]);
        }

        foreach ([['IDEAL Boost', 'CDA'], ['Kelvex-200', 'CDA'], ['Vishnu Prime-E', 'CDA']] as [$name, $code]) {
            PesoProductMapping::create([
                'organization_id' => $organization->id,
                'manufacturer_name' => $name,
                'manufacturer_name_key' => PesoProductMapping::exactNameKey($name),
                're12_code' => $code,
                'internal_product_name' => 'BOOSTER',
                'product_id' => null,
            ]);
        }

        foreach ([
            ['IDEAL Power 90', 'CAA', 'POWER GEL'],
            ['IDEAL Cord-10', 'FAE', 'D-CORD'],
            ['SOLAR e-det', 'DBW', 'ED'],
        ] as [$name, $code, $internalName]) {
            PesoProductMapping::create([
                'organization_id' => $organization->id,
                'manufacturer_name' => $name,
                'manufacturer_name_key' => PesoProductMapping::exactNameKey($name),
                're12_code' => $code,
                'internal_product_name' => $internalName,
                'product_id' => null,
            ]);
        }

        $this->assertSame(0, (int) $variants['DDN']->fresh()->stock);
        $this->assertSame(0, (int) $variants['DDJ']->fresh()->stock);
        $this->assertSame(0, (int) $excel->fresh()->total_stock);
        $this->assertSame($variants['DDN']->id, PesoProductMapping::query()->where('manufacturer_name', 'Kelvev-I')->where('re12_code', 'DDN')->value('product_variant_id'));
        $this->assertSame($variants['DDJ']->id, PesoProductMapping::query()->where('manufacturer_name', 'Kelvev-I')->where('re12_code', 'DDJ')->value('product_variant_id'));
        $this->assertSame(3, PesoProductMapping::query()->where('internal_product_name', 'BOOSTER')->count());
        $this->assertSame(1, PesoProductMapping::query()->where('manufacturer_name', 'IDEAL Boost')->where('re12_code', 'CDA')->count());
        $this->assertSame(1, PesoProductMapping::query()->where('manufacturer_name', 'Kelvex-200')->where('re12_code', 'CDA')->count());
        $this->assertNull(PesoProductMapping::query()->where('internal_product_name', 'BOOSTER')->value('product_id'));

        SystemSetting::set('installed', true, 'boolean');
        $admin = User::create([
            'name' => 'Catalog Admin',
            'email' => 'catalog-admin-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            ['name' => 'Administrator', 'is_system' => true, 'permissions' => []],
        );
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('catalogProducts', function ($rows) use ($variants) {
                    $rows = collect($rows);
                    $excel = $rows->firstWhere('name', 'EXCEL');
                    $booster = $rows->firstWhere('name', 'BOOSTER');

                    return $rows->pluck('name')->all() === ['BOOSTER', 'POWER GEL', 'D-CORD', 'EXCEL', 'ED']
                        && $excel['quantity'] === 0
                        && collect($excel['manufacturers'])->firstWhere('manufacturer', 'Kelvev-I')['size_codes'] === [
                            ['code' => 'DDN', 'quantity' => 0, 'unit' => 'Nos', 'configured' => true],
                            ['code' => 'DDJ', 'quantity' => 0, 'unit' => 'Nos', 'configured' => true],
                        ]
                        && collect($booster['manufacturers'])->pluck('manufacturer')->sort()->values()->all() === ['IDEAL Boost', 'Kelvex-200', 'Vishnu Prime-E'];
                })
            );
    }
}
