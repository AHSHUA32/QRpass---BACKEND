<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegisteredItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'item_name',
        'brand_model',
        'serial_number',
        'color',
        'item_type',
        'purpose',
        'status',
        'qr_code',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}