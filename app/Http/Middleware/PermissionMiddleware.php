<?php

namespace App\Http\Middleware;

use App\Models\RolePermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(
        Request $request,
        Closure $next,
        string $permission
    ): Response {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Must Be Authenticated
        |--------------------------------------------------------------------------
        */

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Account Must Be Active
        |--------------------------------------------------------------------------
        */

        if ($user->status !== 'approved') {
            return response()->json([
                'message' =>
                    'Your account is inactive. Please contact the System Administrator.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Valid Permission Names
        |--------------------------------------------------------------------------
        */

        $allowedPermissions = [
            'register_items',
            'view_qr_codes',
            'approve_requests',
            'scan_verify',
            'view_reports',
            'manage_users',
        ];

        if (
            !in_array(
                $permission,
                $allowedPermissions,
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'Invalid QRPass permission.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Get Role Permission Record
        |--------------------------------------------------------------------------
        */

        $rolePermission =
            RolePermission::where(
                'role',
                $user->role
            )->first();

        if (!$rolePermission) {
            return response()->json([
                'message' =>
                    'No permission configuration exists for your role.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Check Permission
        |--------------------------------------------------------------------------
        */

        if (
            !$rolePermission->{$permission}
        ) {
            return response()->json([
                'message' =>
                    'You do not have permission to access this QRPass module.',
            ], 403);
        }

        return $next($request);
    }
}