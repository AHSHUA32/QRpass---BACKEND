<?php

namespace App\Http\Controllers;

use App\Models\RolePermission;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | FIXED ROLE PERMISSION MATRIX
    |--------------------------------------------------------------------------
    |
    | All QRPass role permissions are fixed and cannot be edited.
    |
    | Student:
    | - Register Items
    | - View QR Codes
    |
    | Security (CSU):
    | - Scan & Verify
    | - View Reports
    |
    | PCO Staff:
    | - View QR Codes
    | - Approve Requests
    | - View Reports
    |
    | System Administrator:
    | - View Reports
    | - Manage Users
    |
    */

    private function fixedPermissions(): array
    {
        return [
            'student' => [
                'register_items' => true,
                'view_qr_codes' => true,
                'approve_requests' => false,
                'scan_verify' => false,
                'view_reports' => false,
                'manage_users' => false,
            ],

            'security' => [
                'register_items' => false,
                'view_qr_codes' => false,
                'approve_requests' => false,
                'scan_verify' => true,
                'view_reports' => true,
                'manage_users' => false,
            ],

            'pco' => [
                'register_items' => false,
                'view_qr_codes' => true,
                'approve_requests' => true,
                'scan_verify' => false,
                'view_reports' => true,
                'manage_users' => false,
            ],

            'sysadmin' => [
                'register_items' => false,
                'view_qr_codes' => false,
                'approve_requests' => false,
                'scan_verify' => false,
                'view_reports' => true,
                'manage_users' => true,
            ],
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | GET ROLE PERMISSIONS
    |--------------------------------------------------------------------------
    */

    public function index()
    {
        $fixedPermissions =
            $this->fixedPermissions();

        /*
        |--------------------------------------------------------------------------
        | Keep every database row synchronized with the fixed permission matrix.
        |--------------------------------------------------------------------------
        */

        foreach (
            $fixedPermissions
            as $role => $permissions
        ) {
            RolePermission::updateOrCreate(
                ['role' => $role],
                $permissions
            );
        }

        $roles = [
            'student',
            'security',
            'pco',
            'sysadmin',
        ];

        $permissions =
            RolePermission::whereIn(
                'role',
                $roles
            )
                ->orderByRaw(
                    "FIELD(role, 'student', 'security', 'pco', 'sysadmin')"
                )
                ->get();

        return response()->json([
            'permissions' =>
                $permissions,

            'fixed' =>
                true,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE ROLE PERMISSIONS
    |--------------------------------------------------------------------------
    |
    | Role permissions are fixed. This endpoint remains available for
    | compatibility with the existing API, but any request is forced back to
    | the approved QRPass permission matrix.
    |
    */

    public function update(
        Request $request,
        string $role
    ) {
        $fixedPermissions =
            $this->fixedPermissions();

        if (
            !array_key_exists(
                $role,
                $fixedPermissions
            )
        ) {
            return response()->json([
                'message' =>
                    'Invalid QRPass role.',
            ], 422);
        }

        $permission =
            RolePermission::firstOrCreate(
                ['role' => $role]
            );

        $before = [
            'register_items' =>
                (bool)
                $permission->register_items,

            'view_qr_codes' =>
                (bool)
                $permission->view_qr_codes,

            'approve_requests' =>
                (bool)
                $permission->approve_requests,

            'scan_verify' =>
                (bool)
                $permission->scan_verify,

            'view_reports' =>
                (bool)
                $permission->view_reports,

            'manage_users' =>
                (bool)
                $permission->manage_users,
        ];

        /*
        |--------------------------------------------------------------------------
        | FORCE FIXED VALUES
        |--------------------------------------------------------------------------
        */

        $permission->update(
            $fixedPermissions[$role]
        );

        $permission->refresh();


        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'enforce_fixed_role_permissions',

            description:
                $request->user()->name .
                ' accessed the fixed permission configuration for the "' .
                $role .
                '" role. The approved QRPass permission matrix was enforced.',

            eventType:
                'update',

            module:
                'Security Configuration',

            status:
                'success',

            metadata: [
                'role' =>
                    $role,

                'fixed' =>
                    true,

                'before' =>
                    $before,

                'after' => [
                    'register_items' =>
                        (bool)
                        $permission->register_items,

                    'view_qr_codes' =>
                        (bool)
                        $permission->view_qr_codes,

                    'approve_requests' =>
                        (bool)
                        $permission->approve_requests,

                    'scan_verify' =>
                        (bool)
                        $permission->scan_verify,

                    'view_reports' =>
                        (bool)
                        $permission->view_reports,

                    'manage_users' =>
                        (bool)
                        $permission->manage_users,
                ],
            ],

            user:
                $request->user()
        );


        return response()->json([
            'message' =>
                'Role permissions are fixed and cannot be edited.',

            'permission' =>
                $permission,

            'fixed' =>
                true,
        ]);
    }
}
