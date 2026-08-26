<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'role',
        'register_items',
        'view_qr_codes',
        'approve_requests',
        'scan_verify',
        'view_reports',
        'manage_users',
    ];

    protected $casts = [
        'register_items' => 'boolean',
        'view_qr_codes' => 'boolean',
        'approve_requests' => 'boolean',
        'scan_verify' => 'boolean',
        'view_reports' => 'boolean',
        'manage_users' => 'boolean',
    ];
}