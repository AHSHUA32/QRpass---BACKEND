<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditLogger;
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
        /*
        |--------------------------------------------------------------------------
        | LOAD SYSTEM SETTINGS
        |--------------------------------------------------------------------------
        */

        $settings =
            SystemSetting::firstOrCreate(
                [
                    'id' => 1,
                ],
                [
                    'system_name' =>
                        'QRpass',

                    'institution' =>
                        'University of Cebu - Main Campus',

                    'academic_year' =>
                        '2026-2027',

                    'semester' =>
                        '1st Semester',

                    'session_timeout' =>
                        30,

                    'max_login_attempts' =>
                        5,

                    'qr_code_validity_months' =>
                        6,

                    'two_factor_enabled' =>
                        false,
                ]
            );

        $timeoutMinutes =
            max(
                1,
                (int) $settings->session_timeout
            );

        /*
        |--------------------------------------------------------------------------
        | CURRENT SESSION TOKEN
        |--------------------------------------------------------------------------
        |
        | We explicitly protect the token used for this request from automatic
        | cleanup.
        |
        */

        $currentTokenId =
            $request
                ->user()
                ?->currentAccessToken()
                ?->id;

        /*
        |--------------------------------------------------------------------------
        | SESSION EXPIRATION CUTOFF
        |--------------------------------------------------------------------------
        */

        $cutoff =
            now()->subMinutes(
                $timeoutMinutes
            );

        /*
        |--------------------------------------------------------------------------
        | AUTOMATIC STALE SESSION CLEANUP
        |--------------------------------------------------------------------------
        |
        | A token is stale when:
        |
        | 1. It has been used before, but last_used_at is older than the
        |    configured session timeout.
        |
        | OR
        |
        | 2. It has never been used, and its created_at is older than the
        |    configured session timeout.
        |
        | The current administrator token is never removed here.
        |
        */

        $staleTokens =
            PersonalAccessToken::query()
                ->where(
                    function ($query) use (
                        $cutoff
                    ) {
                        $query
                            ->where(
                                function ($usedQuery) use (
                                    $cutoff
                                ) {
                                    $usedQuery
                                        ->whereNotNull(
                                            'last_used_at'
                                        )
                                        ->where(
                                            'last_used_at',
                                            '<',
                                            $cutoff
                                        );
                                }
                            )
                            ->orWhere(
                                function ($neverUsedQuery) use (
                                    $cutoff
                                ) {
                                    $neverUsedQuery
                                        ->whereNull(
                                            'last_used_at'
                                        )
                                        ->where(
                                            'created_at',
                                            '<',
                                            $cutoff
                                        );
                                }
                            );
                    }
                );

        /*
        |--------------------------------------------------------------------------
        | PROTECT CURRENT SESSION
        |--------------------------------------------------------------------------
        */

        if ($currentTokenId) {
            $staleTokens->where(
                'id',
                '!=',
                $currentTokenId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE EXPIRED TOKENS
        |--------------------------------------------------------------------------
        */

        $deletedStaleSessions =
            $staleTokens->delete();

        /*
        |--------------------------------------------------------------------------
        | LOAD REMAINING SESSIONS
        |--------------------------------------------------------------------------
        */

        $tokens =
            PersonalAccessToken::query()
                ->with(
                    'tokenable'
                )
                ->latest(
                    'last_used_at'
                )
                ->latest(
                    'created_at'
                )
                ->get();

        /*
        |--------------------------------------------------------------------------
        | BUILD SESSION RESPONSE
        |--------------------------------------------------------------------------
        */

        $sessions =
            $tokens
                ->filter(
                    function ($token) {
                        /*
                        |--------------------------------------------------------------------------
                        | Ignore orphaned Sanctum tokens
                        |--------------------------------------------------------------------------
                        */

                        return
                            $token->tokenable !==
                            null;
                    }
                )
                ->map(
                    function ($token) use (
                        $timeoutMinutes,
                        $currentTokenId
                    ) {
                        $user =
                            $token->tokenable;

                        /*
                        |--------------------------------------------------------------------------
                        | LAST ACTIVITY
                        |--------------------------------------------------------------------------
                        |
                        | Newly-created tokens may temporarily have null
                        | last_used_at, so created_at is used as the fallback.
                        |
                        */

                        $lastActivity =
                            $token->last_used_at ??
                            $token->created_at;

                        /*
                        |--------------------------------------------------------------------------
                        | ACTIVE STATUS
                        |--------------------------------------------------------------------------
                        */

                        $isActive =
                            $lastActivity &&
                            $lastActivity
                                ->copy()
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
                    }
                )
                ->values();

        /*
        |--------------------------------------------------------------------------
        | RETURN ACTIVE SESSION DATA
        |--------------------------------------------------------------------------
        */

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

            /*
            |--------------------------------------------------------------------------
            | Useful for confirming automatic cleanup
            |--------------------------------------------------------------------------
            */

            'stale_sessions_removed' =>
                $deletedStaleSessions,

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
        /*
        |--------------------------------------------------------------------------
        | FIND TOKEN
        |--------------------------------------------------------------------------
        */

        $token =
            PersonalAccessToken::with(
                'tokenable'
            )
                ->findOrFail(
                    $id
                );

        /*
        |--------------------------------------------------------------------------
        | CURRENT SESSION
        |--------------------------------------------------------------------------
        */

        $currentTokenId =
            $request
                ->user()
                ?->currentAccessToken()
                ?->id;

        /*
        |--------------------------------------------------------------------------
        | PREVENT REVOKING CURRENT ADMIN SESSION
        |--------------------------------------------------------------------------
        */

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
        | SAVE SESSION DETAILS BEFORE DELETING
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
        | REVOKE SESSION
        |--------------------------------------------------------------------------
        */

        $token->delete();

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG - SESSION REVOKED
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'revoke_session',

            description:
                $request
                    ->user()
                    ->name .
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
                    $request
                        ->user()
                        ->id,

                'revoked_by_name' =>
                    $request
                        ->user()
                        ->name,
            ],

            user:
                $request->user()
        );

        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'message' =>
                'Session revoked successfully.',
        ]);
    }
}