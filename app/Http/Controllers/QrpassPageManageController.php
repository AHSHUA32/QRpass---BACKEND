<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class QrpassPageManageController extends Controller
{
    private const MODULES = [
        'items' => 'registered_items',
        'lost-found' => 'lost_found_items',
        'announcements' => 'announcements',
        'users' => 'users',
    ];

    private function table(string $module): string
    {
        abort_unless(isset(self::MODULES[$module]), 404, 'Unknown management page.');
        return self::MODULES[$module];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() && $request->user()->role === 'sysadmin', 403);
    }

    private function ensureInstalled(string $table): void
    {
        abort_unless(Schema::hasColumn($table, 'qrpass_archived_at'), 503, 'Run the QRPass page management migration first.');
    }

    public function index(Request $request, string $module)
    {
        $this->authorizeAdmin($request);
        $table = $this->table($module);
        $this->ensureInstalled($table);
        $archived = $request->query('view') === 'archived';
        $query = DB::table($table);
        $query = $archived ? $query->whereNotNull('qrpass_archived_at') : $query->whereNull('qrpass_archived_at');
        $rows = $query->orderByDesc('id')->limit(1000)->get();
        return response()->json(['data' => $rows, 'view' => $archived ? 'archived' : 'active', 'returned' => $rows->count(), 'limit' => 1000]);
    }

    public function action(Request $request, string $module)
    {
        $this->authorizeAdmin($request);
        $table = $this->table($module);
        $this->ensureInstalled($table);
        $validated = $request->validate([
            'action' => 'required|in:archive,restore,delete,disable,enable',
            'ids' => 'required|array|min:1|max:1000',
            'ids.*' => 'required|integer|min:1|distinct',
            'confirmation' => 'nullable|string',
        ]);
        $action = $validated['action'];
        $ids = $validated['ids'];
        if (in_array($action, ['disable','enable'], true) && $module !== 'users') {
            return response()->json(['message' => 'Status changes apply only to users.'], 422);
        }
        if ($module === 'users' && $action === 'delete') {
            return response()->json(['message' => 'User deletion is disabled to preserve linked campus records. Use Disable.'], 422);
        }
        if ($action === 'delete' && ($validated['confirmation'] ?? '') !== 'DELETE') {
            return response()->json(['message' => 'Type DELETE to confirm.'], 422);
        }
        if ($module === 'users') {
            $adminId = $request->user()->id;
            if (in_array($adminId, $ids)) return response()->json(['message' => 'You cannot manage your own active administrator account here.'], 422);
        }
        try {
            $affected = DB::transaction(function () use ($table, $module, $ids, $action, $request) {
                $q = DB::table($table)->whereIn('id', $ids);
                if ($action === 'archive') {
                    // Preserve current account status so a restoration need not reactivate a disabled account.
                    $updated = $q->whereNull('qrpass_archived_at')->update(['qrpass_archived_at' => now()]);
                } elseif ($action === 'restore') {
                    $updated = $q->whereNotNull('qrpass_archived_at')->update(['qrpass_archived_at' => null]);
                } elseif ($action === 'disable' || $action === 'enable') {
                    $updated = $q->update(['status' => $action === 'disable' ? 'inactive' : 'approved']);
                    if ($action === 'disable') {
                        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $ids)->delete();
                    }
                } else {
                    // Do not silently cascade-delete dependent security records.
                    if ($module === 'items') {
                        if (DB::table('scan_logs')->whereIn('registered_item_id', $ids)->exists()) {
                            abort(409, 'Some items have scan history. Archive them instead of permanently deleting them.');
                        }
                    }
                    $updated = $q->delete();
                }
                DB::table('qrpass_management_history')->insert([
                    'module' => $module, 'action' => $action,
                    'record_ids' => json_encode($ids), 'affected' => $updated,
                    'actor_id' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                return $updated;
            });
            return response()->json(['success' => true, 'affected' => $affected]);
        } catch (QueryException $e) {
            Log::warning('QRPass manage action blocked by database integrity', ['module' => $module, 'action' => $action]);
            return response()->json(['message' => 'Cannot delete these records because they are referenced elsewhere. Archive instead.'], 409);
        }
    }

    public function export(Request $request, string $module)
    {
        $this->authorizeAdmin($request);
        $table = $this->table($module);
        $this->ensureInstalled($table);
        abort_unless(class_exists(\ZipArchive::class), 503, 'PHP ZipArchive extension is required to export Excel.');
        $archived = $request->query('view') === 'archived';
        $rows = DB::table($table)
            ->when($archived, fn($q) => $q->whereNotNull('qrpass_archived_at'), fn($q) => $q->whereNull('qrpass_archived_at'))
            ->orderBy('id')->limit(10000)->get();
        $keys = $rows->isNotEmpty() ? array_keys((array)$rows->first()) : ['id'];
        // Do not include password hashes, personal tokens or 2FA secrets in exported user records.
        $keys = array_values(array_filter($keys, fn($k) => !in_array($k, [
            'password','remember_token','two_factor_secret','two_factor_recovery_codes','reset_token'
        ], true)));
        $columns = [];
        foreach ($keys as $col) $columns[] = $col;
        $data = [$columns];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $v = $row->{$col} ?? '';
                $values[] = is_scalar($v) ? (string)$v : json_encode($v);
            }
            $data[] = $values;
        }
        $cell = function ($value) {
            $str = htmlspecialchars((string)($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            return '<c t="inlineStr"><is><t xml:space="preserve">'.$str.'</t></is></c>';
        };
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($data as $index => $values) {
            $sheet .= '<row r="'.($index+1).'">';
            foreach ($values as $value) $sheet .= $cell($value);
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="Records" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'</Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'qrpassxlsx_');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create XLSX export.');
        }
        foreach ($files as $name => $contents) $zip->addFromString($name, $contents);
        $zip->close();
        $filename = 'qrpass-' . $module . '-' . ($archived ? 'archived' : 'active') . '.xlsx';
        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ])->deleteFileAfterSend(true);
    }
}
