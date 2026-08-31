<?php

namespace App\Http\Controllers;

use App\Models\LostFoundItem;
use App\Models\Notification;
use App\Models\RegisteredItem;
use App\Models\SecurityIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | GET NOTIFICATIONS FOR LOGGED-IN USER
    |--------------------------------------------------------------------------
    |
    | Student and PCO notifications are already created directly by the
    | existing workflow controllers.
    |
    | CSU/Security and SysAdmin did not previously receive notification rows.
    | Before returning the feed, QRPass now synchronizes recent system activity
    | into persistent database notifications for those two roles.
    |
    | The synchronization is idempotent because every generated notification
    | uses a deterministic type containing the source record ID and status.
    |
    */

    public function index(Request $request)
    {
        $user =
            $request->user();

        if (!$user) {
            return response()->json([
                'message' =>
                    'Unauthenticated.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | SYNCHRONIZE ROLE-SPECIFIC NOTIFICATIONS
        |--------------------------------------------------------------------------
        */

        if ($user->role === 'security') {
            $this->syncSecurityNotifications(
                $user
            );
        }

        if ($user->role === 'sysadmin') {
            $this->syncSysAdminNotifications(
                $user
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RETURN USER NOTIFICATIONS
        |--------------------------------------------------------------------------
        */

        $notificationsQuery =
            Notification::where(
                'user_id',
                $user->id
            );

        // System Administrators receive system activity notifications,
        // but never announcement notifications.
        if ($user->role === 'sysadmin') {
            $notificationsQuery->where(
                'type',
                '!=',
                'announcement'
            );
        }

        $notifications =
            $notificationsQuery
                ->latest()
                ->get();

        $unreadCount =
            $notifications
                ->where(
                    'is_read',
                    false
                )
                ->count();

        return response()->json([
            'notifications' =>
                $notifications,

            'unread_count' =>
                $unreadCount,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | MARK ONE NOTIFICATION AS READ
    |--------------------------------------------------------------------------
    */

    public function markRead(
        Request $request,
        $id
    ) {
        $notification =
            Notification::where(
                'user_id',
                $request->user()->id
            )
                ->findOrFail(
                    $id
                );

        if (!$notification->is_read) {
            $notification->is_read =
                true;

            $notification->read_at =
                now();

            $notification->save();
        }

        return response()->json([
            'message' =>
                'Notification marked as read.',

            'notification' =>
                $notification,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | MARK ALL NOTIFICATIONS AS READ
    |--------------------------------------------------------------------------
    */

    public function markAllRead(
        Request $request
    ) {
        Notification::where(
            'user_id',
            $request->user()->id
        )
            ->where(
                'is_read',
                false
            )
            ->update([
                'is_read' =>
                    true,

                'read_at' =>
                    now(),
            ]);

        return response()->json([
            'message' =>
                'All notifications marked as read.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CSU / SECURITY NOTIFICATION SYNCHRONIZATION
    |--------------------------------------------------------------------------
    */

    private function syncSecurityNotifications(
        User $user
    ): void {
        /*
        |--------------------------------------------------------------------------
        | RECENT APPROVED QR PERMITS
        |--------------------------------------------------------------------------
        |
        | CSU needs to know when a newly-approved QR permit becomes available
        | for gate verification.
        |
        */

        $approvedItems =
            RegisteredItem::where(
                'status',
                'approved'
            )
                ->whereNotNull(
                    'qr_code'
                )
                ->where(
                    'updated_at',
                    '>=',
                    now()->subDays(7)
                )
                ->latest(
                    'updated_at'
                )
                ->limit(30)
                ->get();

        foreach (
            $approvedItems as
            $item
        ) {
            $this->createRoleNotification(
                user:
                    $user,

                type:
                    'security_item_ready_' .
                    $item->id,

                title:
                    'QR Permit Ready for Verification',

                message:
                    'Item "' .
                    $item->item_name .
                    '" with QR ID ' .
                    (
                        $item->qr_code ??
                        'N/A'
                    ) .
                    ' has been approved and is ready for CSU gate verification.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SECURITY INCIDENTS
        |--------------------------------------------------------------------------
        */

        $incidents =
            SecurityIncident::where(
                'updated_at',
                '>=',
                now()->subDays(7)
            )
                ->latest(
                    'updated_at'
                )
                ->limit(30)
                ->get();

        foreach (
            $incidents as
            $incident
        ) {
            $status =
                strtolower(
                    trim(
                        (string) (
                            $incident->status ??
                            'open'
                        )
                    )
                );

            $incidentLabel =
                $this->firstNonEmpty([
                    $incident->incident_type ??
                        null,

                    $incident->type ??
                        null,

                    $incident->reason ??
                        null,

                    'Security incident',
                ]);

            $this->createRoleNotification(
                user:
                    $user,

                type:
                    'security_incident_' .
                    $incident->id .
                    '_' .
                    $this->safeTypePart(
                        $status
                    ),

                title:
                    $status ===
                    'resolved'
                        ? 'Security Incident Resolved'
                        : 'Security Incident Update',

                message:
                    $incidentLabel .
                    ' — Status: ' .
                    ucfirst(
                        $status
                    ) .
                    '.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | LOST & FOUND ACTIVITY
        |--------------------------------------------------------------------------
        */

        $lostFoundItems =
            LostFoundItem::where(
                'updated_at',
                '>=',
                now()->subDays(7)
            )
                ->latest(
                    'updated_at'
                )
                ->limit(30)
                ->get();

        foreach (
            $lostFoundItems as
            $lostFound
        ) {
            $status =
                strtolower(
                    trim(
                        (string) (
                            $lostFound->status ??
                            'open'
                        )
                    )
                );

            $reportType =
                strtolower(
                    trim(
                        (string) (
                            $lostFound->report_type ??
                            'found'
                        )
                    )
                );

            $itemName =
                $this->firstNonEmpty([
                    $lostFound->item_name ??
                        null,

                    'Lost & Found item',
                ]);

            $this->createRoleNotification(
                user:
                    $user,

                type:
                    'security_lost_found_' .
                    $lostFound->id .
                    '_' .
                    $this->safeTypePart(
                        $status
                    ),

                title:
                    'Lost & Found Update',

                message:
                    ucfirst(
                        $reportType
                    ) .
                    ' item "' .
                    $itemName .
                    '" — Status: ' .
                    ucfirst(
                        $status
                    ) .
                    '.'
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SYSTEM ADMINISTRATOR NOTIFICATION SYNCHRONIZATION
    |--------------------------------------------------------------------------
    */

    private function syncSysAdminNotifications(
        User $user
    ): void {
        /*
        |--------------------------------------------------------------------------
        | NEW ITEM REGISTRATIONS
        |--------------------------------------------------------------------------
        */

        $registeredItems =
            RegisteredItem::where(
                'created_at',
                '>=',
                now()->subDays(7)
            )
                ->latest(
                    'created_at'
                )
                ->limit(30)
                ->get();

        foreach (
            $registeredItems as
            $item
        ) {
            $this->createRoleNotification(
                user:
                    $user,

                type:
                    'admin_item_registration_' .
                    $item->id,

                title:
                    'New Item Registration',

                message:
                    'Item "' .
                    $item->item_name .
                    '" was registered in QRPass. Current status: ' .
                    ucfirst(
                        (string) (
                            $item->status ??
                            'pending'
                        )
                    ) .
                    '.'
            );


            /*
            |--------------------------------------------------------------------------
            | APPROVED / QR ISSUED EVENT
            |--------------------------------------------------------------------------
            */

            if (
                $item->status ===
                    'approved' &&
                !empty(
                    $item->qr_code
                )
            ) {
                $this->createRoleNotification(
                    user:
                        $user,

                    type:
                        'admin_item_approved_' .
                        $item->id,

                    title:
                        'Item Approved & QR Issued',

                    message:
                        'Item "' .
                        $item->item_name .
                        '" was approved and issued QR ID ' .
                        $item->qr_code .
                        '.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | SECURITY INCIDENTS
        |--------------------------------------------------------------------------
        */

        $incidents =
            SecurityIncident::where(
                'updated_at',
                '>=',
                now()->subDays(7)
            )
                ->latest(
                    'updated_at'
                )
                ->limit(30)
                ->get();

        foreach (
            $incidents as
            $incident
        ) {
            $status =
                strtolower(
                    trim(
                        (string) (
                            $incident->status ??
                            'open'
                        )
                    )
                );

            $incidentLabel =
                $this->firstNonEmpty([
                    $incident->incident_type ??
                        null,

                    $incident->type ??
                        null,

                    $incident->reason ??
                        null,

                    'Security incident',
                ]);

            $this->createRoleNotification(
                user:
                    $user,

                type:
                    'admin_security_incident_' .
                    $incident->id .
                    '_' .
                    $this->safeTypePart(
                        $status
                    ),

                title:
                    'Security Incident Activity',

                message:
                    $incidentLabel .
                    ' — Status: ' .
                    ucfirst(
                        $status
                    ) .
                    '.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | LOST & FOUND ACTIVITY
        |--------------------------------------------------------------------------
        */

        $lostFoundItems =
            LostFoundItem::where(
                'updated_at',
                '>=',
                now()->subDays(7)
            )
                ->latest(
                    'updated_at'
                )
                ->limit(30)
                ->get();

        foreach (
            $lostFoundItems as
            $lostFound
        ) {
            $status =
                strtolower(
                    trim(
                        (string) (
                            $lostFound->status ??
                            'open'
                        )
                    )
                );

            $reportType =
                strtolower(
                    trim(
                        (string) (
                            $lostFound->report_type ??
                            'found'
                        )
                    )
                );

            $itemName =
                $this->firstNonEmpty([
                    $lostFound->item_name ??
                        null,

                    'Lost & Found item',
                ]);

            $this->createRoleNotification(
                user:
                    $user,

                type:
                    'admin_lost_found_' .
                    $lostFound->id .
                    '_' .
                    $this->safeTypePart(
                        $status
                    ),

                title:
                    'Lost & Found Activity',

                message:
                    ucfirst(
                        $reportType
                    ) .
                    ' item "' .
                    $itemName .
                    '" — Status: ' .
                    ucfirst(
                        $status
                    ) .
                    '.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | RECENT USER ACCOUNTS
        |--------------------------------------------------------------------------
        */

        $users =
            User::where(
                'created_at',
                '>=',
                now()->subDays(7)
            )
                ->where(
                    'id',
                    '!=',
                    $user->id
                )
                ->latest(
                    'created_at'
                )
                ->limit(30)
                ->get();

        foreach (
            $users as
            $account
        ) {
            $this->createRoleNotification(
                user:
                    $user,

                type:
                    'admin_user_account_' .
                    $account->id,

                title:
                    'User Account Activity',

                message:
                    $account->name .
                    ' (' .
                    $account->username .
                    ') has a QRPass ' .
                    $account->role .
                    ' account with status ' .
                    ucfirst(
                        (string) (
                            $account->status ??
                            'approved'
                        )
                    ) .
                    '.'
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE ONE DEDUPLICATED ROLE NOTIFICATION
    |--------------------------------------------------------------------------
    */

    private function createRoleNotification(
        User $user,
        string $type,
        string $title,
        string $message
    ): void {
        Notification::firstOrCreate(
            [
                'user_id' =>
                    $user->id,

                'type' =>
                    $type,
            ],
            [
                'title' =>
                    $title,

                'message' =>
                    $message,

                'is_read' =>
                    false,

                'read_at' =>
                    null,
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SAFE TYPE PART
    |--------------------------------------------------------------------------
    */

    private function safeTypePart(
        string $value
    ): string {
        $value =
            strtolower(
                trim(
                    $value
                )
            );

        $value =
            preg_replace(
                '/[^a-z0-9]+/',
                '_',
                $value
            ) ??
            '';

        $value =
            trim(
                $value,
                '_'
            );

        return $value !== ''
            ? $value
            : 'unknown';
    }


    /*
    |--------------------------------------------------------------------------
    | FIRST NON-EMPTY STRING
    |--------------------------------------------------------------------------
    */

    private function firstNonEmpty(
        array $values
    ): string {
        foreach (
            $values as
            $value
        ) {
            if (
                $value ===
                null
            ) {
                continue;
            }

            $string =
                trim(
                    (string) $value
                );

            if (
                $string !==
                ''
            ) {
                return $string;
            }
        }

        return 'QRPass activity';
    }
}
