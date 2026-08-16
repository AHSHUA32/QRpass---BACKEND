<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostFoundItem extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | MASS ASSIGNABLE FIELDS
    |--------------------------------------------------------------------------
    */

    protected $fillable = [

        /*
        |--------------------------------------------------------------------------
        | LEGACY / REPORTING USER
        |--------------------------------------------------------------------------
        |
        | We keep reported_by because it already exists in the current
        | database. For new CSU-created records, this will also point to
        | the CSU personnel who encoded the report.
        |
        */

        'reported_by',


        /*
        |--------------------------------------------------------------------------
        | PERSON WHO FOUND / TURNED OVER THE ITEM
        |--------------------------------------------------------------------------
        */

        'found_by_user_id',


        /*
        |--------------------------------------------------------------------------
        | CSU PERSONNEL WHO PROCESSED THE REPORT
        |--------------------------------------------------------------------------
        */

        'processed_by_user_id',


        /*
        |--------------------------------------------------------------------------
        | REPORT INFORMATION
        |--------------------------------------------------------------------------
        */

        'report_type',

        'item_name',

        'category',

        'brand_model',

        'color',

        'location_found',

        'description',

        'status',

        'date_found',


        /*
        |--------------------------------------------------------------------------
        | CLAIM INFORMATION
        |--------------------------------------------------------------------------
        */

        'claimed_by',

        'claimed_at',
    ];


    /*
    |--------------------------------------------------------------------------
    | ATTRIBUTE CASTS
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'date_found' => 'date',

            'claimed_at' => 'datetime',
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | FINDER
    |--------------------------------------------------------------------------
    |
    | The student/user who physically found the item and turned it over
    | to the CSU office.
    |
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
    | CSU PROCESSOR
    |--------------------------------------------------------------------------
    |
    | The CSU personnel who officially entered and processed the
    | Lost & Found record.
    |
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
    |
    | Kept for compatibility with existing Lost & Found records.
    |
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
    |
    | The student/user claiming that the found item belongs to them.
    |
    */

    public function claimant()
    {
        return $this->belongsTo(
            User::class,
            'claimed_by'
        );
    }
}