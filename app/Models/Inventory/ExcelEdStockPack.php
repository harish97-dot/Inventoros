<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\PesoItem;
use App\Models\PesoProductMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/** The authoritative, individually consumable EXCEL/ED stock balance. */
class ExcelEdStockPack extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'physical_stock_unit_id',
        'excel_ed_product_profile_id',
        'product_id',
        'peso_product_mapping_id',
        'source_peso_item_id',
        'manufacturer_name',
        'manufacturer_code',
        'pack_identifier',
        'mtr',
        'original_quantity',
        'quantity_source',
        // remaining_quantity is intentionally excluded: persisted balance
        // changes must be made with recordBalanceMovement().
    ];

    /** Internal guard used only while a movement and balance are saved together. */
    private bool $balanceMutationAuthorized = false;

    protected function casts(): array
    {
        return [
            'mtr' => 'integer',
            'original_quantity' => 'integer',
            'remaining_quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ExcelEdStockPack $pack): void {
            $attributes = $pack->getAttributes();

            foreach (['mtr', 'original_quantity', 'remaining_quantity'] as $field) {
                if (! self::isWholeNumber($attributes[$field] ?? null)) {
                    throw ValidationException::withMessages([
                        $field => 'This value must be a whole number.',
                    ]);
                }
            }

            $mtr = (int) $attributes['mtr'];
            $original = (int) $attributes['original_quantity'];
            $remaining = (int) $attributes['remaining_quantity'];

            if ($mtr < 1) {
                throw ValidationException::withMessages([
                    'mtr' => 'Mtr must be a positive whole number.',
                ]);
            }

            if ($original < 1 || $remaining < 0) {
                throw ValidationException::withMessages([
                    'original_quantity' => 'Original quantity must be positive and remaining quantity cannot be negative.',
                ]);
            }

            if ($remaining > $original) {
                throw ValidationException::withMessages([
                    'remaining_quantity' => 'Remaining quantity cannot exceed original quantity.',
                ]);
            }

            $unit = $pack->physicalStockUnit;
            if ($unit?->stock_type === 'BOX' && trim((string) $pack->manufacturer_code) === '') {
                throw ValidationException::withMessages([
                    'manufacturer_code' => 'A BOX pack requires its manufacturer code.',
                ]);
            }

            if ($pack->peso_product_mapping_id !== null) {
                $mapping = PesoProductMapping::query()->find($pack->peso_product_mapping_id);
                if (
                    $mapping === null
                    || (int) $mapping->organization_id !== (int) $pack->organization_id
                    || (int) $mapping->product_id !== (int) $pack->product_id
                    || $mapping->manufacturer_name !== $pack->manufacturer_name
                    || $mapping->re12_code !== $pack->manufacturer_code
                ) {
                    throw ValidationException::withMessages([
                        'peso_product_mapping_id' => 'The exact manufacturer name and code must map to this internal product in this organization.',
                    ]);
                }
            } elseif (filled($pack->manufacturer_code)) {
                throw ValidationException::withMessages([
                    'peso_product_mapping_id' => 'A supplied manufacturer code requires an exact product mapping.',
                ]);
            }

            if ($pack->exists && $pack->isDirty('remaining_quantity') && ! $pack->balanceMutationAuthorized) {
                throw ValidationException::withMessages([
                    'remaining_quantity' => 'Change the pack balance by recording a stock movement.',
                ]);
            }

            if ($pack->exists && $pack->isDirty('original_quantity')) {
                throw ValidationException::withMessages([
                    'original_quantity' => 'Original quantity is historical and cannot be changed after creation.',
                ]);
            }
        });
    }

    public function physicalStockUnit(): BelongsTo
    {
        return $this->belongsTo(ExcelEdStockUnit::class, 'physical_stock_unit_id');
    }

    public function productProfile(): BelongsTo
    {
        return $this->belongsTo(ExcelEdProductProfile::class, 'excel_ed_product_profile_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productMapping(): BelongsTo
    {
        return $this->belongsTo(PesoProductMapping::class, 'peso_product_mapping_id');
    }

    public function sourcePesoItem(): BelongsTo
    {
        return $this->belongsTo(PesoItem::class, 'source_peso_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ExcelEdStockMovement::class, 'physical_stock_pack_id');
    }

    /**
     * Atomically record an audited balance change.
     * This is a generic pack-level primitive; it does not implement receiving,
     * order allocation, reservations, dispatch or order consumption workflows.
     */
    public function recordBalanceMovement(
        string $movementType,
        int $quantity,
        int $quantityAfter,
        ?int $userId = null,
        ?string $reason = null,
        ?string $notes = null,
        ?int $warehouseId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $fromWarehouseId = null,
        ?int $toWarehouseId = null,
    ): ExcelEdStockMovement {
        if (! $this->exists) {
            throw new LogicException('A stock movement requires a persisted pack.');
        }

        if ($quantity < 1 || ! in_array($movementType, ExcelEdStockMovement::TYPES, true)) {
            throw new InvalidArgumentException('A valid movement type and positive whole quantity are required.');
        }

        return DB::transaction(function () use (
            $movementType,
            $quantity,
            $quantityAfter,
            $userId,
            $reason,
            $notes,
            $warehouseId,
            $referenceType,
            $referenceId,
            $fromWarehouseId,
            $toWarehouseId,
        ): ExcelEdStockMovement {
            $pack = static::query()->lockForUpdate()->findOrFail($this->getKey());
            $quantityBefore = (int) $pack->remaining_quantity;
            $original = (int) $pack->original_quantity;

            if ($quantityAfter < 0 || $quantityAfter > $original) {
                throw ValidationException::withMessages([
                    'remaining_quantity' => 'The new remaining quantity must be between zero and original quantity.',
                ]);
            }

            if (abs($quantityAfter - $quantityBefore) !== $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Movement quantity must equal the change in available pack balance.',
                ]);
            }

            $movement = ExcelEdStockMovement::create([
                'organization_id' => $pack->organization_id,
                'physical_stock_pack_id' => $pack->id,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'warehouse_id' => $warehouseId ?? $pack->physicalStockUnit?->warehouse_id,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'user_id' => $userId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'occurred_at' => now(),
                'reason' => $reason,
                'notes' => $notes,
            ]);

            $pack->balanceMutationAuthorized = true;
            $pack->remaining_quantity = $quantityAfter;
            $pack->save();

            return $movement;
        });
    }

    private static function isWholeNumber(mixed $value): bool
    {
        return is_int($value)
            || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1);
    }
}
