<?php

declare(strict_types=1);

namespace App\Models\Peso;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\PesoItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PesoBarcodeRelease extends Model
{
    protected $table = 'peso_barcode_releases';

    protected $fillable = [
        'peso_item_id',
        'order_id',
        'order_item_id',
        'barcode',
        'old_quantity',
        'new_quantity',
        'released_by',
        'released_at',
        'reason',
    ];

    protected $casts = [
        'old_quantity' => 'integer',
        'new_quantity' => 'integer',
        'released_at' => 'datetime',
    ];

    public function pesoItem(): BelongsTo
    {
        return $this->belongsTo(PesoItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}