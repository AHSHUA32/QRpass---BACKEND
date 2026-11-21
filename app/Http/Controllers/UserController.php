<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | GET ALL USERS
    |--------------------------------------------------------------------------
    */

    public function index()
    {
        $users = User::orderByDesc('created_at')->get();

        return response()->json([
            'users' => $users,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE NEW USER
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'username' => [
                'required',
                'string',
                'max:255',
                'unique:users,username',
            ],

            'role' => [
                'required',
                'in:student,security,pco,sysadmin',
            ],

            'password' => [
                'required',
                'string',
                'min:6',
            ],
        ]);


        /*
        |--------------------------------------------------------------------------
        | Create User
        |--------------------------------------------------------------------------
        */

        $user = User::create([
            'name' =>
                $validated['name'],

            'email' =>
                $validated['email'],

            'username' =>
                $validated['username'],

            'role' =>
                $validated['role'],

            'status' =>
                'approved',

            'password' =>
                Hash::make(
                    $validated['password']
                ),
        ]);


        /*
        |--------------------------------------------------------------------------
        | Audit Log - User Created
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'create_user',

            description:
                $request->user()->name .
                ' created user account "' .
                $user->name .
                '".',

            eventType:
                'create',

            module:
                'User Management',

            status:
                'success',

            metadata: [
                'created_user_id' =>
                    $user->id,

                'name' =>
                    $user->name,

                'username' =>
                    $user->username,

                'email' =>
                    $user->email,

                'role' =>
                    $user->role,

                'account_status' =>
                    $user->status,
            ],

            user:
                $request->user()
        );


        return response()->json([
            'message' =>
                'User account created successfully.',

            'user' =>
                $user,
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE USER DETAILS AND ROLE
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        $id
    ) {
        $user =
            User::findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        $validated =
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'email' => [
                    'required',
                    'email',
                    'max:255',

                    Rule::unique(
                        'users',
                        'email'
                    )->ignore(
                        $user->id
                    ),
                ],

                'username' => [
                    'required',
                    'string',
                    'max:255',

                    Rule::unique(
                        'users',
                        'username'
                    )->ignore(
                        $user->id
                    ),
                ],

                'role' => [
                    'required',
                    'in:student,security,pco,sysadmin',
                ],
            ]);


        /*
        |--------------------------------------------------------------------------
        | Protect Logged-in System Administrator
        |--------------------------------------------------------------------------
        |
        | The currently logged-in administrator cannot accidentally
        | remove their own System Administrator role.
        |
        */

        if (
            $request->user()->id ===
                $user->id &&
            $validated['role'] !==
                'sysadmin'
        ) {
            return response()->json([
                'message' =>
                    'You cannot remove the System Administrator role from your own account while you are logged in.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | Store Previous Values
        |--------------------------------------------------------------------------
        */

        $oldValues = [
            'name' =>
                $user->name,

            'email' =>
                $user->email,

            'username' =>
                $user->username,

            'role' =>
                $user->role,
        ];


        /*
        |--------------------------------------------------------------------------
        | Update User
        |--------------------------------------------------------------------------
        */

        $user->name =
            $validated['name'];

        $user->email =
            $validated['email'];

        $user->username =
            $validated['username'];

        $user->role =
            $validated['role'];

        $user->save();


        /*
        |--------------------------------------------------------------------------
        | Audit Log - User Updated
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'update_user',

            description:
                $request->user()->name .
                ' updated user account "' .
                $user->name .
                '".',

            eventType:
                'update',

            module:
                'User Management',

            status:
                'success',

            metadata: [
                'updated_user_id' =>
                    $user->id,

                'before' =>
                    $oldValues,

                'after' => [
                    'name' =>
                        $user->name,

                    'email' =>
                        $user->email,

                    'username' =>
                        $user->username,

                    'role' =>
                        $user->role,
                ],
            ],

            user:
                $request->user()
        );


        return response()->json([
            'message' =>
                'User account updated successfully.',

            'user' =>
                $user,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | ENABLE / DISABLE USER
    |--------------------------------------------------------------------------
    */

    public function updateStatus(
        Request $request,
        $id
    ) {
        $request->validate([
            'status' =>
                'required|in:approved,inactive',
        ]);

        $user =
            User::findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | Prevent Administrator From Disabling Own Account
        |--------------------------------------------------------------------------
        */

        if (
            $request->user()->id ===
                $user->id &&
            $request->status ===
                'inactive'
        ) {
            return response()->json([
                'message' =>
                    'You cannot deactivate your own administrator account.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | Store Previous Status
        |--------------------------------------------------------------------------
        */

        $previousStatus =
            $user->status;


        /*
        |--------------------------------------------------------------------------
        | Update Status
        |--------------------------------------------------------------------------
        */

        $user->status =
            $request->status;

        $user->save();


        /*
        |--------------------------------------------------------------------------
        | Audit Log - User Status Changed
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                $user->status ===
                'approved'
                    ? 'activate_user'
                    : 'deactivate_user',

            description:
                $request->user()->name .
                (
                    $user->status ===
                    'approved'
                        ? ' activated user account "'
                        : ' deactivated user account "'
                ) .
                $user->name .
                '".',

            eventType:
                'status_change',

            module:
                'User Management',

            status:
                'success',

            metadata: [
                'target_user_id' =>
                    $user->id,

                'name' =>
                    $user->name,

                'username' =>
                    $user->username,

                'role' =>
                    $user->role,

                'previous_status' =>
                    $previousStatus,

                'new_status' =>
                    $user->status,
            ],

            user:
                $request->user()
        );


        return response()->json([
            'message' =>
                $user->status ===
                'approved'
                    ? 'User account activated successfully.'
                    : 'User account deactivated successfully.',

            'user' =>
                $user,
        ]);
    }
    // QRPASS_PERMANENT_REVOKE_V34
    // Delete user and tokens as a single transaction. FK restrictions protect dependent records.
    public function permanentRevoke(Request $request, $id)
    {
        $actor = $request->user();
        $target = User::findOrFail($id);
        if ((int) $actor->id === (int) $target->id) {
            return response()->json(['message' => 'You cannot permanently delete your own administrator account.'], 422);
        }
        if ($target->role === 'sysadmin' && User::where('role', 'sysadmin')->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last system administrator.'], 422);
        }
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($actor, $target) {
                // Prevent accidental cascade-deletion of permits, items and audit history.
                // All MySQL foreign keys referencing users must be clear first.
                if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
                    $references = \Illuminate\Support\Facades\DB::select(
                        "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'users'"
                    );
                    foreach ($references as $reference) {
                        $table = (string) $reference->table_name;
                        $column = (string) $reference->column_name;
                        if ($table === 'personal_access_tokens') continue;
                        if (\Illuminate\Support\Facades\DB::table($table)->where($column, $target->id)->exists()) {
                            throw new \DomainException('Related records prevent permanent deletion.');
                        }
                    }
                }
                AuditLogger::log(
                    action: 'permanent_revoke_user',
                    description: $actor->name . ' permanently deleted user account "' . $target->name . '".',
                    eventType: 'delete', module: 'User Management', status: 'success',
                    metadata: ['target_user_id' => $target->id, 'username' => $target->username, 'role' => $target->role],
                    user: $actor
                );
                $target->tokens()->delete();
                $target->forceDelete();
            });
        } catch (\DomainException $e) {
            return response()->json(['message' => 'Account has related records. Nothing was deleted. Preserve or reassign those records before permanent deletion.'], 409);
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::warning('QRPass user deletion blocked', ['user_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Account has related records protected by database constraints. Nothing was deleted. Resolve dependencies first.'], 409);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('QRPass user deletion failed', ['user_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Permanent deletion failed; no changes committed.'], 500);
        }
        return response()->json(['message' => 'Account permanently deleted and all login tokens revoked.']);
    }
}