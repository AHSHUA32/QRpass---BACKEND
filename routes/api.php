<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\RegisteredItemController;
use App\Http\Controllers\ScanLogController;
use App\Http\Controllers\SecurityIncidentController;
use App\Http\Controllers\LostFoundItemController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SystemRecordController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ActiveSessionController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\AnnouncementController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

Route::get('/test', function () {
    return response()->json([
        'message' =>
            'QRPass API is working!',
    ]);
});


/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

Route::post(
    '/login',
    [AuthController::class, 'login']
);
Route::post(
    '/verify-2fa',
    [AuthController::class, 'verifyTwoFactor']
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
    [
        AuthController::class,
        'requestPasswordReset',
    ]
);

Route::post(
    '/verify-reset-code',
    [
        AuthController::class,
        'verifyPasswordResetCode',
    ]
);

Route::post(
    '/reset-password',
    [
        AuthController::class,
        'resetPassword',
    ]
);


/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware([
    'session.timeout',
    'auth:sanctum',
])->group(function () {


    /*
    |--------------------------------------------------------------------------
    | ACCOUNT SETTINGS
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/account',
        [
            AccountController::class,
            'show',
        ]
    );

    Route::put(
        '/account/profile',
        [
            AccountController::class,
            'updateProfile',
        ]
    );

    Route::put(
        '/account/password',
        [
            AccountController::class,
            'updatePassword',
        ]
    );

    Route::post(
        '/account/profile-photo',
        [
            AccountController::class,
            'uploadProfilePhoto',
        ]
    );

    Route::delete(
        '/account/profile-photo',
        [
            AccountController::class,
            'removeProfilePhoto',
        ]
    );
    
        /*
    |--------------------------------------------------------------------------
    | SESSION POLICY
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/session-policy',
        [
            SystemSettingController::class,
            'sessionPolicy',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | LOGOUT
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/logout',
        [
            AuthController::class,
            'logout',
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | ACTIVE ANNOUNCEMENTS - ALL AUTHENTICATED USERS
    |--------------------------------------------------------------------------
    |
    | Returns only published and currently active announcements intended
    | for the logged-in user's role or for everyone.
    |
    */

    Route::get(
        '/announcements',
        [
            AnnouncementController::class,
            'index',
        ]
    );

    Route::put(
        '/announcements/{id}/view',
        [
            AnnouncementController::class,
            'markViewed',
        ]
    );

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

        Route::get(
            '/items/qr-codes',
            [
                RegisteredItemController::class,
                'qrCodes',
            ]
        )->middleware(
            'permission:view_qr_codes'
        );
        
        Route::post(
            '/items',
            [
                RegisteredItemController::class,
                'store',
            ]
        )->middleware(
            'permission:register_items'
        );


        /*
        |--------------------------------------------------------------------------
        | Claim Found Item
        |--------------------------------------------------------------------------
        */

        Route::put(
            '/lost-found/{id}/claim',
            [
                LostFoundItemController::class,
                'claim',
            ]
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
            [
                RegisteredItemController::class,
                'pending',
            ]
        )->middleware(
            'permission:approve_requests'
        );


        /*
        |--------------------------------------------------------------------------
        | Approve Item Registration / Issue QR
        |--------------------------------------------------------------------------
        */

        Route::put(
            '/items/{id}/approve',
            [
                RegisteredItemController::class,
                'approve',
            ]
        )->middleware(
            'permission:approve_requests'
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
            [
                RegisteredItemController::class,
                'allItems',
            ]
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
        Route::put(
    '/lost-found/{id}',
    [LostFoundItemController::class, 'update']
);


        /*
        |--------------------------------------------------------------------------
        | QR Verification
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/items/verify',
            [
                RegisteredItemController::class,
                'verify',
            ]
        )->middleware(
            'permission:scan_verify'
        );


        /*
        |--------------------------------------------------------------------------
        | Create Scan Log
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/scan-logs',
            [
                ScanLogController::class,
                'store',
            ]
        )->middleware(
            'permission:scan_verify'
        );


        /*
        |--------------------------------------------------------------------------
        | Security Incidents
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/security-incidents',
            [
                SecurityIncidentController::class,
                'store',
            ]
        );

        Route::put(
            '/security-incidents/{id}/resolve',
            [
                SecurityIncidentController::class,
                'resolve',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Create Lost & Found Record
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/lost-found',
            [
                LostFoundItemController::class,
                'store',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Mark Lost & Found Item as Recovered
        |--------------------------------------------------------------------------
        */

        Route::put(
            '/lost-found/{id}/recovered',
            [
                LostFoundItemController::class,
                'markRecovered',
            ]
        );
    });


    /*
    |--------------------------------------------------------------------------
    | SECURITY REPORTS
    |--------------------------------------------------------------------------
    */

    Route::middleware([
        'role:security',
        'permission:view_reports',
    ])->group(function () {

        Route::get(
            '/security-reports',
            [
                ReportController::class,
                'security',
            ]
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
            [
                ScanLogController::class,
                'index',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Security Incidents
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/security-incidents',
            [
                SecurityIncidentController::class,
                'index',
            ]
        );
    });


    /*
    |--------------------------------------------------------------------------
    | LOST & FOUND REGISTRY
    |--------------------------------------------------------------------------
    |
    | Student:
    |   - Browse records
    |   - Claim found items
    |
    | Security:
    |   - Browse records
    |   - Create records
    |   - Process claims
    |   - Mark records recovered
    |
    | Students do NOT create Lost & Found reports.
    |
    */

    Route::middleware(
        'role:student,security'
    )->group(function () {

        Route::get(
            '/lost-found',
            [
                LostFoundItemController::class,
                'index',
            ]
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
            [
                NotificationController::class,
                'index',
            ]
        );

        Route::put(
            '/notifications/read-all',
            [
                NotificationController::class,
                'markAllRead',
            ]
        );

        Route::put(
            '/notifications/{id}/read',
            [
                NotificationController::class,
                'markRead',
            ]
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
            [
                DashboardController::class,
                'index',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Announcement Management
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/announcements/manage',
            [
                AnnouncementController::class,
                'adminIndex',
            ]
        );

        Route::post(
            '/announcements',
            [
                AnnouncementController::class,
                'store',
            ]
        );

        Route::put(
            '/announcements/{id}',
            [
                AnnouncementController::class,
                'update',
            ]
        );

        Route::put(
            '/announcements/{id}/publish',
            [
                AnnouncementController::class,
                'setPublished',
            ]
        );
        Route::delete(
            '/announcements/{id}',
            [
                AnnouncementController::class,
                'destroy',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | System Settings
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/system-settings',
            [
                SystemSettingController::class,
                'show',
            ]
        );

        Route::put(
            '/system-settings',
            [
                SystemSettingController::class,
                'update',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | System Records
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/system-records',
            [
                SystemRecordController::class,
                'index',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Reports & Analytics
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/reports',
            [
                ReportController::class,
                'index',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | User Accounts
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/users',
            [
                UserController::class,
                'index',
            ]
        )->middleware(
            'permission:manage_users'
        );
        
        Route::post(
            '/users',
            [
                UserController::class,
                'store',
            ]
        )->middleware(
            'permission:manage_users'
        );
        
        Route::put(
            '/users/{id}',
            [
                UserController::class,
                'update',
            ]
        )->middleware(
            'permission:manage_users'
        );
        
        Route::put(
            '/users/{id}/status',
            [
                UserController::class,
                'updateStatus',
            ]
        )->middleware(
            'permission:manage_users'
        );


        /*
        |--------------------------------------------------------------------------
        | Audit Logs
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/audit-logs',
            [
                AuditLogController::class,
                'index',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Active Sessions
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/active-sessions',
            [
                ActiveSessionController::class,
                'index',
            ]
        );

        Route::delete(
            '/active-sessions/{id}',
            [
                ActiveSessionController::class,
                'destroy',
            ]
        );

                    /*
            |--------------------------------------------------------------------------
            | PERFORMANCE MONITORING
            |--------------------------------------------------------------------------
            */

            Route::get(
                '/performance',
                [
                    PerformanceController::class,
                    'index',
                ]
            );


        /*
        |--------------------------------------------------------------------------
        | SECURITY CONFIGURATION / RBAC
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/role-permissions',
            [
                RolePermissionController::class,
                'index',
            ]
        );

        Route::put(
            '/role-permissions/{role}',
            [
                RolePermissionController::class,
                'update',
            ]
        );
    });
});