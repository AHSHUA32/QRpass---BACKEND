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

        // QRPASS_REVOKED_ID_MESSAGES_V182
        // A revoked ID always gets this response, even during a prior lockout.
        // Other users keep the existing login rate limiter unchanged.
        $loginId = trim((string) $request->input('username',''));
        if (
            \Illuminate\Support\Facades\Schema::hasTable('qrpass_blocked_identifiers')
            && \Illuminate\Support\Facades\DB::table('qrpass_blocked_identifiers')
                ->where('kind', 'id')
                ->where('identifier_hash', hash('sha256', $loginId))
                ->exists()
        ) {
            return response()->json([
                'message' => 'This ID number has been revoked and can no longer access the QRPass system.'
            ], 403);
        }


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
        // QRPASS_PERMANENT_ACCESS_V91
        if ($user->qrpass_revoked_at !== null) {
            return response()->json(['message' => 'This account has been permanently revoked. Contact the System Administrator.'], 403);
        }


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
        // QRPASS_PERMANENT_ACCESS_V91
        if ($user->qrpass_revoked_at !== null) {
            return response()->json(['message' => 'This account has been permanently revoked. Contact the System Administrator.'], 403);
        }


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
        // QRPASS_ATOMIC_REVOKE_DENYLIST_V180
        $idNumber = trim((string) $request->input('username', ''));
        $emailAddress = strtolower(trim((string) $request->input('email', '')));
        if (
            \Illuminate\Support\Facades\DB::table('qrpass_blocked_identifiers')
                ->where('kind', 'id')->where('identifier_hash', hash('sha256', $idNumber))->exists()
            || \Illuminate\Support\Facades\DB::table('qrpass_blocked_identifiers')
                ->where('kind', 'email')->where('identifier_hash', hash('sha256', $emailAddress))->exists()
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'username' => 'This ID number or email address is not eligible for registration.'
            ]);
        }

        
        // QRPASS_REVOKED_ID_MESSAGES_V182
        $blockedRegistrationId = trim((string)$request->input('username', ''));
        $blockedRegistrationEmail = strtolower(trim((string)$request->input('email', '')));
        if (
            \Illuminate\Support\Facades\Schema::hasTable('qrpass_blocked_identifiers') &&
            (
                \Illuminate\Support\Facades\DB::table('qrpass_blocked_identifiers')
                    ->where('kind', 'id')
                    ->where('identifier_hash', hash('sha256', $blockedRegistrationId))->exists()
                ||
                \Illuminate\Support\Facades\DB::table('qrpass_blocked_identifiers')
                    ->where('kind', 'email')
                    ->where('identifier_hash', hash('sha256', $blockedRegistrationEmail))->exists()
            )
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'username' => 'This ID number or email address has been revoked and cannot be used to register or access the QRPass system.'
            ]);
        }

        // QRPASS_GMAIL_DIAGNOSTICS_V2
        $request->merge(['email' => strtolower(trim((string) $request->input('email', '')))]);
        $request->validate([
            'name' =>
                'required|string|max:255|not_regex:/[0-9]/',

            'email' =>
                'required|email|regex:/^[^@\s]+@gmail\.com$/i|unique:users,email',

            'username' =>
                'required|digits_between:1,20|unique:users,username',

            'role' =>
                'required|in:student',

            'college' =>
                'nullable|required_unless:year_level,Senior High School|string|in:College of Allied Engineering,College of Arts and Sciences,College of Business and Accountancy,College of Computer Studies,College of Criminal Justice,College of Customs Administration,College of Hospitality Management,College of Nursing,College of Social Work,College of Teacher Education',

            'year_level' =>
                'required|string|in:Senior High School,1st Year,2nd Year,3rd Year,4th Year',

            'contact_number' =>
                'nullable|string|max:20|regex:/^[0-9]+$/',

            'password' =>
                'required|string|min:6',
        ], [
            'email.required' => 'Please enter your Gmail address.',
            'email.email' => 'Please enter a valid Gmail address.',
            'email.regex' => 'Please use a valid @gmail.com address.',
            'email.unique' => 'This Gmail address is already registered.',
        ]);

        // QRPASS_GMAIL_VERIFICATION_V1
        $request->validate(['verification_code' => 'nullable|digits:6']);
        $gmail = strtolower(trim((string) $request->email));
        $payload = $request->only(['name','username','password','role','college','year_level','contact_number']);
        $fingerprint = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $key = 'qrpass_registration_gmail_' . hash('sha256', $gmail);
        $limiter = \Illuminate\Support\Facades\RateLimiter::class;
        if (!$request->filled('verification_code')) {
            $mailKey = $key . ':send';
            $ipKey = 'qrpass_registration_send_ip_' . hash('sha256', (string) $request->ip());
            abort_if($limiter::tooManyAttempts($mailKey, 3) || $limiter::tooManyAttempts($ipKey, 15), 429, 'Too many code requests. Try again later.');
            // QRPASS_GMAIL_RESEND_20S_V1
            abort_unless(Cache::add($key . ':resend-cooldown', true, 20), 429, 'Please wait 20 seconds before requesting another verification code.');
            $limiter::hit($mailKey, 600); $limiter::hit($ipKey, 600);
            $code = (string) random_int(100000, 999999);
            Cache::put($key, ['code' => Hash::make($code), 'fingerprint' => $fingerprint, 'expires' => now()->addMinutes(10)->timestamp], now()->addMinutes(10));
            try {
                abort_if(in_array(config('mail.default'), ['log','array','null'], true), 503, 'Email delivery is not configured.');
                Mail::raw("Your QRPass account verification code is: " . $code . ". It expires in 10 minutes. Do not share this code.", function ($mail) use ($gmail) {
                    $mail->to($gmail)->subject('QRPass - Verify your Gmail');
                });
            } catch (\Throwable $e) {
                Cache::forget($key);
                $detail = $e->getMessage();
                $reason = match (true) {
                    in_array(config('mail.default'), ['log','array','null'], true) => 'mailer_not_configured',
                    preg_match('/\b535\b|5\.7\.8|authentication failed|username and password not accepted/i', $detail) === 1 => 'smtp_authentication_failed',
                    preg_match('/\b550\b[^\r\n]*5\.1\.1|5\.1\.1[^\r\n]*does not exist/i', $detail) === 1 => 'recipient_rejected',
                    preg_match('/timed?\s*out|connection refused|could not connect|failed to connect|network is unreachable/i', $detail) === 1 => 'smtp_connection_failed',
                    default => 'mail_delivery_failed',
                };
                \Illuminate\Support\Facades\Log::warning('QRPass registration mail failed', ['reason' => $reason, 'exception_type' => get_class($e)]);
                if ($reason === 'recipient_rejected') {
                    return response()->json(['message' => 'The mail server rejected this Gmail address. Check the address and try again.'], 422);
                }
                return response()->json(['message' => 'Verification email could not be sent. Please try again later or contact the administrator.'], 503);
            }
            return response()->json(['verification_required' => true, 'message' => 'Verification code sent.'], 202);
        }
        $lock = Cache::lock($key . ':verify-lock', 30);
        abort_unless($lock->get(), 429, 'Verification is in progress. Try again.');
        try {
            $attemptKey = $key . ':attempt';
            $ipAttemptKey = 'qrpass_registration_verify_ip_' . hash('sha256', (string) $request->ip());
            abort_if($limiter::tooManyAttempts($attemptKey, 5) || $limiter::tooManyAttempts($ipAttemptKey, 30), 429, 'Too many verification attempts. Try again later.');
            $limiter::hit($attemptKey, 600); $limiter::hit($ipAttemptKey, 600);
            $challenge = Cache::get($key);
            abort_unless($challenge && $challenge['expires'] > now()->timestamp && hash_equals($challenge['fingerprint'], $fingerprint) && Hash::check((string) $request->verification_code, $challenge['code']), 422, 'Invalid or expired code, or registration details changed. Request a new code if needed.');
            Cache::forget($key);
        } finally { $lock->release(); }
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
                    'student',

                'college' =>
                    $request->year_level === 'Senior High School' ? null : $request->college,

                'year_level' =>
                    $request->year_level,

                'contact_number' =>
                    $request->contact_number,

                'status' =>
                    'approved',

                'password' =>
                    Hash::make(
                        $request->password
                    ),
            ]);

        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'email_verified_at')) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>QRpass - Login Verification</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fc;font-family:Arial,Helvetica,sans-serif;color:#172b4d;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f7fc;">
