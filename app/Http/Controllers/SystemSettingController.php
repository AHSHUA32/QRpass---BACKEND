<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
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
        $settings = SystemSetting::firstOrCreate(
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

    public function update(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
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
        | Get Current Settings
        |--------------------------------------------------------------------------
        */

        $settings = SystemSetting::firstOrCreate(
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
        | Store Previous Values
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
                $settings->qr_code_validity_months,

            'two_factor_enabled' =>
                $settings->two_factor_enabled,
        ];


        /*
        |--------------------------------------------------------------------------
        | Update Settings
        |--------------------------------------------------------------------------
        */

        $settings->update(
            $validated
        );


        /*
        |--------------------------------------------------------------------------
        | Audit Log - System Settings Updated
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'update_system_settings',

            description:
                $request->user()->name .
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
                        $settings->qr_code_validity_months,

                    'two_factor_enabled' =>
                        $settings->two_factor_enabled,
                ],
            ],

            user:
                $request->user()
        );


        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'message' =>
                'System settings updated successfully.',

            'settings' =>
                $settings,
        ]);
    }

    public function sessionPolicy()
{
    $settings =
        \App\Models\SystemSetting::first();

    return response()->json([
        'session_timeout_minutes' =>
            (int) (
                $settings?->session_timeout ??
                30
            ),
    ]);
}


}