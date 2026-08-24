<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostFoundItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'reported_by',

        // Found report
        'found_by_user_id',

        // Lost report
        'lost_by_user_id',

        // CSU / Security processor
        'processed_by_user_id',

        // Report details
        'report_type',
        'item_name',
        'category',
        'brand_model',
        'color',

        // Found information
        'location_found',
        'date_found',

        // Lost information
        'location_lost',
        'date_lost',

        'description',
        'status',

        // Claim information
        'claimed_by',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'date_found' => 'datetime',
            'date_lost' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PERSON WHO FOUND / TURNED OVER ITEM
    |--------------------------------------------------------------------------
    */

    public function finder()
    {
        return $this->belongsTo(
            User::class,
            'found_by_user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PERSON WHO LOST THE ITEM
    |--------------------------------------------------------------------------
    */

    public function lostBy()
    {
        return $this->belongsTo(
            User::class,
            'lost_by_user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CSU / SECURITY PROCESSOR
    |--------------------------------------------------------------------------
    */

    public function processor()
    {
        return $this->belongsTo(
            User::class,
            'processed_by_user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | REPORTER
    |--------------------------------------------------------------------------
    */

    public function reporter()
    {
        return $this->belongsTo(
            User::class,
            'reported_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CLAIMANT
    |--------------------------------------------------------------------------
    */

    public function claimant()
    {
        return $this->belongsTo(
            User::class,
            'claimed_by'
        );
    }
}