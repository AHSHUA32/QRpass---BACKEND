<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | GET SYSTEM SETTINGS
    |--------------------------------------------------------------------------
    */

    public function show()
    {
        $settings =
            SystemSetting::firstOrCreate(
                ['id' => 1],
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

        return response()->json([
            'settings' =>
                $settings,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE SYSTEM SETTINGS
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request
    ) {
        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        $validated =
            $request->validate([
                'system_name' => [
                    'required',
                    'string',
                    'max:100',
                ],

                'institution' => [
                    'required',
                    'string',
                    'max:150',
                ],

                'academic_year' => [
                    'required',
                    'string',
                    'max:20',
                ],

                'semester' => [
                    'required',
                    'in:1st Semester,2nd Semester,Summer',
                ],

                'session_timeout' => [
                    'required',
                    'integer',
                    'min:5',
                    'max:1440',
                ],

                'max_login_attempts' => [
                    'required',
                    'integer',
                    'min:1',
                    'max:20',
                ],

                'qr_code_validity_months' => [
                    'required',
                    'integer',
                    'min:1',
                    'max:60',
                ],

                'two_factor_enabled' => [
                    'required',
                    'boolean',
                ],
            ]);


        /*
        |--------------------------------------------------------------------------
        | GET CURRENT SETTINGS
        |--------------------------------------------------------------------------
        */

        $settings =
            SystemSetting::firstOrCreate(
                ['id' => 1],
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


        /*
        |--------------------------------------------------------------------------
        | TWO-FACTOR AUTHENTICATION EMAIL SAFEGUARD
        |--------------------------------------------------------------------------
        |
        | QRPass uses email-based two-factor authentication.
        |
        | Before global 2FA can be enabled, every approved account must have
        | a valid-looking and usable email address.
        |
        | This prevents accounts such as:
        |
        | sysadmin@qrpass.local
        |
        | from being locked out because the verification code cannot be
        | delivered.
        |
        */

        if (
            (bool) $validated[
                'two_factor_enabled'
            ]
        ) {
            $approvedUsers =
                User::where(
                    'status',
                    'approved'
                )
                ->get([
                    'id',
                    'name',
                    'username',
                    'email',
                    'role',
                ]);

            $invalidUsers =
                $approvedUsers
                    ->filter(
                        function (
                            User $user
                        ) {
                            $email =
                                strtolower(
                                    trim(
                                        (string)
                                        $user->email
                                    )
                                );

                            /*
                            |--------------------------------------------------------------------------
                            | MISSING EMAIL
                            |--------------------------------------------------------------------------
                            */

                            if (
                                $email === ''
                            ) {
                                return true;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | INVALID EMAIL FORMAT
                            |--------------------------------------------------------------------------
                            */

                            if (
                                !filter_var(
                                    $email,
                                    FILTER_VALIDATE_EMAIL
                                )
                            ) {
                                return true;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | EXTRACT DOMAIN
                            |--------------------------------------------------------------------------
                            */

                            $atPosition =
                                strrpos(
                                    $email,
                                    '@'
                                );

                            if (
                                $atPosition === false
                            ) {
                                return true;
                            }

                            $domain =
                                substr(
                                    $email,
                                    $atPosition + 1
                                );

                            /*
                            |--------------------------------------------------------------------------
                            | BLOCK .LOCAL PLACEHOLDER ADDRESSES
                            |--------------------------------------------------------------------------
                            */

                            if (
                                str_ends_with(
                                    $domain,
                                    '.local'
                                )
                            ) {
                                return true;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | BLOCK COMMON PLACEHOLDER DOMAINS
                            |--------------------------------------------------------------------------
                            */

                            $placeholderDomains = [
                                'example.com',
                                'example.org',
                                'example.net',
                                'qrpass.local',
                            ];

                            if (
                                in_array(
                                    $domain,
                                    $placeholderDomains,
                                    true
                                )
                            ) {
                                return true;
                            }

                            return false;
                        }
                    )
                    ->values();


            /*
            |--------------------------------------------------------------------------
            | REFUSE TO ENABLE 2FA IF UNSAFE ACCOUNTS EXIST
            |--------------------------------------------------------------------------
            */

            if (
                $invalidUsers
                    ->isNotEmpty()
            ) {
                return response()->json([
                    'message' =>
                        'Two-factor authentication cannot be enabled because one or more approved accounts do not have a usable email address.',

                    'invalid_accounts' =>
                        $invalidUsers
                            ->map(
                                function (
                                    User $user
                                ) {
                                    return [
                                        'id' =>
                                            $user->id,

                                        'name' =>
                                            $user->name,

                                        'username' =>
                                            $user->username,

                                        'role' =>
                                            $user->role,

                                        'email' =>
                                            $user->email,
                                    ];
                                }
                            )
                            ->values(),
                ], 422);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | STORE PREVIOUS VALUES
        |--------------------------------------------------------------------------
        */

        $oldValues = [
            'system_name' =>
                $settings->system_name,

            'institution' =>
                $settings->institution,

            'academic_year' =>
                $settings->academic_year,

            'semester' =>
                $settings->semester,

            'session_timeout' =>
                $settings->session_timeout,

            'max_login_attempts' =>
                $settings->max_login_attempts,

            'qr_code_validity_months' =>
                $settings
                    ->qr_code_validity_months,

            'two_factor_enabled' =>
                $settings
                    ->two_factor_enabled,
        ];


        /*
        |--------------------------------------------------------------------------
        | UPDATE SETTINGS
        |--------------------------------------------------------------------------
        */

        $settings->update(
            $validated
        );


        /*
        |--------------------------------------------------------------------------
        | REFRESH SETTINGS MODEL
        |--------------------------------------------------------------------------
        */

        $settings->refresh();


        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG - SYSTEM SETTINGS UPDATED
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'update_system_settings',

            description:
                $request
                    ->user()
                    ->name .
                ' updated the QRPass system settings.',

            eventType:
                'update',

            module:
                'System Settings',

            status:
                'success',

            metadata: [
                'before' =>
                    $oldValues,

                'after' => [
                    'system_name' =>
                        $settings
                            ->system_name,

                    'institution' =>
                        $settings
                            ->institution,

                    'academic_year' =>
                        $settings
                            ->academic_year,

                    'semester' =>
                        $settings
                            ->semester,

                    'session_timeout' =>
                        $settings
                            ->session_timeout,

                    'max_login_attempts' =>
                        $settings
                            ->max_login_attempts,

                    'qr_code_validity_months' =>
                        $settings
                            ->qr_code_validity_months,

                    'two_factor_enabled' =>
                        $settings
                            ->two_factor_enabled,
                ],
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
                'System settings updated successfully.',

            'settings' =>
                $settings,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SESSION POLICY
    |--------------------------------------------------------------------------
    */

    public function sessionPolicy()
    {
        $settings =
            SystemSetting::first();

        return response()->json([
            'session_timeout_minutes' =>
                (int) (
                    $settings
                        ?->session_timeout ??
                    30
                ),
        ]);
    }
}