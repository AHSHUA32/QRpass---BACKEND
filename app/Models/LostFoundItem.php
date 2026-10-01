<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LostFoundItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'reported_by',
        'found_by_user_id',
        'lost_by_user_id',
        'processed_by_user_id',
        'report_type',
        'item_name',
        'category',
        'brand_model',
        'color',
        'location_found',
        'location_lost',
        'date_found',
        'date_lost',
        'description',
        'status',
        'claimed_by',
        'claimed_at',
    ];

    protected $casts = [
        'date_found' => 'datetime',
        'date_lost' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function finder(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'found_by_user_id'
        );
    }

    public function lostBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'lost_by_user_id'
        );
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'processed_by_user_id'
        );
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reported_by'
        );
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'claimed_by'
        );
    }
}
