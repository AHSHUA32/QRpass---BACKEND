<?php

namespace App\Http\Controllers;

use App\Models\User;
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
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where(
            'username',
            $request->username
        )->first();

        if (
            !$user ||
            !Hash::check(
                $request->password,
                $user->password
            )
        ) {
            return response()->json([
                'message' => 'Invalid username or password.',
            ], 401);
        }

        $token = $user
            ->createToken('qrpass-token')
            ->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',

            'token' => $token,

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
                'status' => $user->status,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTER
    |--------------------------------------------------------------------------
    */

    public function register(Request $request)
    {
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

        $user = User::create([
            'name' =>
                $request->name,

            'email' =>
                strtolower(
                    trim($request->email)
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

            'user' => [
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
            ],
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

        $email = strtolower(
            trim($request->email)
        );

        $user = User::whereRaw(
            'LOWER(email) = ?',
            [$email]
        )->first();

        /*
        |--------------------------------------------------------------------------
        | Do not reveal whether email exists
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
        | Generate 6-digit verification code
        |--------------------------------------------------------------------------
        */

        $code = str_pad(
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
        | Cache Key
        |--------------------------------------------------------------------------
        */

        $cacheKey =
            'qrpass_password_reset_' .
            sha1($email);

        /*
        |--------------------------------------------------------------------------
        | Store hashed verification code for 10 minutes
        |--------------------------------------------------------------------------
        */

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
        | Professional QRPass Password Reset Email
        |--------------------------------------------------------------------------
        */

        $safeName = e(
    ucwords(
        strtolower(
            trim($user->name)
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
        background-color:#f4f6fa;
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
        background-color:#f4f6fa;
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

                <!-- QRPass Header -->
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

                <!-- UC Color Strip -->
                <tr>
                    <td>

                        <table
                            width="100%"
                            cellpadding="0"
                            cellspacing="0"
                            border="0"
                        >
                            <tr>

                                <td
                                    width="33.33%"
                                    height="5"
                                    style="
                                        background:#003087;
                                    "
                                ></td>

                                <td
                                    width="33.33%"
                                    height="5"
                                    style="
                                        background:#f5c200;
                                    "
                                ></td>

                                <td
                                    width="33.33%"
                                    height="5"
                                    style="
                                        background:#00aeef;
                                    "
                                ></td>

                            </tr>
                        </table>

                    </td>
                </tr>

                <!-- Main Content -->
                <tr>

                    <td
                        style="
                            padding:36px 36px 20px 36px;
                        "
                    >

                        <h2
                            style="
                                margin:0 0 18px 0;
                                color:#0d1b3e;
                                font-size:22px;
                            "
                        >
                            Password Reset Verification
                        </h2>

                        <p
                            style="
                                margin:0 0 15px 0;
                                font-size:14px;
                                line-height:1.7;
                            "
                        >
                            Hello <strong>{$safeName}</strong>,
                        </p>

                        <p
                            style="
                                margin:0 0 24px 0;
                                font-size:14px;
                                line-height:1.7;
                                color:#4b5563;
                            "
                        >
                            We received a request to reset the
                            password for your QRPass account.
                            Use the verification code below
                            to continue.
                        </p>

                        <!-- Verification Code -->
                        <table
                            width="100%"
                            cellpadding="0"
                            cellspacing="0"
                            border="0"
                        >

                            <tr>

                                <td
                                    align="center"
                                    style="
                                        background:#f0f5ff;
                                        border:1px solid #cbd9f4;
                                        border-radius:10px;
                                        padding:25px 20px;
                                    "
                                >

                                    <div
                                        style="
                                            font-size:11px;
                                            text-transform:uppercase;
                                            letter-spacing:2px;
                                            color:#64748b;
                                            margin-bottom:10px;
                                            font-weight:600;
                                        "
                                    >
                                        Verification Code
                                    </div>

                                    <div
                                        style="
                                            font-size:36px;
                                            font-weight:800;
                                            letter-spacing:8px;
                                            color:#003087;
                                        "
                                    >
                                        {$code}
                                    </div>

                                </td>

                            </tr>

                        </table>

                        <!-- Expiration Notice -->
                        <div
                            style="
                                margin-top:22px;
                                padding:14px 16px;
                                background:#fff9db;
                                border:1px solid #f5c200;
                                border-radius:8px;
                                font-size:13px;
                                color:#665500;
                                line-height:1.6;
                            "
                        >
                            <strong>
                                Important:
                            </strong>

                            This verification code will
                            expire in

                            <strong>
                                10 minutes
                            </strong>.
                        </div>

                        <!-- Security Notice -->
                        <p
                            style="
                                margin:24px 0 0 0;
                                font-size:13px;
                                line-height:1.7;
                                color:#6b7280;
                            "
                        >
                            If you did not request a password
                            reset, you can safely ignore this
                            email. Your password will remain
                            unchanged.
                        </p>

                    </td>

                </tr>

                <!-- Footer -->
                <tr>

                    <td
                        align="center"
                        style="
                            padding:24px 30px;
                            background:#f8fafc;
                            border-top:1px solid #e5e7eb;
                        "
                    >

                        <div
                            style="
                                font-size:12px;
                                font-weight:700;
                                color:#003087;
                                margin-bottom:5px;
                            "
                        >
                            QRPass
                        </div>

                        <div
                            style="
                                font-size:11px;
                                color:#6b7280;
                                line-height:1.6;
                            "
                        >
                            Enhancing Campus Security Through
                            QR-Based Item Registration &amp;
                            Verification
                        </div>

                        <div
                            style="
                                margin-top:8px;
                                font-size:10px;
                                color:#9ca3af;
                            "
                        >
                            © 2026 University of Cebu –
                            Main Campus
                            &nbsp;|&nbsp;
                            QRPass V1.0
                        </div>

                    </td>

                </tr>

            </table>

        </td>
    </tr>
</table>

</body>

</html>
HTML;

        /*
        |--------------------------------------------------------------------------
        | Send Password Reset Email
        |--------------------------------------------------------------------------
        */

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

        $email = strtolower(
            trim($request->email)
        );

        $cacheKey =
            'qrpass_password_reset_' .
            sha1($email);

        $resetData =
            Cache::get(
                $cacheKey
            );

        /*
        |--------------------------------------------------------------------------
        | Code expired or does not exist
        |--------------------------------------------------------------------------
        */

        if (!$resetData) {
            return response()->json([
                'message' =>
                    'The verification code is invalid or has expired.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Check Verification Code
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

        $email = strtolower(
            trim($request->email)
        );

        $cacheKey =
            'qrpass_password_reset_' .
            sha1($email);

        $resetData =
            Cache::get(
                $cacheKey
            );

        /*
        |--------------------------------------------------------------------------
        | Code Expired or Missing
        |--------------------------------------------------------------------------
        */

        if (!$resetData) {
            return response()->json([
                'message' =>
                    'The verification code is invalid or has expired.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Code Again
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
        | Find User
        |--------------------------------------------------------------------------
        */

        $user = User::find(
            $resetData['user_id']
        );

        if (
            !$user ||
            strtolower(
                $user->email
            ) !== $email
        ) {
            return response()->json([
                'message' =>
                    'Unable to reset the password.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Update Password
        |--------------------------------------------------------------------------
        */

        $user->password =
            Hash::make(
                $request->password
            );

        $user->save();

        /*
        |--------------------------------------------------------------------------
        | Revoke Existing Login Tokens
        |--------------------------------------------------------------------------
        */

        $user
            ->tokens()
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | Delete Used Verification Code
        |--------------------------------------------------------------------------
        */

        Cache::forget(
            $cacheKey
        );

        return response()->json([
            'message' =>
                'Password changed successfully. You can now sign in using your new password.',
        ]);
    }
}