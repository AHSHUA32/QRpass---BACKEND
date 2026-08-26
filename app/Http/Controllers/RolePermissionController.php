<?php

namespace App\Http\Controllers;

use App\Models\RolePermission;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RolePermissionController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | GET ROLE PERMISSIONS
    |--------------------------------------------------------------------------
    */

    public function index()
    {
        $roles = [
            'student',
            'security',
            'pco',
            'sysadmin',
        ];

        foreach ($roles as $role) {
            RolePermission::firstOrCreate(
                ['role' => $role],
                [
                    'register_items' => false,
                    'view_qr_codes' => false,
                    'approve_requests' => false,
                    'scan_verify' => false,
                    'view_reports' => false,
                    'manage_users' => false,
                ]
            );
        }

        $permissions = RolePermission::whereIn(
            'role',
            $roles
        )
            ->orderByRaw(
                "FIELD(role, 'student', 'security', 'pco', 'sysadmin')"
            )
            ->get();

        return response()->json([
            'permissions' => $permissions,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE ROLE PERMISSIONS
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        string $role
    ) {
        $validated = $request->validate([
            'role' => [
                'sometimes',
                Rule::in([
                    'student',
                    'security',
                    'pco',
                    'sysadmin',
                ]),
            ],

            'register_items' => [
                'required',
                'boolean',
            ],

            'view_qr_codes' => [
                'required',
                'boolean',
            ],

            'approve_requests' => [
                'required',
                'boolean',
            ],

            'scan_verify' => [
                'required',
                'boolean',
            ],

            'view_reports' => [
                'required',
                'boolean',
            ],

            'manage_users' => [
                'required',
                'boolean',
            ],
        ]);

        if (
            !in_array(
                $role,
                [
                    'student',
                    'security',
                    'pco',
                    'sysadmin',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'Invalid QRPass role.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | Protect System Administrator
        |--------------------------------------------------------------------------
        |
        | Keep the System Administrator with full access so the system cannot
        | accidentally lock every administrator out of management functions.
        |
        */

        if ($role === 'sysadmin') {
            $validated = [
                'register_items' => true,
                'view_qr_codes' => true,
                'approve_requests' => true,
                'scan_verify' => true,
                'view_reports' => true,
                'manage_users' => true,
            ];
        }


        $permission = RolePermission::firstOrCreate(
            ['role' => $role]
        );

        $before = [
            'register_items' =>
                (bool) $permission->register_items,

            'view_qr_codes' =>
                (bool) $permission->view_qr_codes,

            'approve_requests' =>
                (bool) $permission->approve_requests,

            'scan_verify' =>
                (bool) $permission->scan_verify,

            'view_reports' =>
                (bool) $permission->view_reports,

            'manage_users' =>
                (bool) $permission->manage_users,
        ];


        /*
        |--------------------------------------------------------------------------
        | SAVE PERMISSIONS
        |--------------------------------------------------------------------------
        */

        $permission->update([
            'register_items' =>
                $validated['register_items'],

            'view_qr_codes' =>
                $validated['view_qr_codes'],

            'approve_requests' =>
                $validated['approve_requests'],

            'scan_verify' =>
                $validated['scan_verify'],

            'view_reports' =>
                $validated['view_reports'],

            'manage_users' =>
                $validated['manage_users'],
        ]);


        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'update_role_permissions',

            description:
                $request->user()->name .
                ' updated permissions for the "' .
                $role .
                '" role.',

            eventType:
                'update',

            module:
                'Security Configuration',

            status:
                'success',

            metadata: [
                'role' =>
                    $role,

                'before' =>
                    $before,

                'after' => [
                    'register_items' =>
                        (bool) $permission->register_items,

                    'view_qr_codes' =>
                        (bool) $permission->view_qr_codes,

                    'approve_requests' =>
                        (bool) $permission->approve_requests,

                    'scan_verify' =>
                        (bool) $permission->scan_verify,

                    'view_reports' =>
                        (bool) $permission->view_reports,

                    'manage_users' =>
                        (bool) $permission->manage_users,
                ],
            ],

            user:
                $request->user()
        );


        return response()->json([
            'message' =>
                'Role permissions updated successfully.',

            'permission' =>
                $permission,
        ]);
    }
}