<tr><td align="center" style="padding:36px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:540px;background:#ffffff;border:1px solid #dce5f3;border-radius:18px;overflow:hidden;">

<tr>
<td align="center" style="background:#173b7a;padding:26px 20px;">
<img src="QRpass_LOGO_SRC" alt="QRpass Shield" width="65" height="65" style="display:block;width:65px;height:65px;object-fit:contain;background:#ffffff;border-radius:12px;padding:7px;box-sizing:border-box;">
<div style="margin-top:13px;font-size:27px;font-weight:700;color:#ffffff;letter-spacing:-0.5px;">
QRpass
</div>
</td>
</tr>

<tr>
<td style="padding:30px 28px;">
<h2 style="font-size:21px;line-height:1.35;margin:0 0 20px;color:#17336d;">
Login Verification
</h2>

<p style="font-size:14px;line-height:1.7;color:#334155;">
Hello <strong>{$safeName}</strong>,
</p>

<p style="font-size:14px;line-height:1.8;color:#475569;">
A sign-in request was made for your QRpass account. Enter the verification code below to complete your login.
</p>

<div style="margin:24px 0;padding:22px 12px;text-align:center;background:#edf2ff;border:1px solid #c7d5fb;border-radius:12px;">
<div style="font-size:11px;font-weight:700;letter-spacing:1px;color:#64748b;">
YOUR VERIFICATION CODE
</div>
<div style="margin-top:10px;font-size:34px;font-weight:800;letter-spacing:6px;color:#173b7a;line-height:1.3;">
{$code}
</div>
</div>

