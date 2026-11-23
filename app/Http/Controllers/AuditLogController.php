<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    /**
     * Display audit logs for the System Administrator.
     */
    public function index(Request $request)
    {
        // Archived batches live in private storage. Only active rows remain in audit_logs.
        $query = AuditLog::query()
            ->with([
                'user:id,name,username,role',
            ]);

        $options = $request->validate([
            'search' => 'nullable|string|max:500',
            'view' => 'nullable|in:activity,manage',
            'activity' => 'nullable|in:all,login,logout,changes,qr_scan,errors',
            'sort' => 'nullable|in:newest,oldest',
            'from' => 'nullable|date',
            'before' => 'nullable|date',
            'cursor' => 'nullable|string|max:1024',
            'ids_only' => 'nullable|boolean',
            'ids' => 'nullable|string|regex:/^[1-9][0-9]*(,[1-9][0-9]*){0,49}$/|max:999',
        ]);

        $view = $options['view'] ?? null;
        $idsOnly = $view === 'manage' && !empty($options['ids_only']);
        if ($view === 'manage' && !empty($options['ids'])) {
            $query->whereIn('id', explode(',', $options['ids']));
        }
        $search = trim($options['search'] ?? '');
        if ($search !== '') {
            $pattern = '%' . strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query->where(function ($filter) use ($pattern, $view) {
                foreach (['actor_name', 'actor_username', 'action', 'module', 'description', 'event_type', 'status'] as $column) {
                    $filter->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
                if ($view === 'activity') {
                    $filter->orWhereRaw("LOWER(ip_address) LIKE ? ESCAPE '!'", [$pattern]);
                    $filter->orWhereHas('user', fn ($user) => $user
                        ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(username) LIKE ? ESCAPE '!'", [$pattern]));
                }
            });
        }

        foreach (['event_type', 'status', 'module'] as $column) {
            if ($request->filled($column)) $query->where($column, $request->input($column));
        }
        if ($request->filled('date')) $query->whereDate('created_at', $request->input('date'));
        foreach (['from' => '>=', 'before' => '<'] as $key => $operator) {
            if (!empty($options[$key])) {
                $query->where('created_at', $operator, \Carbon\Carbon::parse($options[$key])->utc()->toDateTimeString());
            }
        }

        $activity = $options['activity'] ?? 'all';
        $terms = match ($activity) {
            'login' => ['login', 'log in', 'sign in'],
            'logout' => ['logout', 'log out', 'sign out'],
            'changes' => ['create', 'update', 'delete', 'approval', 'status!_change'],
            'qr_scan' => ['scan'],
            default => [],
        };
        if ($terms) {
            $query->where(function ($filter) use ($terms, $activity) {
                foreach ($terms as $term) {
                    $filter->orWhereRaw("LOWER(CONCAT(COALESCE(event_type, ''), ' ', COALESCE(action, ''))) LIKE ? ESCAPE '!'", ["%{$term}%"]);
                }
                if ($activity === 'qr_scan') $filter->orWhereRaw("LOWER(module) LIKE '%qr verification%'");
            });
        }
        if ($activity === 'errors') $query->whereIn(DB::raw('LOWER(status)'), ['failed', 'error', 'warning']);

        $limit = max(1, min($request->integer('limit', $view ? 50 : 200), $view ? 100 : 500));
        $direction = ($options['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';
        $total = $view ? (clone $query)->count() : null;
        // Include older records with a null timestamp in the cursor ordering.
        if ($idsOnly) $query->without('user');
        $page = $query->select($idsOnly ? 'audit_logs.id' : 'audit_logs.*')
            ->selectRaw("COALESCE(created_at, '1000-01-01 00:00:00') AS audit_sort_date")
            ->orderBy('audit_sort_date', $direction)
            ->orderBy('id', $direction)
            ->cursorPaginate($limit);
        $next = $page->nextCursor()?->encode();
        $previous = $page->previousCursor()?->encode();
        $logs = $page->getCollection()->makeHidden('audit_sort_date');
        $pagination = ['total' => $total, 'next_cursor' => $next, 'previous_cursor' => $previous];
        if ($view === 'manage') {
            return response()->json(['logs' => $logs, 'pagination' => $pagination])->header('Cache-Control', 'private, no-store');
        }

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
            'pagination' => $pagination,
        ])->header('Cache-Control', 'private, no-store');
    }
}
