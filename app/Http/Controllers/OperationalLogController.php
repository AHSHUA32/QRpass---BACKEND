<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalLogController extends Controller
{
    public const ARCHIVED_AT = 'operational_archived_at';

    private const TABLES = [
        'scans' => 'scan_logs',
        'resolved-incidents' => 'security_incidents',
        'requests' => 'registered_items',
        'items' => 'registered_items',
    ];

    private function records(string $module, bool $archived): Builder
    {
        if ($module === 'scans') {
            $query = DB::table('scan_logs as records')
                ->join('users as scanner', 'scanner.id', '=', 'records.scanned_by')
                ->leftJoin('registered_items as items', 'items.id', '=', 'records.registered_item_id')
                ->where('scanner.role', 'security');
        } elseif ($module === 'resolved-incidents') {
            $query = DB::table('security_incidents as records')
                ->join('users as reporter', 'reporter.id', '=', 'records.reported_by')
                ->where('reporter.role', 'security')
                ->where('records.status', 'Resolved');
        } else {
            $query = DB::table('registered_items as records')
                ->leftJoin('users as owner', 'owner.id', '=', 'records.user_id')
                ->whereNull('records.qrpass_archived_at')
                ->where('records.status', $module === 'requests' ? 'pending' : 'approved');

            if ($module === 'items' && !$archived) {
                $query->whereNotExists(function (Builder $incidents) {
                    $incidents->selectRaw('1')->from('security_incidents')
                        ->whereColumn('registered_item_id', 'records.id')
                        ->where('status', 'Flagged');
                });
            }
        }

        $column = 'records.' . self::ARCHIVED_AT;
        return $archived ? $query->whereNotNull($column) : $query->whereNull($column);
    }

    private function ensureStorage(string $module): void
    {
        $table = self::TABLES[$module];
        abort_unless(
            Schema::hasColumn($table, self::ARCHIVED_AT) && Schema::hasTable('qrpass_management_history'),
            503,
            'Operational archives require the QRPass archive migrations.'
        );
    }

    public function index(Request $request, string $module)
    {
        $this->ensureStorage($module);
        $validated = $request->validate(['view' => 'required|in:active,archived']);
        $query = $this->records($module, $validated['view'] === 'archived');

        $columns = match ($module) {
            'scans' => ['items.item_name as title', 'records.gate', 'records.direction', 'scanner.name as person',
                'records.result as status', 'records.scanned_at as date', 'records.qr_code'],
            'resolved-incidents' => ['records.incident_type as title', 'reporter.name as person',
                'records.gate', 'records.serial_number', 'records.scanned_code as qr_code',
                'records.status', 'records.reported_at as date'],
            default => ['records.item_name as title', 'owner.name as person', 'records.serial_number',
                'records.item_type', 'records.status', 'records.created_at as date', 'records.qr_code'],
        };

        return response()->json(['data' => $query->select('records.id', ...$columns)
            ->orderByDesc('date')->orderByDesc('records.id')->get()]);
    }

    public function action(Request $request, string $module)
    {
        $this->ensureStorage($module);
        abort_if($request->input('action') === 'delete', 403, 'Permanent deletion is prohibited for operational logs.');
        $validated = $request->validate([
            'action' => 'required|in:archive,restore',
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|min:1|distinct',
        ]);
        $action = $validated['action'];
        $ids = array_map('intval', $validated['ids']);
        sort($ids);
        $restoring = $action === 'restore';

        $affected = DB::transaction(function () use ($request, $module, $action, $ids, $restoring) {
            $records = $this->records($module, $restoring)->whereIntegerInRaw('records.id', $ids);
            $eligible = (clone $records)->orderBy('records.id')->lockForUpdate()->pluck('records.id');
            abort_unless($eligible->count() === count($ids), 409, 'Some selected records are no longer available in this view. Refresh and try again.');

            $now = now();
            $table = self::TABLES[$module];
            $affected = DB::table($table)->whereIntegerInRaw('id', $ids)
                ->update([self::ARCHIVED_AT => $restoring ? null : $now]);

            DB::table('qrpass_management_history')->insert([
                'module' => 'operational-' . $module,
                'action' => $action,
                'record_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
                'affected' => $affected,
                'actor_id' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $affected;
        }, 3);

        return response()->json(['affected' => $affected]);
    }
}
