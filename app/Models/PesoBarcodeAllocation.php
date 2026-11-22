<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Order\OrderItem;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An immutable quantity allocation or release against one exact PESO barcode. */
final class PesoBarcodeAllocation extends Model
{
    protected $fillable = [
        'peso_item_id',
        'order_item_id',
        'event_type',
        'quantity',
        'occurred_at',
        'reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (PesoBarcodeAllocation $allocation): void {
            if (! in_array($allocation->event_type, ['allocated', 'released'], true)
                || (int) $allocation->quantity < 1) {
                throw ValidationException::withMessages([
                    'allocation' => 'Barcode allocation events must have a valid type and a positive quantity.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function pesoItem(): BelongsTo
    {
        return $this->belongsTo(PesoItem::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
