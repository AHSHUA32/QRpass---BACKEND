<?php
/* QRPass audit log archive / delete endpoints. Registered inside routes/api.php. */
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

$qrpassGuard = function (Request $request) {
    $user = $request->user();
    if (!$user) abort(401, 'Authentication required.');
    $role = strtolower(trim((string)($user->role ?? $user->user_role ?? '')));
    $allowed = ['sysadmin','system administrator','system_administrator','system-admin'];
    if (!in_array($role, $allowed, true)) abort(403, 'System Administrator permission required.');
    return $user;
};
$qrpassWindow = function (Request $request) {
    $data = $request->validate([
        'period' => 'required|in:all,today,yesterday,7,30,custom',
        'start' => 'required_if:period,custom|nullable|date_format:Y-m-d',
        'end' => 'required_if:period,custom|nullable|date_format:Y-m-d',
    ]);
    $now = Carbon::now('Asia/Manila');
    $start = match ($data['period']) {
        'all' => Carbon::parse('1970-01-01', 'Asia/Manila')->startOfDay(),
        'today' => $now->copy()->startOfDay(),
        'yesterday' => $now->copy()->subDay()->startOfDay(),
        '7' => $now->copy()->subDays(6)->startOfDay(),
        '30' => $now->copy()->subDays(29)->startOfDay(),
        default => Carbon::createFromFormat('!Y-m-d', $data['start'], 'Asia/Manila')->startOfDay(),
    };
    $end = match ($data['period']) {
        'all' => Carbon::parse('2100-01-01', 'Asia/Manila')->startOfDay(),
        'today' => $now->copy()->addDay()->startOfDay(),
        'yesterday' => $now->copy()->startOfDay(),
        '7','30' => $now->copy()->addDay()->startOfDay(),
        default => Carbon::createFromFormat('!Y-m-d', $data['end'], 'Asia/Manila')->addDay()->startOfDay(),
    };
    if ($end->lessThanOrEqualTo($start) || ($data['period'] !== 'all' && $end->diffInDays($start) > 366)) abort(422, 'Invalid date range (max 366 days).');
    return [$start->copy()->utc()->toDateTimeString(), $end->copy()->utc()->toDateTimeString()];
};

