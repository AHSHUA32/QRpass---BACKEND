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
            if ($user->qrpass_revoked_at !== null) {
                return response()->json(['message' => 'This account has been permanently revoked and cannot be re-enabled.'], 409);
            }
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
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $actor = $request->user();
        return DB::transaction(function () use ($actor, $id, $data) {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int)$actor->id === (int)$user->id) {
                return response()->json(['message' => 'You cannot revoke your own administrator account.'], 422);
            }
            if ($user->qrpass_revoked_at !== null) {
                return response()->json(['message' => 'This account is already permanently revoked.'], 409);
            }
            if ($user->role === 'sysadmin' && User::where('role', 'sysadmin')
                ->where('status','approved')->whereNull('qrpass_revoked_at')->count() <= 1) {
                return response()->json(['message' => 'Cannot revoke the last active system administrator.'], 422);
            }
            $user->status = 'inactive';
            $user->qrpass_revoked_at = now();
            $user->qrpass_revocation_reason = trim($data['reason']);
            $user->save();
            $user->tokens()->delete();
            AuditLogger::log(
                action: 'revoke_user_access',
                description: $actor->name . ' permanently revoked QRPass access for "' . $user->name . '".',
                eventType: 'status_change', module: 'User Management', status: 'success',
                metadata: ['target_user_id' => $user->id, 'username' => $user->username,
                    'role' => $user->role, 'reason' => $data['reason'],
                    'permanent_access_revocation' => true, 'user_deleted' => false], user: $actor
            );
            return response()->json(['message' => 'QRPass access permanently revoked; sessions terminated. Account and security history retained.', 'user' => $user]);
        });
    }
}
