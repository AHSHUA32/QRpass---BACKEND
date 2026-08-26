<?php

namespace Database\Seeders;

use App\Models\RolePermission;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            [
                'role' => 'student',
                'register_items' => true,
                'view_qr_codes' => true,
                'approve_requests' => false,
                'scan_verify' => false,
                'view_reports' => false,
                'manage_users' => false,
            ],

            [
                'role' => 'security',
                'register_items' => false,
                'view_qr_codes' => false,
                'approve_requests' => false,
                'scan_verify' => true,
                'view_reports' => true,
                'manage_users' => false,
            ],

            [
                'role' => 'pco',
                'register_items' => false,
                'view_qr_codes' => true,
                'approve_requests' => true,
                'scan_verify' => false,
                'view_reports' => true,
                'manage_users' => false,
            ],

            [
                'role' => 'sysadmin',
                'register_items' => true,
                'view_qr_codes' => true,
                'approve_requests' => true,
                'scan_verify' => true,
                'view_reports' => true,
                'manage_users' => true,
            ],
        ];

        foreach ($permissions as $permission) {
            RolePermission::updateOrCreate(
                [
                    'role' =>
                        $permission['role'],
                ],
                $permission
            );
        }
    }
}