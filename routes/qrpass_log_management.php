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

// Serialize management mutations against the same local archive store.
$qrpassArchiveLock = function (callable $operation) {
    $disk = Storage::disk('local');
    $disk->makeDirectory('qrpass-log-archives');
    $lock = fopen($disk->path('qrpass-log-archives/.management.lock'), 'c');
    if ($lock === false) abort(500, 'Could not open audit archive lock.');
    try {
        if (!flock($lock, LOCK_EX)) abort(500, 'Could not lock audit archives.');
        return $operation();
    } finally {
        fclose($lock);
    }
};

$qrpassWriteHistory = function (array $entry) {
    $path = Storage::disk('local')->path('qrpass-log-archives/protected-cleanup-history.jsonl');
    $line = json_encode($entry + ['at' => now()->toIso8601String()], JSON_THROW_ON_ERROR);
    $data = PHP_EOL . $line;
    if (file_put_contents($path, $data, FILE_APPEND | LOCK_EX) !== strlen($data)) {
        abort(500, 'Could not write protected audit cleanup history.');
    }
};

$qrpassWriteManifest = function ($disk, string $path, array $manifest) {
    $temporary = $path . '.' . Str::uuid() . '.tmp';
    try {
        if (!$disk->put($temporary, json_encode($manifest, JSON_THROW_ON_ERROR)) || !$disk->move($temporary, $path)) {
            abort(500, 'Could not save audit archive manifest.');
        }
    } finally {
        $disk->delete($temporary);
    }
};

$qrpassMoveLogs = function ($query, $user, ?int $expected = null) use ($qrpassArchiveLock, $qrpassWriteManifest, $qrpassWriteHistory) {
    return $qrpassArchiveLock(function () use ($query, $user, $expected, $qrpassWriteManifest, $qrpassWriteHistory) {
        $disk = Storage::disk('local');
        $id = (string) Str::uuid();
        $file = "qrpass-log-archives/{$id}.json";
        $manifestFile = "qrpass-log-archives/{$id}.manifest.json";
        try {
            $count = DB::transaction(function () use ($query, $user, $expected, $disk, $id, $file, $manifestFile, $qrpassWriteManifest, $qrpassWriteHistory) {
                $rows = $query->orderBy('id')->lockForUpdate()->get();
                if ($expected !== null && $rows->count() !== $expected) abort(409, 'Some selected logs are no longer available. Refresh the list.');
                if ($rows->isEmpty()) abort(422, 'No active logs match this selection.');

                $payload = ['archive_id' => $id, 'created_at' => now()->toIso8601String(), 'archived_by' => $user->id, 'records' => $rows->all()];
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (!$disk->put($file, $json) || $disk->get($file) !== $json) abort(500, 'Archive backup verification failed.');

                $count = 0;
                foreach ($rows->pluck('id')->chunk(500) as $ids) {
                    $count += DB::table('audit_logs')->whereIn('id', $ids->all())->delete();
                }
                if ($count !== $rows->count()) abort(409, 'Archive not completed. Selected log count changed.');

                $qrpassWriteHistory(['action' => 'archive_move', 'archive_id' => $id, 'count' => $count, 'user_id' => $user->id]);
                $qrpassWriteManifest($disk, $manifestFile, [
                    'archive_id' => $id, 'count' => $count, 'sha256' => hash('sha256', $json),
                    'created_by' => $user->id, 'created_at' => $payload['created_at'], 'moved' => true, 'deleted' => false,
                ]);
                return $count;
            });
        } catch (Throwable $error) {
            // A rolled-back move must not leave a restorable batch for still-active rows.
            $disk->delete($manifestFile);
            throw $error;
        }
        return response()->json(['archive_id' => $id, 'count' => $count, 'message' => $count . ' logs archived.']);
    });
};

