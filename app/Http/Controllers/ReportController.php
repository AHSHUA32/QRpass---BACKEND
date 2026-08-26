<?php

namespace App\Http\Controllers;

use App\Models\RegisteredItem;
use App\Models\ScanLog;
use App\Models\SecurityIncident;
use App\Models\LostFoundItem;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | SYSTEM ADMIN REPORTS
    |--------------------------------------------------------------------------
    */

    public function index()
    {
        /*
        |--------------------------------------------------------------------------
        | REPORT SUMMARY
        |--------------------------------------------------------------------------
        */

        $totalRegisteredItems =
            RegisteredItem::count();

        $activeQrCodes =
            RegisteredItem::where(
                'status',
                'approved'
            )
                ->whereNotNull(
                    'qr_code'
                )
                ->count();

        $pendingItems =
            RegisteredItem::where(
                'status',
                'pending'
            )->count();

        $scansToday =
            ScanLog::whereDate(
                'scanned_at',
                today()
            )->count();

        $scansThisMonth =
            ScanLog::whereYear(
                'scanned_at',
                now()->year
            )
                ->whereMonth(
                    'scanned_at',
                    now()->month
                )
                ->count();

        $registrationsThisMonth =
            RegisteredItem::whereYear(
                'created_at',
                now()->year
            )
                ->whereMonth(
                    'created_at',
                    now()->month
                )
                ->count();

        $flaggedIncidents =
            SecurityIncident::where(
                'status',
                'Flagged'
            )->count();

        $resolvedIncidents =
            SecurityIncident::where(
                'status',
                'Resolved'
            )->count();

        $lostFoundAvailable =
            LostFoundItem::where(
                'status',
                'Found'
            )->count();

        $lostFoundClaimed =
            LostFoundItem::where(
                'status',
                'Claimed'
            )->count();


        /*
        |--------------------------------------------------------------------------
        | ITEM TYPE BREAKDOWN
        |--------------------------------------------------------------------------
        */

        $itemTypes =
            RegisteredItem::select(
                'item_type',
                DB::raw(
                    'COUNT(*) as total'
                )
            )
                ->groupBy(
                    'item_type'
                )
                ->orderByDesc(
                    'total'
                )
                ->get();


        /*
        |--------------------------------------------------------------------------
        | DAILY QR SCANS - LAST 7 DAYS
        |--------------------------------------------------------------------------
        */

        $dailyScans =
            ScanLog::select(
                DB::raw(
                    'DATE(scanned_at) as date'
                ),
                DB::raw(
                    'COUNT(*) as total'
                )
            )
                ->whereDate(
                    'scanned_at',
                    '>=',
                    now()
                        ->subDays(6)
                        ->toDateString()
                )
                ->groupBy(
                    DB::raw(
                        'DATE(scanned_at)'
                    )
                )
                ->orderBy(
                    'date'
                )
                ->get();


        /*
        |--------------------------------------------------------------------------
        | MONTHLY ITEM REGISTRATIONS
        |--------------------------------------------------------------------------
        */

        $monthlyRegistrations =
            RegisteredItem::select(
                DB::raw(
                    'MONTH(created_at) as month'
                ),
                DB::raw(
                    'COUNT(*) as total'
                )
            )
                ->whereYear(
                    'created_at',
                    now()->year
                )
                ->groupBy(
                    DB::raw(
                        'MONTH(created_at)'
                    )
                )
                ->orderBy(
                    'month'
                )
                ->get();


        /*
        |--------------------------------------------------------------------------
        | RECENT QR SCANS
        |--------------------------------------------------------------------------
        */

        $recentScans =
            ScanLog::with([
                'item.user',
                'scanner',
            ])
                ->latest(
                    'scanned_at'
                )
                ->limit(20)
                ->get();


        /*
        |--------------------------------------------------------------------------
        | RECENT SECURITY INCIDENTS
        |--------------------------------------------------------------------------
        */

        $recentIncidents =
            SecurityIncident::with([
                'reporter',
                'item.user',
            ])
                ->latest(
                    'reported_at'
                )
                ->limit(20)
                ->get();


        /*
        |--------------------------------------------------------------------------
        | RECENT ITEM REGISTRATIONS
        |--------------------------------------------------------------------------
        */

        $recentRegistrations =
            RegisteredItem::with(
                'user'
            )
                ->latest()
                ->limit(20)
                ->get();


        return response()->json([
            'summary' => [
                'total_registered_items' =>
                    $totalRegisteredItems,

                'active_qr_codes' =>
                    $activeQrCodes,

                'pending_items' =>
                    $pendingItems,

                'scans_today' =>
                    $scansToday,

                'scans_this_month' =>
                    $scansThisMonth,

                'registrations_this_month' =>
                    $registrationsThisMonth,

                'flagged_incidents' =>
                    $flaggedIncidents,

                'resolved_incidents' =>
                    $resolvedIncidents,

                'lost_found_available' =>
                    $lostFoundAvailable,

                'lost_found_claimed' =>
                    $lostFoundClaimed,
            ],

            'item_types' =>
                $itemTypes,

            'daily_scans' =>
                $dailyScans,

            'monthly_registrations' =>
                $monthlyRegistrations,

            'recent_scans' =>
                $recentScans,

            'recent_incidents' =>
                $recentIncidents,

            'recent_registrations' =>
                $recentRegistrations,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CSU / SECURITY REPORT DATA
    |--------------------------------------------------------------------------
    |
    | Dedicated endpoint for the Security Reports page.
    | This keeps report access separate from the normal operational
    | scan-log and security-incident endpoints.
    |
    */

    public function security()
    {
        $logs =
            ScanLog::with([
                'item.user',
                'scanner',
            ])
                ->latest(
                    'scanned_at'
                )
                ->get();

        $incidents =
            SecurityIncident::with([
                'reporter',
                'item.user',
            ])
                ->latest(
                    'reported_at'
                )
                ->get();

        return response()->json([
            'logs' =>
                $logs,

            'incidents' =>
                $incidents,
        ]);
    }
}