<p style="font-size:13px;line-height:1.7;color:#64748b;">
This verification code expires in <strong>10 minutes</strong>.
</p>

<div style="margin-top:22px;padding-top:17px;border-top:1px solid #e2e8f0;">
<p style="font-size:12px;line-height:1.7;color:#64748b;margin:0;">
If you did not attempt to log in, you can safely ignore this email.
</p>
</div>

</td>
</tr>
</table>

<p style="font-size:11px;color:#94a3b8;margin-top:20px;">
Automated security email from QRpass
</p>

</td></tr>
</table>
</body>
</html>
HTML;

        Mail::html(
            $html,
            function ($message) use ($user, $html) {
                $logoCid = $message->embed(
                    public_path('email/qrpass-shield-only.png')
                );

                $message->getSymfonyMessage()->html(
                    str_replace('QRpass_LOGO_SRC', $logoCid, $html)
                );
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>QRpass - Password Reset Verification</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fc;font-family:Arial,Helvetica,sans-serif;color:#172b4d;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f7fc;">
<tr><td align="center" style="padding:36px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:540px;background:#ffffff;border:1px solid #dce5f3;border-radius:18px;overflow:hidden;">

<tr>
<td align="center" style="background:#173b7a;padding:26px 20px;">
<img src="QRpass_LOGO_SRC" alt="QRpass Shield" width="65" height="65" style="display:block;width:65px;height:65px;object-fit:contain;background:#ffffff;border-radius:12px;padding:7px;box-sizing:border-box;">
<div style="margin-top:13px;font-size:27px;font-weight:700;color:#ffffff;letter-spacing:-0.5px;">
QRpass
</div>
</td>
</tr>

<tr>
<td style="padding:30px 28px;">
<h2 style="font-size:21px;line-height:1.35;margin:0 0 20px;color:#17336d;">
Password Reset Verification
</h2>

<p style="font-size:14px;line-height:1.7;color:#334155;">
Hello <strong>{$safeName}</strong>,
</p>

<p style="font-size:14px;line-height:1.8;color:#475569;">
We received a request to reset your QRpass account password. Enter the verification code below to continue.
</p>

<div style="margin:24px 0;padding:22px 12px;text-align:center;background:#edf2ff;border:1px solid #c7d5fb;border-radius:12px;">
<div style="font-size:11px;font-weight:700;letter-spacing:1px;color:#64748b;">
YOUR VERIFICATION CODE
</div>
<div style="margin-top:10px;font-size:34px;font-weight:800;letter-spacing:6px;color:#173b7a;line-height:1.3;">
{$code}
</div>
</div>

<p style="font-size:13px;line-height:1.7;color:#64748b;">
This verification code expires in <strong>10 minutes</strong>.
</p>

<div style="margin-top:22px;padding-top:17px;border-top:1px solid #e2e8f0;">
<p style="font-size:12px;line-height:1.7;color:#64748b;margin:0;">
If you did not request a password reset, your password will remain unchanged.
</p>
</div>

</td>
</tr>
</table>

<p style="font-size:11px;color:#94a3b8;margin-top:20px;">
Automated security email from QRpass
</p>

</td></tr>
</table>
</body>
</html>
HTML;

        Mail::html(
            $html,
            function ($message) use ($user, $html) {
                $logoCid = $message->embed(
                    public_path('email/qrpass-shield-only.png')
                );

                $message->getSymfonyMessage()->html(
                    str_replace('QRpass_LOGO_SRC', $logoCid, $html)
                );
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