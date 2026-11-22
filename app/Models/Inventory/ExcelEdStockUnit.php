<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\PesoItem;
use App\Models\Warehouse;
use App\Models\WarehouseLicence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/** A physical BOX or LOOSE container; all operational quantities live on packs. */
class ExcelEdStockUnit extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'receipt_uuid',
        'excel_ed_product_profile_id',
        'stock_type',
        'main_barcode',
        'warehouse_id',
        'receiving_warehouse_id',
        'receiving_licence_id',
        'receiving_licence_number',
        'source_peso_item_id',
        'received_at',
        'status',
        'physical_receipt_status',
        're12_verification_status',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ExcelEdStockUnit $unit): void {
            if (! in_array($unit->stock_type, ['BOX', 'LOOSE'], true)) {
                throw ValidationException::withMessages([
                    'stock_type' => 'Stock type must be BOX or LOOSE.',
                ]);
            }

            if ($unit->stock_type === 'BOX' && trim((string) $unit->main_barcode) === '') {
                throw ValidationException::withMessages([
                    'main_barcode' => 'A BOX requires its physical manufacturer barcode.',
                ]);
            }
        });
    }

    public function productProfile(): BelongsTo
    {
        return $this->belongsTo(ExcelEdProductProfile::class, 'excel_ed_product_profile_id');
    }

    public function currentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function receivingWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'receiving_warehouse_id');
    }

    public function receivingLicence(): BelongsTo
    {
        return $this->belongsTo(WarehouseLicence::class, 'receiving_licence_id');
    }

    public function sourcePesoItem(): BelongsTo
    {
        return $this->belongsTo(PesoItem::class, 'source_peso_item_id');
    }

    public function packs(): HasMany
    {
        return $this->hasMany(ExcelEdStockPack::class, 'physical_stock_unit_id');
    }
}
