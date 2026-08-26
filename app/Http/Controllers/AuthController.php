<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | LOGIN
    |--------------------------------------------------------------------------
    */

    public function login(Request $request)
    {
        $request->validate([
            'username' =>
                'required|string',

            'password' =>
                'required|string',
        ]);

        /*
        |--------------------------------------------------------------------------
        | SYSTEM SETTINGS
        |--------------------------------------------------------------------------
        */

        $settings =
            SystemSetting::first();

        $maxAttempts =
            max(
                1,
                (int) (
                    $settings
                        ?->max_login_attempts ??
                    5
                )
            );

        $twoFactorEnabled =
            (bool) (
                $settings
                    ?->two_factor_enabled ??
                false
            );

        /*
        |--------------------------------------------------------------------------
        | LOGIN ATTEMPT KEYS
        |--------------------------------------------------------------------------
        */

        $lockMinutes = 15;

        $loginIdentifier =
            strtolower(
                trim(
                    $request->username
                )
            ) .
            '|' .
            $request->ip();

        $keyHash =
            sha1(
                $loginIdentifier
            );

        $attemptsKey =
            'qrpass_login_attempts_' .
            $keyHash;

        $lockKey =
            'qrpass_login_lock_' .
            $keyHash;

        /*
        |--------------------------------------------------------------------------
        | CHECK LOGIN LOCK
        |--------------------------------------------------------------------------
        */

        $lockedUntil =
            Cache::get(
                $lockKey
            );

        if ($lockedUntil) {
            $lockedUntilTimestamp =
                (int) $lockedUntil;

            if (
                $lockedUntilTimestamp >
                now()->timestamp
            ) {
                $remainingSeconds =
                    $lockedUntilTimestamp -
                    now()->timestamp;

                $remainingMinutes =
                    max(
                        1,
                        (int) ceil(
                            $remainingSeconds /
                            60
                        )
                    );

                return response()->json([
                    'message' =>
                        'Too many failed login attempts. Please try again in ' .
                        $remainingMinutes .
                        ' minute' .
                        (
                            $remainingMinutes === 1
                                ? ''
                                : 's'
                        ) .
                        '.',
                ], 429);
            }

            Cache::forget(
                $lockKey
            );
        }

        /*
        |--------------------------------------------------------------------------
        | FIND USER
        |--------------------------------------------------------------------------
        */

        $user =
            User::where(
                'username',
                $request->username
            )->first();

        /*
        |--------------------------------------------------------------------------
        | INVALID USERNAME / PASSWORD
        |--------------------------------------------------------------------------
        */

        if (
            !$user ||
            !Hash::check(
                $request->password,
                $user->password
            )
        ) {
            $failedAttempts =
                (int) Cache::get(
                    $attemptsKey,
                    0
                ) + 1;

            /*
            |--------------------------------------------------------------------------
            | AUDIT FAILED LOGIN
            |--------------------------------------------------------------------------
            */

            AuditLogger::log(
                action:
                    'login_failed',

                description:
                    'Failed login attempt for username "' .
                    $request->username .
                    '".',

                eventType:
                    'authentication',

                module:
                    'Authentication',

                status:
                    'failed',

                metadata: [
                    'username' =>
                        $request->username,

                    'failed_attempt' =>
                        $failedAttempts,

                    'max_attempts' =>
                        $maxAttempts,

                    'ip_address' =>
                        $request->ip(),
                ],

                user:
                    $user
            );

            /*
            |--------------------------------------------------------------------------
            | MAX ATTEMPTS REACHED
            |--------------------------------------------------------------------------
            */

            if (
                $failedAttempts >=
                $maxAttempts
            ) {
                $lockUntil =
                    now()->addMinutes(
                        $lockMinutes
                    );

                Cache::put(
                    $lockKey,
                    $lockUntil->timestamp,
                    $lockUntil
                );

                Cache::forget(
                    $attemptsKey
                );

                AuditLogger::log(
                    action:
                        'login_locked',

                    description:
                        'Login temporarily locked after reaching the maximum failed login attempts for username "' .
                        $request->username .
                        '".',

                    eventType:
                        'authentication',

                    module:
                        'Authentication',

                    status:
                        'warning',

                    metadata: [
                        'username' =>
                            $request->username,

                        'max_attempts' =>
                            $maxAttempts,

                        'lock_minutes' =>
                            $lockMinutes,

                        'locked_until' =>
                            $lockUntil
                                ->toDateTimeString(),

                        'ip_address' =>
                            $request->ip(),
                    ],

                    user:
                        $user
                );

                return response()->json([
                    'message' =>
                        'Too many failed login attempts. Login has been temporarily locked for ' .
                        $lockMinutes .
                        ' minutes.',
                ], 429);
            }

            /*
            |--------------------------------------------------------------------------
            | STORE ATTEMPT COUNT
            |--------------------------------------------------------------------------
            */

            Cache::put(
                $attemptsKey,
                $failedAttempts,
                now()->addMinutes(
                    $lockMinutes
                )
            );

            $remainingAttempts =
                max(
                    0,
                    $maxAttempts -
                    $failedAttempts
                );

            return response()->json([
                'message' =>
                    'Invalid username or password. ' .
                    $remainingAttempts .
                    ' login attempt' .
                    (
                        $remainingAttempts === 1
                            ? ''
                            : 's'
                    ) .
                    ' remaining.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | ACCOUNT STATUS
        |--------------------------------------------------------------------------
        */

        if (
            strtolower(
                (string) $user->status
            ) !==
            'approved'
        ) {
            return response()->json([
                'message' =>
                    'This account is currently disabled.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | CORRECT PASSWORD - RESET FAILED ATTEMPTS
        |--------------------------------------------------------------------------
        */

        Cache::forget(
            $attemptsKey
        );

        Cache::forget(
            $lockKey
        );

        /*
        |--------------------------------------------------------------------------
        | TWO-FACTOR AUTHENTICATION
        |--------------------------------------------------------------------------
        */

        if ($twoFactorEnabled) {
            /*
            |--------------------------------------------------------------------------
            | GENERATE 6-DIGIT CODE
            |--------------------------------------------------------------------------
            */

            $code =
                str_pad(
                    (string) random_int(
                        0,
                        999999
                    ),
                    6,
                    '0',
                    STR_PAD_LEFT
                );

            /*
            |--------------------------------------------------------------------------
            | CREATE TEMPORARY CHALLENGE TOKEN
            |--------------------------------------------------------------------------
            */

            $challengeToken =
                bin2hex(
                    random_bytes(32)
                );

            $challengeKey =
                'qrpass_2fa_' .
                sha1(
                    $challengeToken
                );

            /*
            |--------------------------------------------------------------------------
            | CACHE 2FA CHALLENGE FOR 10 MINUTES
            |--------------------------------------------------------------------------
            */

            Cache::put(
                $challengeKey,
                [
                    'user_id' =>
                        $user->id,

                    'code' =>
                        Hash::make(
                            $code
                        ),

                    'attempts' =>
                        0,
                ],
                now()->addMinutes(10)
            );

            /*
            |--------------------------------------------------------------------------
            | SEND 2FA EMAIL
            |--------------------------------------------------------------------------
            */

            $this->sendTwoFactorEmail(
                $user,
                $code
            );

            /*
            |--------------------------------------------------------------------------
            | AUDIT 2FA CODE
            |--------------------------------------------------------------------------
            */

            AuditLogger::log(
                action:
                    'two_factor_code_sent',

                description:
                    'A two-factor authentication code was sent for ' .
                    $user->name .
                    '.',

                eventType:
                    'authentication',

                module:
                    'Two-Factor Authentication',

                status:
                    'success',

                metadata: [
                    'user_id' =>
                        $user->id,

                    'username' =>
                        $user->username,

                    'role' =>
                        $user->role,
                ],

                user:
                    $user
            );

            /*
            |--------------------------------------------------------------------------
            | DO NOT CREATE LOGIN TOKEN YET
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'message' =>
                    'A verification code has been sent to your registered email address.',

                'requires_2fa' =>
                    true,

                'challenge_token' =>
                    $challengeToken,
            ], 202);
        }

        /*
        |--------------------------------------------------------------------------
        | NORMAL LOGIN - 2FA DISABLED
        |--------------------------------------------------------------------------
        */

        $token =
            $user
                ->createToken(
                    'qrpass-token'
                )
                ->plainTextToken;

        AuditLogger::log(
            action:
                'login',

            description:
                "{$user->name} logged in successfully.",

            eventType:
                'authentication',

            module:
                'Authentication',

            status:
                'success',

            metadata: [
                'role' =>
                    $user->role,

                'two_factor' =>
                    false,
            ],

            user:
                $user
        );

        return response()->json([
            'message' =>
                'Login successful.',

            'requires_2fa' =>
                false,

            'token' =>
                $token,

            'user' =>
                $this->userPayload(
                    $user
                ),
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY TWO-FACTOR AUTHENTICATION
    |--------------------------------------------------------------------------
    */

    public function verifyTwoFactor(
        Request $request
    ) {
        $request->validate([
            'challenge_token' =>
                'required|string',

            'code' =>
                'required|digits:6',
        ]);

        /*
        |--------------------------------------------------------------------------
        | LOAD CHALLENGE
        |--------------------------------------------------------------------------
        */

        $challengeKey =
            'qrpass_2fa_' .
            sha1(
                $request->challenge_token
            );

        $challenge =
            Cache::get(
                $challengeKey
            );

        if (!$challenge) {
            return response()->json([
                'message' =>
                    'The verification code has expired. Please log in again.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | FIND USER
        |--------------------------------------------------------------------------
        */

        $user =
            User::find(
                $challenge['user_id'] ??
                null
            );

        if (!$user) {
            Cache::forget(
                $challengeKey
            );

            return response()->json([
                'message' =>
                    'Unable to verify this login request.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | ACCOUNT MUST STILL BE ACTIVE
        |--------------------------------------------------------------------------
        */

        if (
            strtolower(
                (string) $user->status
            ) !==
            'approved'
        ) {
            Cache::forget(
                $challengeKey
            );

            return response()->json([
                'message' =>
                    'This account is currently disabled.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | VERIFY CODE
        |--------------------------------------------------------------------------
        */

        if (
            !Hash::check(
                $request->code,
                $challenge['code']
            )
        ) {
            $attempts =
                (int) (
                    $challenge['attempts'] ??
                    0
                ) + 1;

            /*
            |--------------------------------------------------------------------------
            | MAX 2FA CODE ATTEMPTS
            |--------------------------------------------------------------------------
            */

            if ($attempts >= 5) {
                Cache::forget(
                    $challengeKey
                );

                AuditLogger::log(
                    action:
                        'two_factor_failed',

                    description:
                        'Two-factor authentication failed too many times for ' .
                        $user->name .
                        '.',

                    eventType:
                        'authentication',

                    module:
                        'Two-Factor Authentication',

                    status:
                        'failed',

                    metadata: [
                        'user_id' =>
                            $user->id,

                        'username' =>
                            $user->username,

                        'attempts' =>
                            $attempts,
                    ],

                    user:
                        $user
                );

                return response()->json([
                    'message' =>
                        'Too many incorrect verification codes. Please log in again.',
                ], 429);
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE FAILED 2FA ATTEMPTS
            |--------------------------------------------------------------------------
            */

            $challenge['attempts'] =
                $attempts;

            Cache::put(
                $challengeKey,
                $challenge,
                now()->addMinutes(10)
            );

            $remaining =
                5 -
                $attempts;

            return response()->json([
                'message' =>
                    'Incorrect verification code. ' .
                    $remaining .
                    ' attempt' .
                    (
                        $remaining === 1
                            ? ''
                            : 's'
                    ) .
                    ' remaining.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | CORRECT CODE - DELETE CHALLENGE
        |--------------------------------------------------------------------------
        */

        Cache::forget(
            $challengeKey
        );

        /*
        |--------------------------------------------------------------------------
        | CREATE SANCTUM TOKEN
        |--------------------------------------------------------------------------
        */

        $token =
            $user
                ->createToken(
                    'qrpass-token'
                )
                ->plainTextToken;

        /*
        |--------------------------------------------------------------------------
        | AUDIT SUCCESSFUL 2FA LOGIN
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'login',

            description:
                "{$user->name} logged in successfully using two-factor authentication.",

            eventType:
                'authentication',

            module:
                'Authentication',

            status:
                'success',

            metadata: [
                'role' =>
                    $user->role,

                'two_factor' =>
                    true,
            ],

            user:
                $user
        );

        return response()->json([
            'message' =>
                'Verification successful. Login completed.',

            'requires_2fa' =>
                false,

            'token' =>
                $token,

            'user' =>
                $this->userPayload(
                    $user
                ),
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | LOGOUT
    |--------------------------------------------------------------------------
    */

    public function logout(
        Request $request
    ) {
        $user =
            $request->user();

        if (!$user) {
            return response()->json([
                'message' =>
                    'Unauthenticated.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG - LOGOUT
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'logout',

            description:
                "{$user->name} logged out successfully.",

            eventType:
                'authentication',

            module:
                'Authentication',

            status:
                'success',

            metadata: [
                'role' =>
                    $user->role,
            ],

            user:
                $user
        );

        /*
        |--------------------------------------------------------------------------
        | DELETE CURRENT TOKEN
        |--------------------------------------------------------------------------
        */

        $currentToken =
            $user->currentAccessToken();

        if ($currentToken) {
            $currentToken->delete();
        }

        return response()->json([
            'message' =>
                'Logged out successfully.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | REGISTER
    |--------------------------------------------------------------------------
    */

    public function register(
        Request $request
    ) {
        $request->validate([
            'name' =>
                'required|string|max:255',

            'email' =>
                'required|email|unique:users,email',

            'username' =>
                'required|string|max:255|unique:users,username',

            'role' =>
                'required|in:student,security,pco',

            'password' =>
                'required|string|min:6',
        ]);

        $user =
            User::create([
                'name' =>
                    $request->name,

                'email' =>
                    strtolower(
                        trim(
                            $request->email
                        )
                    ),

                'username' =>
                    $request->username,

                'role' =>
                    $request->role,

                'status' =>
                    'approved',

                'password' =>
                    Hash::make(
                        $request->password
                    ),
            ]);

        return response()->json([
            'message' =>
                'Registration successful.',

            'user' =>
                $this->userPayload(
                    $user
                ),
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | REQUEST PASSWORD RESET CODE
    |--------------------------------------------------------------------------
    */

    public function requestPasswordReset(
        Request $request
    ) {
        $request->validate([
            'email' =>
                'required|email',
        ]);

        $email =
            strtolower(
                trim(
                    $request->email
                )
            );

        $user =
            User::whereRaw(
                'LOWER(email) = ?',
                [$email]
            )->first();

        /*
        |--------------------------------------------------------------------------
        | DO NOT REVEAL EMAIL EXISTENCE
        |--------------------------------------------------------------------------
        */

        if (!$user) {
            return response()->json([
                'message' =>
                    'If the email is registered, a verification code has been sent.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | GENERATE CODE
        |--------------------------------------------------------------------------
        */

        $code =
            str_pad(
                (string) random_int(
                    0,
                    999999
                ),
                6,
                '0',
                STR_PAD_LEFT
            );

        /*
        |--------------------------------------------------------------------------
        | CACHE RESET DATA
        |--------------------------------------------------------------------------
        */

        $cacheKey =
            'qrpass_password_reset_' .
            sha1(
                $email
            );

        Cache::put(
            $cacheKey,
            [
                'user_id' =>
                    $user->id,

                'code' =>
                    Hash::make(
                        $code
                    ),
            ],
            now()->addMinutes(10)
        );

        /*
        |--------------------------------------------------------------------------
        | SEND EMAIL
        |--------------------------------------------------------------------------
        */

        $this->sendPasswordResetEmail(
            $user,
            $code
        );

        return response()->json([
            'message' =>
                'If the email is registered, a verification code has been sent.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY PASSWORD RESET CODE
    |--------------------------------------------------------------------------
    */

    public function verifyPasswordResetCode(
        Request $request
    ) {
        $request->validate([
            'email' =>
                'required|email',

            'code' =>
                'required|digits:6',
        ]);

        $email =
            strtolower(
                trim(
                    $request->email
                )
            );

        $cacheKey =
            'qrpass_password_reset_' .
            sha1(
                $email
            );

        $resetData =
            Cache::get(
                $cacheKey
            );

        if (!$resetData) {
            return response()->json([
                'message' =>
                    'The verification code is invalid or has expired.',
            ], 422);
        }

        if (
            !Hash::check(
                $request->code,
                $resetData['code']
            )
        ) {
            return response()->json([
                'message' =>
                    'The verification code is incorrect.',
            ], 422);
        }

        return response()->json([
            'message' =>
                'Verification code confirmed.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | RESET PASSWORD
    |--------------------------------------------------------------------------
    */

    public function resetPassword(
        Request $request
    ) {
        $request->validate([
            'email' =>
                'required|email',

            'code' =>
                'required|digits:6',

            'password' =>
                'required|string|min:6|confirmed',
        ]);

        $email =
            strtolower(
                trim(
                    $request->email
                )
            );

        $cacheKey =
            'qrpass_password_reset_' .
            sha1(
                $email
            );

        $resetData =
            Cache::get(
                $cacheKey
            );

        if (!$resetData) {
            return response()->json([
                'message' =>
                    'The verification code is invalid or has expired.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | VERIFY RESET CODE AGAIN
        |--------------------------------------------------------------------------
        */

        if (
            !Hash::check(
                $request->code,
                $resetData['code']
            )
        ) {
            return response()->json([
                'message' =>
                    'The verification code is incorrect.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | FIND USER
        |--------------------------------------------------------------------------
        */

        $user =
            User::find(
                $resetData['user_id']
            );

        if (
            !$user ||
            strtolower(
                $user->email
            ) !==
            $email
        ) {
            return response()->json([
                'message' =>
                    'Unable to reset the password.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE PASSWORD
        |--------------------------------------------------------------------------
        */

        $user->password =
            Hash::make(
                $request->password
            );

        $user->save();

        /*
        |--------------------------------------------------------------------------
        | REVOKE ALL CURRENT TOKENS
        |--------------------------------------------------------------------------
        */

        $user
            ->tokens()
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | DELETE USED RESET CODE
        |--------------------------------------------------------------------------
        */

        Cache::forget(
            $cacheKey
        );

        AuditLogger::log(
            action:
                'password_reset',

            description:
                "{$user->name} successfully reset their QRPass password.",

            eventType:
                'authentication',

            module:
                'Authentication',

            status:
                'success',

            metadata: [
                'user_id' =>
                    $user->id,

                'username' =>
                    $user->username,
            ],

            user:
                $user
        );

        return response()->json([
            'message' =>
                'Password changed successfully. You can now sign in using your new password.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | USER RESPONSE PAYLOAD
    |--------------------------------------------------------------------------
    */

    private function userPayload(
        User $user
    ): array {
        return [
            'id' =>
                $user->id,

            'name' =>
                $user->name,

            'email' =>
                $user->email,

            'username' =>
                $user->username,

            'role' =>
                $user->role,

            'status' =>
                $user->status,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | SEND TWO-FACTOR EMAIL
    |--------------------------------------------------------------------------
    */

    private function sendTwoFactorEmail(
        User $user,
        string $code
    ): void {
        $safeName =
            e(
                ucwords(
                    strtolower(
                        trim(
                            $user->name
                        )
                    )
                )
            );

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        QRPass Login Verification
    </title>
</head>

<body
    style="
        margin:0;
        padding:0;
        background:#f4f6fa;
        font-family:Arial, Helvetica, sans-serif;
        color:#1f2937;
    "
>

<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    border="0"
    style="
        background:#f4f6fa;
        padding:40px 15px;
    "
>
    <tr>
        <td align="center">

            <table
                width="100%"
                cellpadding="0"
                cellspacing="0"
                border="0"
                style="
                    max-width:560px;
                    background:#ffffff;
                    border-radius:12px;
                    overflow:hidden;
                    border:1px solid #e5e7eb;
                    box-shadow:0 4px 14px rgba(0,0,0,0.06);
                "
            >

                <tr>
                    <td
                        align="center"
                        style="
                            background:#003087;
                            padding:28px 30px;
                        "
                    >
                        <div
                            style="
                                font-size:26px;
                                font-weight:800;
                                letter-spacing:3px;
                                color:#ffffff;
                            "
                        >
                            QRPASS
                        </div>

                        <div
                            style="
                                margin-top:6px;
                                font-size:12px;
                                color:#dbeafe;
                                letter-spacing:1px;
                            "
                        >
                            UNIVERSITY OF CEBU – MAIN CAMPUS
                        </div>
                    </td>
                </tr>

                <tr>
                    <td
                        style="
                            padding:36px;
                        "
                    >
                        <h2
                            style="
                                margin-top:0;
                                color:#0d1b3e;
                            "
                        >
                            Login Verification
                        </h2>

                        <p>
                            Hello <strong>{$safeName}</strong>,
                        </p>

                        <p
                            style="
                                color:#4b5563;
                                line-height:1.7;
                            "
                        >
                            A login attempt was made using your QRPass account.
                            Enter the verification code below to complete your login.
                        </p>

                        <div
                            style="
                                margin:24px 0;
                                padding:24px;
                                text-align:center;
                                font-size:36px;
                                font-weight:800;
                                letter-spacing:8px;
                                color:#003087;
                                background:#f0f5ff;
                                border:1px solid #cbd9f4;
                                border-radius:10px;
                            "
                        >
                            {$code}
                        </div>

                        <p
                            style="
                                color:#64748b;
                                font-size:13px;
                            "
                        >
                            This verification code expires in 10 minutes.
                        </p>

                        <p
                            style="
                                color:#64748b;
                                font-size:13px;
                            "
                        >
                            If you did not attempt to log in, you can safely ignore this email.
                        </p>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
HTML;

        Mail::html(
            $html,
            function ($message) use (
                $user
            ) {
                $message
                    ->to(
                        $user->email
                    )
                    ->subject(
                        'QRPass Login Verification Code'
                    );
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SEND PASSWORD RESET EMAIL
    |--------------------------------------------------------------------------
    */

    private function sendPasswordResetEmail(
        User $user,
        string $code
    ): void {
        $safeName =
            e(
                ucwords(
                    strtolower(
                        trim(
                            $user->name
                        )
                    )
                )
            );

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        QRPass Password Reset
    </title>
</head>

<body
    style="
        margin:0;
        padding:0;
        background:#f4f6fa;
        font-family:Arial, Helvetica, sans-serif;
        color:#1f2937;
    "
>

<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    border="0"
    style="
        background:#f4f6fa;
        padding:40px 15px;
    "
>
    <tr>
        <td align="center">

            <table
                width="100%"
                cellpadding="0"
                cellspacing="0"
                border="0"
                style="
                    max-width:560px;
                    background:#ffffff;
                    border-radius:12px;
                    overflow:hidden;
                    border:1px solid #e5e7eb;
                    box-shadow:0 4px 14px rgba(0,0,0,0.06);
                "
            >

                <tr>
                    <td
                        align="center"
                        style="
                            background:#003087;
                            padding:28px 30px;
                        "
                    >
                        <div
                            style="
                                font-size:26px;
                                font-weight:800;
                                letter-spacing:3px;
                                color:#ffffff;
                            "
                        >
                            QRPASS
                        </div>

                        <div
                            style="
                                margin-top:6px;
                                font-size:12px;
                                color:#dbeafe;
                                letter-spacing:1px;
                            "
                        >
                            UNIVERSITY OF CEBU – MAIN CAMPUS
                        </div>
                    </td>
                </tr>

                <tr>
                    <td
                        style="
                            padding:36px;
                        "
                    >
                        <h2
                            style="
                                margin-top:0;
                                color:#0d1b3e;
                            "
                        >
                            Password Reset Verification
                        </h2>

                        <p>
                            Hello <strong>{$safeName}</strong>,
                        </p>

                        <p
                            style="
                                color:#4b5563;
                                line-height:1.7;
                            "
                        >
                            We received a request to reset the password for your
                            QRPass account. Use the verification code below to continue.
                        </p>

                        <div
                            style="
                                margin:24px 0;
                                padding:24px;
                                text-align:center;
                                font-size:36px;
                                font-weight:800;
                                letter-spacing:8px;
                                color:#003087;
                                background:#f0f5ff;
                                border:1px solid #cbd9f4;
                                border-radius:10px;
                            "
                        >
                            {$code}
                        </div>

                        <p
                            style="
                                color:#64748b;
                                font-size:13px;
                            "
                        >
                            This verification code expires in 10 minutes.
                        </p>

                        <p
                            style="
                                color:#64748b;
                                font-size:13px;
                            "
                        >
                            If you did not request a password reset,
                            your password will remain unchanged.
                        </p>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
HTML;

        Mail::html(
            $html,
            function ($message) use (
                $user
            ) {
                $message
                    ->to(
                        $user->email
                    )
                    ->subject(
                        'QRPass Password Reset Verification Code'
                    );
            }
        );
    }
}