<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'reported_by',
        'registered_item_id',
        'scanned_code',
        'incident_type',
        'item_name',
        'serial_number',
        'owner_name',
        'owner_id_number',
        'carrier_name',
        'carrier_id_number',
        'direction',
        'gate',
        'status',
        'description',
        'reported_at',

        'resolution_type',
        'resolution_details',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reported_by'
        );
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'resolved_by'
        );
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(
            RegisteredItem::class,
            'registered_item_id'
        );
    }
}