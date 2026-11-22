<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Immutable audit row for a physical EXCEL/ED pack movement. */
class ExcelEdStockMovement extends Model
{
    use BelongsToOrganization;

    public const TYPES = [
        'RECEIPT',
        'ADJUSTMENT',
        'ALLOCATION',
        'RELEASE',
        'CONSUMPTION',
        'TRANSFER',
    ];

    protected $fillable = [
        'organization_id',
        'physical_stock_pack_id',
        'movement_type',
        'quantity',
        'quantity_before',
        'quantity_after',
        'warehouse_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'user_id',
        'reference_type',
        'reference_id',
        'occurred_at',
        'reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ExcelEdStockMovement $movement): void {
            if (! in_array($movement->movement_type, self::TYPES, true)) {
                throw ValidationException::withMessages([
                    'movement_type' => 'Movement type is not supported.',
                ]);
            }

            foreach (['quantity', 'quantity_before', 'quantity_after'] as $field) {
                $value = $movement->getAttributes()[$field] ?? null;
                if (! self::isWholeNumber($value)) {
                    throw ValidationException::withMessages([
                        $field => 'This value must be a whole number.',
                    ]);
                }
            }

            if ((int) $movement->quantity < 1 || (int) $movement->quantity_before < 0 || (int) $movement->quantity_after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Movement quantity must be positive and balances cannot be negative.',
                ]);
            }
        });

        static::updating(function (): never {
            throw new LogicException('Stock movement history is append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Stock movement history cannot be deleted.');
        });
    }

    public function stockPack(): BelongsTo
    {
        return $this->belongsTo(ExcelEdStockPack::class, 'physical_stock_pack_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    private static function isWholeNumber(mixed $value): bool
    {
        return is_int($value)
            || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1);
    }
}
