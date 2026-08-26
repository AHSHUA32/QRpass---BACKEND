<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Display audit logs for the System Administrator.
     */
    public function index(Request $request)
    {
        $query = AuditLog::query()
            ->with([
                'user:id,name,username,role',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        |
        | Search by actor name, username, action, module, description,
        | event type, or status.
        |
        */

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where(
                    'actor_name',
                    'like',
                    "%{$search}%"
                )
                    ->orWhere(
                        'actor_username',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'action',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'module',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'description',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'event_type',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'status',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Event Type Filter
        |--------------------------------------------------------------------------
        */

        if ($request->filled('event_type')) {
            $query->where(
                'event_type',
                $request->event_type
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Status Filter
        |--------------------------------------------------------------------------
        */

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->status
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Module Filter
        |--------------------------------------------------------------------------
        */

        if ($request->filled('module')) {
            $query->where(
                'module',
                $request->module
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Date Filter
        |--------------------------------------------------------------------------
        */

        if ($request->filled('date')) {
            $query->whereDate(
                'created_at',
                $request->date
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Result Limit
        |--------------------------------------------------------------------------
        */

        $limit = (int) $request->get(
            'limit',
            200
        );

        $limit = max(
            1,
            min($limit, 500)
        );

        $logs = $query
            ->latest('created_at')
            ->limit($limit)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Dashboard Statistics
        |--------------------------------------------------------------------------
        */

        $today = now()->toDateString();

        $logsToday = AuditLog::whereDate(
            'created_at',
            $today
        )->count();

        $qrScanEvents = AuditLog::whereDate(
            'created_at',
            $today
        )
            ->where(function ($query) {
                $query
                    ->where(
                        'event_type',
                        'qr_scan'
                    )
                    ->orWhere(
                        'module',
                        'QR Verification'
                    );
            })
            ->count();

        $dataChanges = AuditLog::whereDate(
            'created_at',
            $today
        )
            ->whereIn(
                'event_type',
                [
                    'create',
                    'update',
                    'delete',
                    'approval',
                    'status_change',
                ]
            )
            ->count();

        $errors = AuditLog::whereDate(
            'created_at',
            $today
        )
            ->whereIn(
                'status',
                [
                    'failed',
                    'error',
                    'warning',
                ]
            )
            ->count();

        return response()->json([
            'message' =>
                'Audit logs retrieved successfully.',

            'stats' => [
                'logs_today' =>
                    $logsToday,

                'qr_scan_events' =>
                    $qrScanEvents,

                'data_changes' =>
                    $dataChanges,

                'errors' =>
                    $errors,
            ],

            'logs' => $logs,
        ]);
    }
}