Route::middleware('auth:sanctum')->prefix('sysadmin/manage-audit-logs')->group(function () use ($qrpassGuard, $qrpassWindow) {
    Route::get('/preview', function (Request $request) use ($qrpassGuard, $qrpassWindow) {
        $qrpassGuard($request);
        [$from,$to] = $qrpassWindow($request);
        $count = DB::table('audit_logs')->where('created_at','>=',$from)->where('created_at','<',$to)->count();
        return response()->json(['count'=>$count,'from_utc'=>$from,'to_utc'=>$to]);
    });
    Route::post('/archive', function (Request $request) use ($qrpassGuard, $qrpassWindow) {
        $user = $qrpassGuard($request);
        [$from,$to] = $qrpassWindow($request);
        $items = DB::table('audit_logs')->where('created_at','>=',$from)->where('created_at','<',$to)->orderBy('id')->get();
        if ($items->isEmpty()) return response()->json(['message'=>'No matching audit logs to archive.'],422);
        if ($items->count()>10000) return response()->json(['message'=>'More than 10,000 logs. Archive a shorter date range.'],422);
        $id = (string) Str::uuid();
        $payload = ['archive_id'=>$id,'created_at'=>now()->toIso8601String(),'archived_by'=>$user->id,'from_utc'=>$from,'to_utc'=>$to,'records'=>$items->toArray()];
        $json = json_encode($payload, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        $disk = Storage::disk('local');
        $name = "qrpass-log-archives/{$id}.json";
        if (!$disk->put($name,$json)) abort(500,'Archive could not be saved. No records deleted.');
        if ($disk->get($name)!==$json) abort(500,'Archive verification failed. No records deleted.');
        $manifest = ['archive_id'=>$id,'count'=>$items->count(),'sha256'=>hash('sha256',$json),'created_by'=>$user->id,'created_at'=>now()->toIso8601String(),'deleted'=>false];
        $disk->put("qrpass-log-archives/{$id}.manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR));
        return response()->json(['archive_id'=>$id,'count'=>$items->count(),'sha256'=>$manifest['sha256'],'message'=>'Archive saved and verified. Download a copy before deleting.']);
    });
    Route::get('/archive/{id}', function (Request $request, string $id) use ($qrpassGuard) {
        $qrpassGuard($request);
        if (!Str::isUuid($id)) abort(404);
        $disk=Storage::disk('local'); $name="qrpass-log-archives/{$id}.json";
        if (!$disk->exists($name)) abort(404);
        return response($disk->get($name),200,['Content-Type'=>'application/json','Content-Disposition'=>'attachment; filename="QRPass-audit-archive-'.$id.'.json"']);
    });
    // QRPass Manage Logs independent delete v3: independent, authorized, date-scoped deletion.
    // Archive remains a separate optional feature. Cleanup metadata is persisted outside audit_logs.
    Route::post('/delete-direct', function (Request $request) use ($qrpassGuard, $qrpassWindow) {
        $admin = $qrpassGuard($request);
        $request->validate(['confirmation' => 'required|in:DELETE LOGS']);
        [$from, $to] = $qrpassWindow($request);
        $query = DB::table('audit_logs')->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $count = (clone $query)->count();
        // Protect the server from accidentally deleting a massive unreviewed range.
        if ($count > 10000) return response()->json(['message' => 'Too many records. Select a shorter date range.'], 422);
        $cleanupId = (string) Str::uuid();
        $historyFile = 'qrpass-log-archives/protected-cleanup-history.jsonl';
        $disk = Storage::disk('local');
        $record = ['id' => $cleanupId, 'action' => 'direct_delete', 'requested_by' => $admin->id, 'from_utc' => $from, 'to_utc' => $to, 'matched_count' => $count, 'at' => now()->toIso8601String()];
        // Require the separate cleanup log to be writable before deleting anything.
        try {
            if (!$disk->append($historyFile, json_encode($record + ['state' => 'requested'], JSON_THROW_ON_ERROR))) {
                // Some Laravel filesystem versions return void from append(): verify file exists instead.
                if (!$disk->exists($historyFile)) abort(500, 'Cleanup history is unavailable. No logs deleted.');
            }
        } catch (\Throwable $error) {
            abort(500, 'Cleanup history is unavailable. No logs deleted.');
        }
        $deleted = DB::transaction(function () use ($from, $to) {
            return DB::table('audit_logs')->where('created_at', '>=', $from)->where('created_at', '<', $to)->delete();
        });
        $disk->append($historyFile, json_encode($record + ['state' => 'completed', 'deleted_count' => $deleted, 'completed_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR));
        return response()->json(['deleted_count' => $deleted, 'message' => 'Selected logs deleted. Archive was not required.']);
    });
    Route::post('/delete', function (Request $request) use ($qrpassGuard) {
        $user=$qrpassGuard($request);
        $data=$request->validate(['archive_id'=>'required|uuid','confirmation'=>'required|in:DELETE ARCHIVED LOGS']);
        $id=$data['archive_id'];$disk=Storage::disk('local');
        $file="qrpass-log-archives/{$id}.json";$meta="qrpass-log-archives/{$id}.manifest.json";
        if (!$disk->exists($file)||!$disk->exists($meta)) abort(422,'Verified archive not found.');
        $raw=$disk->get($file);$manifest=json_decode($disk->get($meta),true,512,JSON_THROW_ON_ERROR);
        if (!hash_equals($manifest['sha256'],hash('sha256',$raw))) abort(422,'Archive checksum mismatch.');
        if (!empty($manifest['deleted'])) abort(409,'This archive has already been deleted from active logs.');
        $archive=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        $ids=array_map(fn($r)=>(int)$r['id'],$archive['records']);
        if(count($ids)!==(int)$manifest['count'] || !$ids) abort(422,'Archive record count mismatch.');
        $deleted=DB::transaction(function () use ($ids) {
            $total=0;
            foreach(array_chunk($ids,500) as $batch) $total+=DB::table('audit_logs')->whereIn('id',$batch)->delete();
            return $total;
        });
        $manifest['deleted']=true;$manifest['deleted_at']=now()->toIso8601String();$manifest['deleted_by']=$user->id;$manifest['deleted_count']=$deleted;
        $disk->put($meta,json_encode($manifest,JSON_THROW_ON_ERROR));
        $disk->append('qrpass-log-archives/protected-cleanup-history.jsonl',json_encode(['archive_id'=>$id,'deleted_count'=>$deleted,'user_id'=>$user->id,'at'=>now()->toIso8601String()]));
        return response()->json(['deleted_count'=>$deleted,'message'=>'Only the archived log IDs were deleted. The archive remains stored.']);
    });
    // QRPass Manage Logs archive-unarchive v4: list persisted archive manifests.
    Route::get('/archives', function (Request $request) use ($qrpassGuard) {
        $qrpassGuard($request);
        $disk = Storage::disk('local');
        $list = [];
        foreach ($disk->files('qrpass-log-archives') as $file) {
            if (!str_ends_with($file, '.manifest.json')) continue;
            try {
                $meta = json_decode($disk->get($file), true, 512, JSON_THROW_ON_ERROR);
                if (!isset($meta['archive_id']) || !Str::isUuid($meta['archive_id'])) continue;
                // QRPass Restore & Export v6: restored batches are no longer shown as active archives.
                if (!empty($meta['restored_at'])) continue;
                $list[] = [
                    'archive_id' => $meta['archive_id'],
                    'count' => $meta['count'] ?? 0,
                    'created_at' => $meta['created_at'] ?? null,
                    'restored_at' => $meta['restored_at'] ?? null,
                    'moved' => (bool)($meta['moved'] ?? false),
                    'deleted' => (bool)($meta['deleted'] ?? false),
                ];
            } catch (\Throwable $e) { continue; }
        }
        usort($list, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
        return response()->json(['archives' => $list]);
    });
    // Archives move selected logs out of the live table, keeping an on-disk recovery copy.
    Route::post('/archive-move', function (Request $request) use ($qrpassGuard, $qrpassWindow) {
        $user = $qrpassGuard($request);
        [$from, $to] = $qrpassWindow($request);
        $disk = Storage::disk('local');
        $id = (string) Str::uuid();
        $file = "qrpass-log-archives/{$id}.json";
        $manifestFile = "qrpass-log-archives/{$id}.manifest.json";
        $items = DB::table('audit_logs')->where('created_at', '>=', $from)->where('created_at', '<', $to)->orderBy('id')->get();
        if ($items->isEmpty()) return response()->json(['message' => 'No active logs match this selection.'], 422);
        // Create and verify a durable archive BEFORE removing any live audit logs.
        $payload = ['archive_id' => $id, 'created_at' => now()->toIso8601String(), 'archived_by' => $user->id, 'from_utc' => $from, 'to_utc' => $to, 'records' => $items->toArray()];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (!$disk->put($file, $json) || hash('sha256', $disk->get($file)) !== hash('sha256', $json)) abort(500, 'Archive backup verification failed. No logs removed.');
        $manifest = ['archive_id' => $id, 'count' => $items->count(), 'sha256' => hash('sha256', $json), 'created_by' => $user->id, 'created_at' => now()->toIso8601String(), 'moved' => false, 'deleted' => false];
        if (!$disk->put($manifestFile, json_encode($manifest, JSON_THROW_ON_ERROR))) abort(500, 'Archive manifest could not be saved. No logs removed.');
        $ids = $items->pluck('id')->map(fn($id) => (int)$id)->all();
        $deleted = DB::transaction(function () use ($ids) {
            $count = 0;
            foreach (array_chunk($ids, 500) as $chunk) $count += DB::table('audit_logs')->whereIn('id', $chunk)->delete();
            return $count;
        });
        $manifest['moved'] = true;
        $manifest['moved_count'] = $deleted;
        $manifest['moved_at'] = now()->toIso8601String();
        if (!$disk->put($manifestFile, json_encode($manifest, JSON_THROW_ON_ERROR))) abort(500, 'Archive saved and logs moved, but manifest update failed; archive ID: ' . $id);
        $disk->append('qrpass-log-archives/protected-cleanup-history.jsonl', json_encode(['action' => 'archive_move', 'archive_id' => $id, 'count' => $deleted, 'user_id' => $user->id, 'at' => now()->toIso8601String()]));
        return response()->json(['archive_id' => $id, 'count' => $deleted, 'message' => 'Logs archived and hidden from active System Logs.']);
    });
    Route::post('/unarchive', function (Request $request) use ($qrpassGuard) {
        $user = $qrpassGuard($request);
        $data = $request->validate(['archive_id' => 'required|uuid']);
        $id = $data['archive_id'];
        $disk = Storage::disk('local');
        $file = "qrpass-log-archives/{$id}.json";
        $manifestFile = "qrpass-log-archives/{$id}.manifest.json";
        if (!$disk->exists($file) || !$disk->exists($manifestFile)) abort(404, 'Archive not found.');
        $json = $disk->get($file);
        $meta = json_decode($disk->get($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        if (!hash_equals((string)($meta['sha256'] ?? ''), hash('sha256', $json))) abort(422, 'Archive checksum mismatch.');
        $archive = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $rows = $archive['records'] ?? [];
        $columns = array_flip(DB::getSchemaBuilder()->getColumnListing('audit_logs'));
        $restored = DB::transaction(function () use ($rows, $columns) {
            $total = 0;
            foreach (array_chunk($rows, 200) as $chunk) {
                $normalized = [];
                foreach ($chunk as $row) {
                    $data = array_intersect_key((array)$row, $columns);
                    if (isset($data['id'])) $normalized[] = $data;
                }
                if ($normalized) $total += DB::table('audit_logs')->insertOrIgnore($normalized);
            }
            return $total;
        });
        $meta['restored_at'] = now()->toIso8601String();
        $meta['restored_by'] = $user->id;
        $meta['restored_count'] = $restored;
        $disk->put($manifestFile, json_encode($meta, JSON_THROW_ON_ERROR));
        $disk->append('qrpass-log-archives/protected-cleanup-history.jsonl', json_encode(['action' => 'unarchive', 'archive_id' => $id, 'restored_count' => $restored, 'user_id' => $user->id, 'at' => now()->toIso8601String()]));
        return response()->json(['restored_count' => $restored, 'message' => $restored . ' records restored to active System Logs.']);
    });
    // Independent delete for ANY date range including all audit logs, no archival prerequisite.
    Route::post('/delete-selection', function (Request $request) use ($qrpassGuard, $qrpassWindow) {
        $user = $qrpassGuard($request);
        $request->validate(['confirmation' => 'required|in:DELETE LOGS']);
        [$from, $to] = $qrpassWindow($request);
        $disk = Storage::disk('local');
        $history = 'qrpass-log-archives/protected-cleanup-history.jsonl';
        $count = DB::table('audit_logs')->where('created_at','>=',$from)->where('created_at','<',$to)->count();
        $entry = ['action' => 'direct_delete', 'user_id' => $user->id, 'count' => $count, 'from_utc' => $from, 'to_utc' => $to, 'at' => now()->toIso8601String()];
        try {
            $disk->append($history, json_encode($entry + ['status' => 'requested'], JSON_THROW_ON_ERROR));
            if (!$disk->exists($history)) abort(500, 'Cleanup history unavailable. No logs deleted.');
        } catch (\Throwable $e) { abort(500, 'Cleanup history unavailable. No logs deleted.'); }
        $deleted = DB::table('audit_logs')->where('created_at','>=',$from)->where('created_at','<',$to)->delete();
        $disk->append($history, json_encode($entry + ['status' => 'completed', 'deleted_count' => $deleted], JSON_THROW_ON_ERROR));
        return response()->json(['deleted_count' => $deleted]);
    });

    // QRPass Archive Deletion v5: only delete a saved archive file and its manifest.
    // Never delete rows from audit_logs here; direct Delete Logs is an independent endpoint.
    Route::post('/delete-archive', function (Request $request) use ($qrpassGuard) {
        $admin = $qrpassGuard($request);
        $validated = $request->validate([
            'archive_id' => 'required|uuid',
            'confirmation' => 'required|in:DELETE ARCHIVE',
        ]);
        $id = $validated['archive_id'];
        $disk = Storage::disk('local');
        $archiveFile = "qrpass-log-archives/{$id}.json";
        $manifestFile = "qrpass-log-archives/{$id}.manifest.json";
        $historyFile = 'qrpass-log-archives/protected-cleanup-history.jsonl';
        if (!$disk->exists($archiveFile) || !$disk->exists($manifestFile)) abort(404, 'Archive not found.');
        $manifest = json_decode($disk->get($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['archive_id'] ?? '') !== $id) abort(422, 'Archive metadata mismatch.');
        $json = $disk->get($archiveFile);
        if (!hash_equals((string)($manifest['sha256'] ?? ''), hash('sha256', $json))) abort(422, 'Archive checksum verification failed.');
        $record = [
            'action' => 'delete_archive', 'archive_id' => $id,
            'count' => (int)($manifest['count'] ?? 0),
            'deleted_by' => $admin->id, 'at' => now()->toIso8601String(),
        ];
        // Protected cleanup history must be writable before removing the archive.
        try {
            $disk->append($historyFile, json_encode($record + ['status' => 'requested'], JSON_THROW_ON_ERROR));
            if (!$disk->exists($historyFile)) abort(500, 'Cleanup history unavailable. Archive not deleted.');
        } catch (\Throwable $e) { abort(500, 'Cleanup history unavailable. Archive not deleted.'); }
        if (!$disk->delete($archiveFile)) abort(500, 'Archive file deletion failed.');
        if (!$disk->delete($manifestFile)) abort(500, 'Archive manifest deletion failed.');
        $disk->append($historyFile, json_encode($record + ['status' => 'completed'], JSON_THROW_ON_ERROR));
        return response()->json(['archive_id' => $id, 'message' => 'Archive deleted permanently. Active System Logs were not changed.']);
    });

    // QRPass Restore & Export v6: protected XLSX and PDF archive exports.
    require __DIR__ . '/qrpass_archive_exports.php';

});
