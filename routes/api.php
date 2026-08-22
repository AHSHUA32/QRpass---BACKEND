<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegisteredItemController;
use App\Http\Controllers\ScanLogController;
use App\Http\Controllers\SecurityIncidentController;
use App\Http\Controllers\LostFoundItemController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SystemRecordController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;


/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

Route::get('/test', function () {
    return response()->json([
        'message' => 'QRPass API is working!'
    ]);
});


Route::post(
    '/login',
    [AuthController::class, 'login']
);


Route::post(
    '/register',
    [AuthController::class, 'register']
);
/*
|--------------------------------------------------------------------------
| PASSWORD RESET
|--------------------------------------------------------------------------
*/

Route::post(
    '/forgot-password',
    [AuthController::class, 'requestPasswordReset']
);

Route::post(
    '/verify-reset-code',
    [AuthController::class, 'verifyPasswordResetCode']
);

Route::post(
    '/reset-password',
    [AuthController::class, 'resetPassword']
);

/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | LOGOUT
    |--------------------------------------------------------------------------
    */

    Route::post('/logout', function (Request $request) {

        $token = $request
            ->user()
            ->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    });


    /*
    |--------------------------------------------------------------------------
    | STUDENT ROUTES
    |--------------------------------------------------------------------------
    */

    Route::middleware(
        'role:student'
    )->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Item Registration
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/items',
            [RegisteredItemController::class, 'index']
        );

        Route::post(
            '/items',
            [RegisteredItemController::class, 'store']
        );


        /*
        |--------------------------------------------------------------------------
        | Claim Found Item
        |--------------------------------------------------------------------------
        */

        Route::put(
            '/lost-found/{id}/claim',
            [LostFoundItemController::class, 'claim']
        );
    });


    /*
    |--------------------------------------------------------------------------
    | PCO STAFF ROUTES
    |--------------------------------------------------------------------------
    */

    Route::middleware(
        'role:pco'
    )->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Pending Item Registration Requests
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/items/pending',
            [RegisteredItemController::class, 'pending']
        );


        /*
        |--------------------------------------------------------------------------
        | Approve Item Registration
        |--------------------------------------------------------------------------
        */

        Route::put(
            '/items/{id}/approve',
            [RegisteredItemController::class, 'approve']
        );
    });


    /*
    |--------------------------------------------------------------------------
    | PCO + SYSTEM ADMIN ITEM REGISTRY
    |--------------------------------------------------------------------------
    */

    Route::middleware(
        'role:pco,sysadmin'
    )->group(function () {

        Route::get(
            '/items/all',
            [RegisteredItemController::class, 'allItems']
        );
    });


    /*
    |--------------------------------------------------------------------------
    | SECURITY PERSONNEL / CSU ROUTES
    |--------------------------------------------------------------------------
    */

    Route::middleware(
        'role:security'
    )->group(function () {

        /*
        |--------------------------------------------------------------------------
        | QR Verification
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/items/verify',
            [RegisteredItemController::class, 'verify']
        );


        /*
        |--------------------------------------------------------------------------
        | Create Scan Log
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/scan-logs',
            [ScanLogController::class, 'store']
        );


        /*
        |--------------------------------------------------------------------------
        | Security Incidents
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/security-incidents',
            [SecurityIncidentController::class, 'store']
        );

        Route::put(
            '/security-incidents/{id}/resolve',
            [SecurityIncidentController::class, 'resolve']
        );


        /*
        |--------------------------------------------------------------------------
        | Create Lost & Found Record
        |--------------------------------------------------------------------------
        |
        | A person turns a found item over to CSU.
        | CSU records the item and credits the person who found it.
        |
        */

        Route::post(
            '/lost-found',
            [LostFoundItemController::class, 'store']
        );


        /*
        |--------------------------------------------------------------------------
        | Mark Lost & Found Item as Recovered
        |--------------------------------------------------------------------------
        |
        | After CSU verifies that the claimant is the rightful owner,
        | CSU marks the record as Recovered.
        |
        */

        Route::put(
            '/lost-found/{id}/recovered',
            [LostFoundItemController::class, 'markRecovered']
        );
    });


    /*
    |--------------------------------------------------------------------------
    | SECURITY + SYSTEM ADMIN ROUTES
    |--------------------------------------------------------------------------
    */

    Route::middleware(
        'role:security,sysadmin'
    )->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Scan Logs
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/scan-logs',
            [ScanLogController::class, 'index']
        );


        /*
        |--------------------------------------------------------------------------
        | Security Incidents
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/security-incidents',
            [SecurityIncidentController::class, 'index']
        );
    });


    /*
    |--------------------------------------------------------------------------
    | LOST & FOUND REGISTRY
    |--------------------------------------------------------------------------
    |
    | Student:
    |   - Browse records
    |   - Claim / inquire
    |
    | Security:
    |   - Browse records
    |   - Create records
    |   - Process claims
    |   - Mark items as Recovered
    |
    | Students do NOT create Lost & Found reports.
    |
    */

    Route::middleware(
        'role:student,security'
    )->group(function () {

        Route::get(
            '/lost-found',
            [LostFoundItemController::class, 'index']
        );
    });


    /*
    |--------------------------------------------------------------------------
    | NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    Route::middleware(
        'role:student,security,pco,sysadmin'
    )->group(function () {

        Route::get(
            '/notifications',
            [NotificationController::class, 'index']
        );

        Route::put(
            '/notifications/read-all',
            [NotificationController::class, 'markAllRead']
        );

        Route::put(
            '/notifications/{id}/read',
            [NotificationController::class, 'markRead']
        );
    });


    /*
    |--------------------------------------------------------------------------
    | SYSTEM ADMINISTRATOR ROUTES
    |--------------------------------------------------------------------------
    */

    Route::middleware(
        'role:sysadmin'
    )->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Overview Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/dashboard',
            [DashboardController::class, 'index']
        );


        /*
        |--------------------------------------------------------------------------
        | System Records
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/system-records',
            [SystemRecordController::class, 'index']
        );


        /*
        |--------------------------------------------------------------------------
        | Reports & Analytics
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/reports',
            [ReportController::class, 'index']
        );


        /*
        |--------------------------------------------------------------------------
        | User Accounts
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/users',
            [UserController::class, 'index']
        );

        Route::post(
            '/users',
            [UserController::class, 'store']
        );

        Route::put(
            '/users/{id}',
            [UserController::class, 'update']
        );

        Route::put(
            '/users/{id}/status',
            [UserController::class, 'updateStatus']
        );
    });
});