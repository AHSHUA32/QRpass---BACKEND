<?php

namespace App\Http\Controllers;

use App\Models\User;
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
            'summary' => [
                'total_users' => User::count(),

                'active_users' => User::where(
                    'status',
                    'approved'
                )->count(),

                'inactive_users' => User::where(
                    'status',
                    'inactive'
                )->count(),

                'students' => User::where(
                    'role',
                    'student'
                )->count(),

                'security' => User::where(
                    'role',
                    'security'
                )->count(),

                'pco' => User::where(
                    'role',
<<<<<<< HEAD
                    'sao'
=======
                    'pco'
>>>>>>> 6354b62 (Standardize PCO role and update QRPass backend)
                )->count(),

                'system_admins' => User::where(
                    'role',
                    'sysadmin'
                )->count(),
            ],

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
<<<<<<< HEAD
                'in:student,security,sao,sysadmin',
=======
                'in:student,security,pco,sysadmin',
>>>>>>> 6354b62 (Standardize PCO role and update QRPass backend)
            ],

            'password' => [
                'required',
                'string',
                'min:6',
            ],
        ]);

        $user = User::create([
            'name' => $validated['name'],

            'email' => $validated['email'],

            'username' => $validated['username'],

            'role' => $validated['role'],

            'status' => 'approved',

            'password' => Hash::make(
                $validated['password']
            ),
        ]);

        return response()->json([
            'message' =>
                'User account created successfully.',

            'user' => $user,
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
        $user = User::findOrFail($id);

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

                Rule::unique(
                    'users',
                    'email'
                )->ignore($user->id),
            ],

            'username' => [
                'required',
                'string',
                'max:255',

                Rule::unique(
                    'users',
                    'username'
                )->ignore($user->id),
            ],

            'role' => [
                'required',
<<<<<<< HEAD
                'in:student,security,sao,sysadmin',
=======
                'in:student,security,pco,sysadmin',
>>>>>>> 6354b62 (Standardize PCO role and update QRPass backend)
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Protect the currently logged-in System Administrator
        |--------------------------------------------------------------------------
        |
        | The admin should not accidentally change their own role while
        | currently logged in.
        |
        */

        if (
            $request->user()->id === $user->id &&
            $validated['role'] !== 'sysadmin'
        ) {
            return response()->json([
                'message' =>
                    'You cannot remove the System Administrator role from your own account while you are logged in.',
            ], 422);
        }

        $user->name =
            $validated['name'];

        $user->email =
            $validated['email'];

        $user->username =
            $validated['username'];

        $user->role =
            $validated['role'];

        $user->save();

        return response()->json([
            'message' =>
                'User account updated successfully.',

            'user' => $user,
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

        $user = User::findOrFail($id);

        /*
        |--------------------------------------------------------------------------
        | Prevent the logged-in administrator from disabling themselves
        |--------------------------------------------------------------------------
        */

        if (
            $request->user()->id === $user->id &&
            $request->status === 'inactive'
        ) {
            return response()->json([
                'message' =>
                    'You cannot deactivate your own administrator account.',
            ], 422);
        }

        $user->status =
            $request->status;

        $user->save();

        return response()->json([
            'message' =>
                $user->status === 'approved'
                    ? 'User account activated successfully.'
                    : 'User account deactivated successfully.',

            'user' => $user,
        ]);
    }
}