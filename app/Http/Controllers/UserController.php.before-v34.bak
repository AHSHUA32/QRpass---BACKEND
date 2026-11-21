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
}