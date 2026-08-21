<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Example:
     *
     * role:sysadmin
     *
     * or:
     *
     * role:security,sysadmin
     */
    public function handle(
        Request $request,
        Closure $next,
        ...$roles
    ): Response {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | User must be authenticated
        |--------------------------------------------------------------------------
        */

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Disabled accounts cannot access protected QRPass modules
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
        | Check role permission
        |--------------------------------------------------------------------------
        */

        if (!in_array($user->role, $roles)) {
            return response()->json([
                'message' =>
                    'You are not authorized to access this QRPass module.',
            ], 403);
        }

        return $next($request);
    }
}