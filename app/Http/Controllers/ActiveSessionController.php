<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ActiveSessionController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | GET ACTIVE SESSIONS
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $settings = SystemSetting::firstOrCreate(
            ['id' => 1],
            [
                'system_name' => 'QRpass',
                'institution' =>
                    'University of Cebu - Main Campus',
                'academic_year' => '2026-2027',
                'semester' => '1st Semester',
                'session_timeout' => 30,
                'max_login_attempts' => 5,
                'qr_code_validity_months' => 6,
                'two_factor_enabled' => false,
            ]
        );

        $timeoutMinutes =
            (int) $settings->session_timeout;

        $currentTokenId =
            $request->user()
                ?->currentAccessToken()
                ?->id;

        $tokens = PersonalAccessToken::query()
            ->with('tokenable')
            ->latest('last_used_at')
            ->latest('created_at')
            ->get();

        $sessions = $tokens
            ->filter(function ($token) {
                return $token->tokenable !== null;
            })
            ->map(function ($token) use (
                $timeoutMinutes,
                $currentTokenId
            ) {
                $user = $token->tokenable;

                $lastActivity =
                    $token->last_used_at ??
                    $token->created_at;

                $isActive =
                    $lastActivity &&
                    $lastActivity->copy()
                        ->addMinutes(
                            $timeoutMinutes
                        )
                        ->isFuture();

                return [
                    'id' =>
                        $token->id,

                    'user_id' =>
                        $user->id,

                    'name' =>
                        $user->name,

                    'username' =>
                        $user->username,

                    'role' =>
                        $user->role,

                    'token_name' =>
                        $token->name,

                    'created_at' =>
                        $token->created_at,

                    'last_used_at' =>
                        $token->last_used_at,

                    'last_activity' =>
                        $lastActivity,

                    'is_active' =>
                        $isActive,

                    'is_current' =>
                        (int) $token->id ===
                        (int) $currentTokenId,
                ];
            })
            ->values();

        return response()->json([
            'session_timeout_minutes' =>
                $timeoutMinutes,

            'active_count' =>
                $sessions
                    ->where(
                        'is_active',
                        true
                    )
                    ->count(),

            'total_sessions' =>
                $sessions->count(),

            'sessions' =>
                $sessions,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | REVOKE SESSION
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        $id
    ) {
        $token = PersonalAccessToken::with(
            'tokenable'
        )->findOrFail($id);
    
        $currentTokenId =
            $request->user()
                ?->currentAccessToken()
                ?->id;
    
        if (
            (int) $token->id ===
            (int) $currentTokenId
        ) {
            return response()->json([
                'message' =>
                    'You cannot revoke your current administrator session from this page.',
            ], 422);
        }
    
    
        /*
        |--------------------------------------------------------------------------
        | Save Session Details Before Deleting
        |--------------------------------------------------------------------------
        */
    
        $targetUser =
            $token->tokenable;
    
        $tokenId =
            $token->id;
    
        $tokenName =
            $token->name;
    
        $targetName =
            $targetUser?->name ??
            'Unknown User';
    
    
        /*
        |--------------------------------------------------------------------------
        | Revoke Session
        |--------------------------------------------------------------------------
        */
    
        $token->delete();
    
    
        /*
        |--------------------------------------------------------------------------
        | Audit Log - Session Revoked
        |--------------------------------------------------------------------------
        */
    
        AuditLogger::log(
            action:
                'revoke_session',
    
            description:
                $request->user()->name .
                ' revoked the active session of "' .
                $targetName .
                '".',
    
            eventType:
                'status_change',
    
            module:
                'Active Sessions',
    
            status:
                'success',
    
            metadata: [
                'session_token_id' =>
                    $tokenId,
    
                'token_name' =>
                    $tokenName,
    
                'target_user_id' =>
                    $targetUser?->id,
    
                'target_name' =>
                    $targetUser?->name,
    
                'target_username' =>
                    $targetUser?->username,
    
                'target_role' =>
                    $targetUser?->role,
    
                'revoked_by_user_id' =>
                    $request->user()->id,
    
                'revoked_by_name' =>
                    $request->user()->name,
            ],
    
            user:
                $request->user()
        );
    
    
        return response()->json([
            'message' =>
                'Session revoked successfully.',
        ]);
    }
}