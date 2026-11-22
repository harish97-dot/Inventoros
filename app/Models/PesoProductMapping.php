<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PesoProductMapping extends Model
{
    protected $fillable = [
        'organization_id',
        'manufacturer_name',
        'manufacturer_name_key',
        're12_code',
        'internal_product_name',
        'product_id',
        'product_variant_id',
    ];

    public static function exactNameKey(string $manufacturerName): string
    {
        return hash('sha256', $manufacturerName);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function pesoItems(): HasMany
    {
        return $this->hasMany(PesoItem::class, 'peso_product_mapping_id');
    }
}
