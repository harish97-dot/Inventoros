<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivingScan extends Model
{
    protected $table = 'receiving_scans';

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'barcode',
        'status',
        'scanned_by',
        'scanned_at',
        'peso_item_id',
        'peso_shipment_id',
        'notes',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    public function pesoItem(): BelongsTo
    {
        return $this->belongsTo(PesoItem::class);
    }

    public function pesoShipment(): BelongsTo
    {
        return $this->belongsTo(PesoShipment::class);
    }
}