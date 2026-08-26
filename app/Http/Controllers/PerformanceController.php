<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class PerformanceController extends Controller
{
    public function index()
    {
        /*
        |--------------------------------------------------------------------------
        | SESSION TIMEOUT SETTING
        |--------------------------------------------------------------------------
        */

        $settings = SystemSetting::first();

        $sessionTimeout = (int) (
            $settings?->session_timeout ?? 30
        );

        $sessionCutoff = now()->subMinutes(
            $sessionTimeout
        );


        /*
        |--------------------------------------------------------------------------
        | REAL ACTIVE SESSIONS
        |--------------------------------------------------------------------------
        */

        $activeSessions =
            PersonalAccessToken::where(
                function ($query) use ($sessionCutoff) {
                    $query
                        ->where(
                            'last_used_at',
                            '>=',
                            $sessionCutoff
                        )
                        ->orWhere(
                            function ($query) use ($sessionCutoff) {
                                $query
                                    ->whereNull(
                                        'last_used_at'
                                    )
                                    ->where(
                                        'created_at',
                                        '>=',
                                        $sessionCutoff
                                    );
                            }
                        );
                }
            )->count();


        /*
        |--------------------------------------------------------------------------
        | ERRORS TODAY
        |--------------------------------------------------------------------------
        */

        $errorsToday =
            AuditLog::whereDate(
                'created_at',
                today()
            )
                ->whereIn(
                    'status',
                    [
                        'failed',
                        'error',
                    ]
                )
                ->count();


        /*
        |--------------------------------------------------------------------------
        | DATABASE HEALTH + RESPONSE TIME
        |--------------------------------------------------------------------------
        */

        $databaseStatus = 'Offline';
        $databaseResponseMs = null;

        try {
            $start = microtime(true);

            DB::select(
                'SELECT 1'
            );

            $databaseResponseMs =
                round(
                    (
                        microtime(true) -
                        $start
                    ) * 1000,
                    2
                );

            $databaseStatus = 'Online';
        } catch (Throwable $e) {
            $databaseStatus = 'Offline';
        }


        /*
        |--------------------------------------------------------------------------
        | DATABASE SERVER UPTIME
        |--------------------------------------------------------------------------
        */

        $uptimeSeconds = null;

        try {
            $uptimeResult =
                DB::select(
                    "SHOW GLOBAL STATUS LIKE 'Uptime'"
                );

            if (
                isset(
                    $uptimeResult[0]
                )
            ) {
                $row =
                    (array) $uptimeResult[0];

                $uptimeSeconds =
                    (int) (
                        $row['Value'] ??
                        $row['value'] ??
                        0
                    );
            }
        } catch (Throwable $e) {
            $uptimeSeconds = null;
        }


        /*
        |--------------------------------------------------------------------------
        | QR SCAN SERVICE HEALTH
        |--------------------------------------------------------------------------
        */

        $qrStatus = 'Offline';
        $qrResponseMs = null;

        try {
            $start = microtime(true);

            DB::table(
                'registered_items'
            )
                ->select('id')
                ->limit(1)
                ->get();

            $qrResponseMs =
                round(
                    (
                        microtime(true) -
                        $start
                    ) * 1000,
                    2
                );

            $qrStatus = 'Online';
        } catch (Throwable $e) {
            $qrStatus = 'Offline';
        }


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATION SERVICE HEALTH
        |--------------------------------------------------------------------------
        */

        $notificationStatus =
            'Not Configured';

        $notificationResponseMs =
            null;

        if (
            Schema::hasTable(
                'notifications'
            )
        ) {
            try {
                $start =
                    microtime(true);

                DB::table(
                    'notifications'
                )
                    ->select('id')
                    ->limit(1)
                    ->get();

                $notificationResponseMs =
                    round(
                        (
                            microtime(true) -
                            $start
                        ) * 1000,
                        2
                    );

                $notificationStatus =
                    'Online';
            } catch (Throwable $e) {
                $notificationStatus =
                    'Offline';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | BACKUP SERVICE
        |--------------------------------------------------------------------------
        */

        $backupStatus =
            'Not Configured';

        $backupLastRun =
            null;

        $backupDirectory =
            storage_path(
                'app/backups'
            );

        if (
            File::exists(
                $backupDirectory
            )
        ) {
            $files =
                File::files(
                    $backupDirectory
                );

            if (
                count($files) > 0
            ) {
                usort(
                    $files,
                    function ($a, $b) {
                        return
                            $b->getMTime() <=>
                            $a->getMTime();
                    }
                );

                $latestBackup =
                    $files[0];

                $backupLastRun =
                    date(
                        'Y-m-d H:i:s',
                        $latestBackup
                            ->getMTime()
                    );

                $backupStatus =
                    'Available';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | WEB APPLICATION RESPONSE TIME
        |--------------------------------------------------------------------------
        */

        $applicationResponseMs =
            defined(
                'LARAVEL_START'
            )
                ? round(
                    (
                        microtime(true) -
                        LARAVEL_START
                    ) * 1000,
                    2
                )
                : null;


        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'metrics' => [
                'server_uptime_seconds' =>
                    $uptimeSeconds,

                'active_sessions' =>
                    $activeSessions,

                'qr_response_ms' =>
                    $qrResponseMs,

                'errors_today' =>
                    $errorsToday,

                'session_timeout_minutes' =>
                    $sessionTimeout,
            ],

            'services' => [
                [
                    'name' =>
                        'Database Server',

                    'status' =>
                        $databaseStatus,

                    'response_ms' =>
                        $databaseResponseMs,
                ],

                [
                    'name' =>
                        'Web Application',

                    'status' =>
                        'Online',

                    'response_ms' =>
                        $applicationResponseMs,
                ],

                [
                    'name' =>
                        'QR Scan Service',

                    'status' =>
                        $qrStatus,

                    'response_ms' =>
                        $qrResponseMs,
                ],

                [
                    'name' =>
                        'Notification Service',

                    'status' =>
                        $notificationStatus,

                    'response_ms' =>
                        $notificationResponseMs,
                ],

                [
                    'name' =>
                        'Backup Service',

                    'status' =>
                        $backupStatus,

                    'response_ms' =>
                        null,

                    'last_run' =>
                        $backupLastRun,
                ],
            ],
        ]);
    }
}