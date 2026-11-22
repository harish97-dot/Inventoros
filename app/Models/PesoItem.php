<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Order\OrderItem;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;

class PesoItem extends Model
{
    protected $table = 'peso_items';

    protected static function booted(): void
    {
        static::saving(function (PesoItem $item): void {
            if ($item->product_id === null) {
                return;
            }

            $product = $item->relationLoaded('product')
                ? $item->product
                : Product::query()->find($item->product_id);

            if (! $product?->partial_barcode_sales) {
                return;
            }

            $original = (int) $item->original_quantity;
            $remaining = (int) $item->remaining_quantity;
            $baseUnit = (string) $product->base_inventory_unit;

            if ($original < 1 || $remaining < 0 || $remaining > $original) {
                throw ValidationException::withMessages([
                    'barcode_quantity' => 'Barcode remaining quantity must be between zero and its positive original quantity.',
                ]);
            }

            if ($item->base_unit !== $baseUnit) {
                throw ValidationException::withMessages([
                    'barcode_quantity' => "Barcode quantity must use the product base unit ({$baseUnit}).",
                ]);
            }
        });
    }

    protected $fillable = [
        'peso_shipment_id',
        'organization_id',
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'peso_product_mapping_id',
        'product_name',
        'size_code',
        'barcode',
        'barcode_level',
        'parent_peso_item_id',
        'declared_quantity',
        'declared_unit',
        'package_information',
        'status',
        'received_at',
        'original_quantity',
        'remaining_quantity',
        'base_unit',
        'quantity_source',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'original_quantity' => 'integer',
        'remaining_quantity' => 'integer',
        'declared_quantity' => 'decimal:3',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(PesoShipment::class, 'peso_shipment_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function parentBarcode(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_peso_item_id');
    }

    public function childBarcodes()
    {
        return $this->hasMany(self::class, 'parent_peso_item_id');
    }

    public function productMapping(): BelongsTo
    {
        return $this->belongsTo(PesoProductMapping::class, 'peso_product_mapping_id');
    }
    public function orderItem(): BelongsTo
{
    return $this->belongsTo(OrderItem::class);
}

    public function allocations()
    {
        return $this->hasMany(PesoBarcodeAllocation::class, 'peso_item_id');
    }
}