Route::middleware('auth:sanctum')->prefix('sysadmin/manage-audit-logs')->group(function () use ($qrpassGuard, $qrpassWindow, $qrpassArchiveLock, $qrpassWriteHistory, $qrpassWriteManifest, $qrpassMoveLogs) {
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
    Route::get('/archive/{id}/records', function (Request $request, string $id) use ($qrpassGuard) {
        $qrpassGuard($request);
        if (!Str::isUuid($id)) abort(404);
        $input = $request->validate(['page' => 'nullable|integer|min:1|max:1000000', 'search' => 'nullable|string|max:500']);
        $disk = Storage::disk('local');
        $file = "qrpass-log-archives/{$id}.json";
        $manifestFile = "qrpass-log-archives/{$id}.manifest.json";
        if (!$disk->exists($file) || !$disk->exists($manifestFile)) abort(404, 'Archive not found.');
        $manifest = json_decode($disk->get($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($manifest['archive_id'] ?? null) === $id && !empty($manifest['moved']) && empty($manifest['restored_at']), 404);
        $raw = $disk->get($file);
        abort_unless(is_string($raw) && hash_equals((string) ($manifest['sha256'] ?? ''), hash('sha256', $raw)), 422, 'Archive verification failed.');
        $archive = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $page = $input['page'] ?? 1;
        $size = 50;
        $offset = ($page - 1) * $size;
        $search = trim($input['search'] ?? '');
        $records = [];
        $matching = 0;
        $columns = ['id', 'actor_name', 'actor_username', 'event_type', 'action', 'module', 'description', 'status', 'created_at', 'ip_address'];
        foreach ($archive['records'] as $row) {
            $row = array_intersect_key($row, array_flip($columns));
            if ($search !== '' && !collect($row)->contains(fn ($value) => mb_stripos((string) $value, $search) !== false)) continue;
            if ($matching >= $offset && count($records) < $size) $records[] = $row;
            $matching++;
        }
        return response()->json([
            'archive_id' => $id, 'records' => $records, 'matching' => $matching,
            'total' => count($archive['records']), 'has_more' => $matching > $offset + $size,
        ])->header('Cache-Control', 'private, no-store');
    });

    // QRPass Manage Logs independent delete v3: independent, authorized, date-scoped deletion.
    // Archive remains a separate optional feature. Cleanup metadata is persisted outside audit_logs.
    // QRPASS_MANAGE_DELETE_UNIFORM_V191 - permanently delete selected audit logs only.
    Route::post('/delete-ids', function (Request $request) use ($qrpassGuard, $qrpassArchiveLock, $qrpassWriteHistory) {
        $admin = $qrpassGuard($request);
        $input = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required|integer|min:1|distinct',
            'confirmation' => 'required|in:DELETE SELECTED LOGS',
        ]);
        $ids = $input['ids'];
        return $qrpassArchiveLock(function () use ($ids, $admin, $qrpassWriteHistory) {
            $matched = DB::table('audit_logs')->whereIn('id', $ids)->count();
            if (!$matched) return response()->json(['message' => 'No matching logs remain.'], 422);
            $event = [
                'action' => 'selected_delete', 'requested_by' => $admin->id,
                'ids' => $ids, 'matched_count' => $matched, 'at' => now()->toIso8601String()
            ];
            $qrpassWriteHistory($event + ['state' => 'requested']);
            $deleted = DB::transaction(function () use ($ids, $event, $qrpassWriteHistory) {
                $count = DB::table('audit_logs')->whereIn('id', $ids)->delete();
                $qrpassWriteHistory($event + ['state' => 'completed', 'deleted_count' => $count, 'completed_at' => now()->toIso8601String()]);
                return $count;
            });
            return response()->json(['deleted_count' => $deleted, 'message' => 'Selected audit logs deleted.']);
        });
    });

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
                if (!empty($meta['restored_at']) || !$disk->exists('qrpass-log-archives/' . $meta['archive_id'] . '.json')) continue;
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
    Route::post('/archive-move', function (Request $request) use ($qrpassGuard, $qrpassWindow, $qrpassMoveLogs) {
        $user = $qrpassGuard($request);
        [$from, $to] = $qrpassWindow($request);
        return $qrpassMoveLogs(DB::table('audit_logs')->where('created_at', '>=', $from)->where('created_at', '<', $to), $user);
    });

    Route::post('/unarchive', function (Request $request) use ($qrpassGuard, $qrpassArchiveLock, $qrpassWriteManifest, $qrpassWriteHistory) {
        $user = $qrpassGuard($request);
        $id = $request->validate(['archive_id' => 'required|uuid'])['archive_id'];

        return $qrpassArchiveLock(function () use ($id, $user, $qrpassWriteManifest, $qrpassWriteHistory) {
            $disk = Storage::disk('local');
            $file = "qrpass-log-archives/{$id}.json";
            $manifestFile = "qrpass-log-archives/{$id}.manifest.json";
            if (!$disk->exists($file) || !$disk->exists($manifestFile)) abort(404, 'Archive not found.');

            $json = $disk->get($file);
            $meta = json_decode($disk->get($manifestFile), true, 512, JSON_THROW_ON_ERROR);
            if (($meta['archive_id'] ?? null) !== $id || !hash_equals((string)($meta['sha256'] ?? ''), hash('sha256', $json))) {
                abort(422, 'Archive verification failed.');
            }
            if (!empty($meta['restored_at'])) {
                return response()->json(['restored_count' => 0, 'message' => 'This archive batch was already restored.']);
            }
            if (empty($meta['moved']) || !empty($meta['deleted'])) abort(409, 'This batch is unavailable for restore.');

            $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['records'] ?? [];
            $ids = array_column($rows, 'id');
            if (!$ids || count($ids) !== (int)$meta['count'] || count(array_unique($ids)) !== count($rows)) {
                abort(422, 'Archive record count mismatch.');
            }

            $columns = array_flip(DB::getSchemaBuilder()->getColumnListing('audit_logs'));
            $manifestUpdated = false;
            try {
                $restored = DB::transaction(function () use ($rows, $columns, $meta, $disk, $manifestFile, $id, $user, $qrpassWriteManifest, $qrpassWriteHistory, &$manifestUpdated) {
                    $total = 0;
                    foreach (array_chunk($rows, 65) as $chunk) {
                        $activeIds = DB::table('audit_logs')->whereIn('id', array_column($chunk, 'id'))->lockForUpdate()->pluck('id')->flip();
                        $actorIds = DB::table('users')->whereIn('id', array_filter(array_column($chunk, 'user_id')))->pluck('id')->flip();
                        foreach ($chunk as $row) {
                            if ($activeIds->has($row['id'])) continue;
                            $record = array_intersect_key($row, $columns);
                            // Match the live foreign key's nullOnDelete behavior for removed users.
                            if (isset($record['user_id']) && !$actorIds->has($record['user_id'])) $record['user_id'] = null;
                            DB::table('audit_logs')->insert($record);
                            $total++;
                        }
                    }
                    $qrpassWriteHistory(['action' => 'unarchive', 'archive_id' => $id, 'restored_count' => $total, 'user_id' => $user->id]);
                    $qrpassWriteManifest($disk, $manifestFile, array_merge($meta, [
                        'restored_at' => now()->toIso8601String(), 'restored_by' => $user->id, 'restored_count' => $total,
                    ]));
                    $manifestUpdated = true;
                    return $total;
                });
            } catch (Throwable $error) {
                if ($manifestUpdated) $qrpassWriteManifest($disk, $manifestFile, $meta);
                throw $error;
            }

            return response()->json(['restored_count' => $restored, 'message' => $restored . ' records restored to Audit Activity Records.']);
        });
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
    Route::post('/delete-archive', function (Request $request) use ($qrpassGuard, $qrpassArchiveLock, $qrpassWriteHistory) {
        $admin = $qrpassGuard($request);
        $validated = $request->validate([
            'archive_id' => 'required|uuid',
            'confirmation' => 'required|in:DELETE ARCHIVE',
        ]);
        $id = $validated['archive_id'];
        return $qrpassArchiveLock(function () use ($id, $admin, $qrpassWriteHistory) {
            $disk = Storage::disk('local');
            $archiveFile = "qrpass-log-archives/{$id}.json";
            $manifestFile = "qrpass-log-archives/{$id}.manifest.json";
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
            $qrpassWriteHistory($record + ['status' => 'requested']);
            if (!$disk->delete($archiveFile)) abort(500, 'Archive file deletion failed.');
            if (!$disk->delete($manifestFile)) {
                report(new \RuntimeException('Could not remove metadata for deleted audit archive ' . $id));
            }
            try {
                $qrpassWriteHistory($record + ['status' => 'completed']);
            } catch (\Throwable $error) {
                report($error);
            }
            return response()->json(['archive_id' => $id, 'message' => 'Archive deleted permanently. Active System Logs were not changed.']);
        });
    });

    // QRPass Restore & Export v6: protected XLSX and PDF archive exports.
    require __DIR__ . '/qrpass_archive_exports.php';

});


// Selected audit log archiving uses the exact IDs checked in Manage Logs.
Route::middleware('auth:sanctum')->prefix('sysadmin/manage-audit-logs')->group(function () use ($qrpassGuard, $qrpassMoveLogs) {
    Route::post('/archive-ids', function (Request $request) use ($qrpassGuard, $qrpassMoveLogs) {
        $user = $qrpassGuard($request);
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required|integer|min:1|distinct',
        ]);
        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));
        return $qrpassMoveLogs(DB::table('audit_logs')->whereIn('id', $ids), $user, count($ids));
    });
});
