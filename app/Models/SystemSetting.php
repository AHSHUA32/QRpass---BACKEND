<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'system_name',
        'institution',
        'academic_year',
        'semester',
        'session_timeout',
        'max_login_attempts',
        'qr_code_validity_months',
        'two_factor_enabled',
    ];

    protected $casts = [
        'session_timeout' => 'integer',
        'max_login_attempts' => 'integer',
        'qr_code_validity_months' => 'integer',
        'two_factor_enabled' => 'boolean',
    ];
}