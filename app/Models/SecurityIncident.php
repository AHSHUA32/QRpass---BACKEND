<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
];

    protected $casts = [
        'reported_at' => 'datetime',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function item()
    {
        return $this->belongsTo(
            RegisteredItem::class,
            'registered_item_id'
        );
    }
}