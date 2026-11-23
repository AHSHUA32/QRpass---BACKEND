<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// QRPASS_PERMANENT_ACCESS_V91 — no user deletion; stores revocation history.
class QrpassAccessRevocationController extends Controller
{
    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:approved,inactive']);
        $actor = $request->user();
        return DB::transaction(function () use ($request, $id, $actor) {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int)$actor->id === (int)$user->id && $request->status === 'inactive') {
                return response()->json(['message' => 'You cannot disable your own administrator account.'], 422);
            }
            $previous = (string)$user->status;
            $user->status = $request->status;
            $user->save();
            if ($user->status === 'inactive') $user->tokens()->delete();
            AuditLogger::log(
                action: $user->status === 'inactive' ? 'deactivate_user' : 'activate_user',
                description: $actor->name . ($user->status === 'inactive' ? ' disabled ' : ' enabled ') . $user->name . ' QRPass account.',
                eventType: 'status_change', module: 'User Management', status: 'success',
                metadata: ['target_user_id' => $user->id, 'username' => $user->username,
                    'previous_status' => $previous, 'new_status' => $user->status], user: $actor
            );
            return response()->json(['message' => $user->status === 'inactive'
                ? 'Account disabled; active sessions terminated. You can enable it later.'
                : 'Account enabled successfully.', 'user' => $user]);
        });
    }

    public function revoke(Request $request, $id)
    {
        // QRPASS_REVOKE_PURGE_NOTIFY_V181
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $actor = $request->user();
        if (!\Illuminate\Support\Facades\Schema::hasTable('qrpass_blocked_identifiers')) {
            return response()->json(['message' => 'Blocked identifiers table is missing. Run the v180 database migration.'], 503);
        }

        try {
            return DB::transaction(function () use ($id, $actor, $data) {
                $target = User::whereKey($id)->lockForUpdate()->firstOrFail();
                if ((int)$actor->id === (int)$target->id) {
                    return response()->json(['message' => 'You cannot revoke your own account.'], 422);
                }
                if ($target->role === 'sysadmin' &&
                    User::where('role','sysadmin')->where('status','approved')->count() <= 1) {
                    return response()->json(['message' => 'Cannot revoke the last active administrator.'], 422);
                }
                if (DB::getDriverName() !== 'mysql') {
                    throw new \RuntimeException('MySQL required for safe FK cleanup');
                }

                $email = strtolower(trim((string)$target->email));
                $number = trim((string)$target->username);
                foreach (['id' => $number, 'email' => $email] as $kind => $value) {
                    if ($value !== '') {
                        DB::table('qrpass_blocked_identifiers')->updateOrInsert(
                            ['kind' => $kind, 'identifier_hash' => hash('sha256', $value)],
                            ['updated_at' => now(), 'created_at' => now()]
                        );
                    }
                }

                // Find and delete child operational records depth-first, respecting
                // the FK graph. Never disable foreign_key_checks.
                $relationships = DB::select(
                    "SELECT TABLE_NAME AS child_table, COLUMN_NAME AS child_fk, " .
                    "REFERENCED_TABLE_NAME AS parent_table, REFERENCED_COLUMN_NAME AS parent_col " .
                    "FROM information_schema.KEY_COLUMN_USAGE " .
                    "WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL"
                );
                $byParent = [];
                foreach ($relationships as $edge) {
                    $byParent[$edge->parent_table][] = $edge;
                }

                $inProgress = [];
                $completed = [];
                $deletedRows = 0;
                $deleteTree = function ($table, $keys) use (
                    &$deleteTree, &$inProgress, &$completed, &$deletedRows, $byParent
                ) {
                    $keys = array_values(array_unique(array_filter(array_map('intval', $keys), fn($n) => $n > 0)));
                    if (!$keys) return;
                    if ($table === 'users') {
                        // Do not cascade into other accounts via a self-reference.
                        throw new \RuntimeException('Unexpected nested users relationship');
                    }
                    foreach ($keys as $key) {
                        $node = $table . ':' . $key;
                        if (isset($completed[$node])) continue;
                        if (isset($inProgress[$node])) {
                            throw new \RuntimeException('Cyclic foreign key dependency encountered');
                        }
                        $inProgress[$node] = true;
                        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
                            throw new \RuntimeException('Invalid table name');
                        }

                        foreach (($byParent[$table] ?? []) as $edge) {
                            $child = (string)$edge->child_table;
                            $fk = (string)$edge->child_fk;
                            $parentCol = (string)$edge->parent_col;
                            if (!preg_match('/^[A-Za-z0-9_]+$/D', $child.$fk.$parentCol)) {
                                throw new \RuntimeException('Invalid FK name');
                            }
                            $value = DB::table($table)->where('id', $key)->value($parentCol);
                            if ($value === null) continue;

                            // Preserve auditing evidence, detach the deleted user/record.
                            if ($child === 'audit_logs' && $fk === 'user_id') {
                                DB::table($child)->where($fk, $value)->update([$fk => null]);
                                continue;
                            }
                            if ($child === 'users') {
                                if (DB::table('users')->where($fk, $value)->exists()) {
                                    throw new \RuntimeException('Other user accounts depend on this record');
                                }
                                continue;
                            }
                            $childIds = DB::table($child)->where($fk, $value)->pluck('id')->all();
                            if ($childIds) $deleteTree($child, $childIds);
                        }
                        $deletedRows += DB::table($table)->where('id', $key)->delete();
                        unset($inProgress[$node]);
                        $completed[$node] = true;
                        if ($deletedRows > 50000) throw new \RuntimeException('Deletion scope exceeds safety limit');
                    }
                };

                // Gather all direct dependents of the user before deleting any rows.
                foreach (($byParent['users'] ?? []) as $edge) {
                    $table = (string)$edge->child_table;
                    $fk = (string)$edge->child_fk;
                    $parentCol = (string)$edge->parent_col;
                    $value = DB::table('users')->where('id', $target->id)->value($parentCol);
                    if ($value === null) continue;
                    if ($table === 'audit_logs' && $fk === 'user_id') {
                        DB::table('audit_logs')->where($fk, $value)->update(['user_id' => null]);
                        continue;
                    }
                    if ($table === 'users') {
                        if (DB::table('users')->where($fk, $value)->where('id','<>',$target->id)->exists()) {
                            throw new \RuntimeException('Another account depends on target');
                        }
                        continue;
                    }
                    $ids = DB::table($table)->where($fk, $value)->pluck('id')->all();
                    if ($ids) $deleteTree($table, $ids);
                }

                // Delete active tokens even if no FK was configured.
                $target->tokens()->delete();

                $targetId = $target->id;
                $targetName = $target->name;
                $target->forceDelete();

                \App\Services\AuditLogger::log(
                    action: 'permanent_revoke_user',
                    description: 'Account permanently revoked by ' . $actor->name . '.',
                    eventType: 'delete', module: 'User Management', status: 'success',
                    metadata: [
                        'target_user_id' => $targetId,
                        'reason' => trim($data['reason']),
                        'deleted_operational_rows' => $deletedRows,
                        'identifiers_blocked' => true
                    ], user: $actor
                );

                $message = 'Account with ID ' . $number .
                    ' has been permanently revoked. Do not accept its previous QR passes.';
                $recipients = User::whereIn('role', ['security','pco'])
                    ->where('status','approved')->get();
                foreach ($recipients as $staff) {
                    \App\Models\Notification::create([
                        'user_id' => $staff->id,
                        'type' => 'account_revoked_' . $targetId,
                        'title' => 'QRPass Account Revoked',
                        'message' => $message,
                        'is_read' => false,
                    ]);
                }

                return response()->json([
                    'message' => 'Revoke Successfully',
                    'user_deleted' => true,
                    'related_operational_rows_deleted' => $deletedRows,
                    'staff_notified' => $recipients->count()
                ]);
            }, 3);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('QRPass revoke transaction rolled back', [
                'target_id' => $id, 'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Revoke could not complete safely. No changes committed. ' .
                    'Check Laravel logs and resolve unsupported related records.'
            ], 409);
        }
    }
}
