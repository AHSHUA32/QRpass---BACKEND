<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SessionTimeoutMiddleware
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        /*
        |--------------------------------------------------------------------------
        | NO TOKEN = LET AUTHENTICATION HANDLE IT
        |--------------------------------------------------------------------------
        */

        $plainTextToken =
            $request->bearerToken();

        if (!$plainTextToken) {
            return $next($request);
        }


        /*
        |--------------------------------------------------------------------------
        | FIND SANCTUM TOKEN BEFORE AUTHENTICATION UPDATES LAST_USED_AT
        |--------------------------------------------------------------------------
        */

        $accessToken =
            PersonalAccessToken::findToken(
                $plainTextToken
            );

        if (!$accessToken) {
            return $next($request);
        }


        /*
        |--------------------------------------------------------------------------
        | GET CONFIGURED SESSION TIMEOUT
        |--------------------------------------------------------------------------
        */

        $settings =
            SystemSetting::first();

        $timeoutMinutes =
            max(
                1,
                (int) (
                    $settings
                        ?->session_timeout ??
                    30
                )
            );


        /*
        |--------------------------------------------------------------------------
        | DETERMINE LAST ACTIVITY
        |--------------------------------------------------------------------------
        */

        $lastActivity =
            $accessToken->last_used_at ??
            $accessToken->created_at;

        if (!$lastActivity) {
            return $next($request);
        }


        /*
        |--------------------------------------------------------------------------
        | EXPIRE INACTIVE SESSION
        |--------------------------------------------------------------------------
        */

        $expiresAt =
            $lastActivity
                ->copy()
                ->addMinutes(
                    $timeoutMinutes
                );

        if ($expiresAt->isPast()) {
            $accessToken->delete();

            return response()->json([
                'message' =>
                    'Your session has expired due to inactivity. Please log in again.',
            ], 401);
        }


        return $next($request);
    }
}