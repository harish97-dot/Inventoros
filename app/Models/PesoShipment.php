<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PesoShipment extends Model
{
    protected $table = 'peso_shipments';

    protected $fillable = [
        're12_number',
        'peso_date',
        'licence_number',
        'warehouse_id',
        'pdf_path',
        'status',
        'total_packages',
        'received_packages',
        'notes',
    ];

    protected $casts = [
        'peso_date' => 'date',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PesoItem::class);
    }
}