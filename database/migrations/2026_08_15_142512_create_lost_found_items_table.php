<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostFoundItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'reported_by',
        'item_name',
        'category',
        'brand_model',
        'color',
        'location_found',
        'description',
        'status',
        'date_found',
        'claimed_by',
        'claimed_at',
    ];

    protected $casts = [
        'date_found' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function claimant()
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}