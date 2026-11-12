<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Order\OrderItem;
use App\Models\Inventory\Product;

class PesoItem extends Model
{
    protected $table = 'peso_items';

    protected $fillable = [
        'peso_shipment_id',
        'organization_id',
        'warehouse_id',
        'product_id',
        'product_name',
        'size_code',
        'barcode',
        'status',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
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
    public function orderItem(): BelongsTo
{
    return $this->belongsTo(OrderItem::class);
}
}