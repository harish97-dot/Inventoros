<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/** Identifies an internal EXCEL or ED product using physical-pack stock. */
class ExcelEdProductProfile extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        static::saving(function (ExcelEdProductProfile $profile): void {
            if (! in_array($profile->product_type, ['EXCEL', 'ED'], true)) {
                throw ValidationException::withMessages([
                    'product_type' => 'Product type must be EXCEL or ED.',
                ]);
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'product_id',
        'product_type',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockUnits(): HasMany
    {
        return $this->hasMany(ExcelEdStockUnit::class, 'excel_ed_product_profile_id');
    }
}
