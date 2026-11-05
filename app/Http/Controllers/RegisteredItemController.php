<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\RegisteredItem;
use App\Models\SecurityIncident;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class RegisteredItemController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | STUDENT - VIEW OWN REGISTERED ITEMS
    |--------------------------------------------------------------------------
    */
    public function index(Request $request)
    {
        $items = RegisteredItem::where(
            'user_id',
            $request->user()->id
        )
            ->latest()
            ->get();

        return response()->json([
            'items' => $items,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | STUDENT - VIEW OWN QR CODES
    |--------------------------------------------------------------------------
    */
    public function qrCodes(Request $request)
    {
        $items = RegisteredItem::where(
            'user_id',
            $request->user()->id
        )
            ->where(
                'status',
                'approved'
            )
            ->whereNotNull(
                'qr_code'
            )
            ->where(
                function ($query) {
                    $query
                        ->whereNull(
                            'qr_expires_at'
                        )
                        ->orWhere(
                            'qr_expires_at',
                            '>',
                            now()
                        );
                }
            )
            ->latest()
            ->get();

        return response()->json([
            'items' => $items,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | STUDENT - REGISTER NEW ITEM
    |--------------------------------------------------------------------------
    |
    | Students submit only the item information. They do NOT control the permit
    | expiration. qr_expires_at remains null while the request is pending and
    | is assigned only when PCO/admin approves the item.
    |
    */
    public function store(Request $request)
    {
        $request->validate([
            'item_name' =>
                'required|string|max:255',

            'brand_model' =>
                'nullable|string|max:255',

            'serial_number' =>
                'nullable|string|max:255|unique:registered_items,serial_number',

            'color' =>
                'nullable|string|max:100',

            'item_type' =>
                'required|string|max:100',

            'purpose' =>
                'nullable|string|max:255',
        ]);

        $item = RegisteredItem::create([
            'user_id' =>
                $request->user()->id,

            'item_name' =>
                $request->item_name,

            'brand_model' =>
                $request->brand_model,

            'serial_number' =>
                $request->serial_number,

            'color' =>
                $request->color,

            'item_type' =>
                $request->item_type,

            'purpose' =>
                $request->purpose,

            'status' =>
                'pending',

            'qr_code' =>
                null,

            // Only PCO/admin approval may assign the permit expiration.
            'qr_expires_at' =>
                null,
        ]);

        Notification::create([
            'user_id' =>
                $request->user()->id,

            'type' =>
                'item_submitted',

            'title' =>
                'Item Registration Submitted',

            'message' =>
                'Your item "' .
                $item->item_name .
                '" has been submitted and is waiting for PCO approval.',

            'is_read' =>
                false,

            'read_at' =>
                null,
        ]);

        $pcoUsers = User::where(
            'role',
            'pco'
        )->get();

        foreach ($pcoUsers as $pcoUser) {
            Notification::create([
                'user_id' =>
                    $pcoUser->id,

                'type' =>
                    'new_item_registration',

                'title' =>
                    'New Item Registration',

                'message' =>
                    $request->user()->name .
                    ' submitted "' .
                    $item->item_name .
                    '" for PCO approval.',

                'is_read' =>
                    false,

                'read_at' =>
                    null,
            ]);
        }

        AuditLogger::log(
            action:
                'register_item',

            description:
                $request->user()->name .
                ' submitted item "' .
                $item->item_name .
                '" for registration.',

            eventType:
                'create',

            module:
                'Item Registration',

            status:
                'success',

            metadata: [
                'item_id' =>
                    $item->id,

                'item_name' =>
                    $item->item_name,

                'item_type' =>
                    $item->item_type,

                'serial_number' =>
                    $item->serial_number,

                'registration_status' =>
                    $item->status,
            ],

            user:
                $request->user()
        );

        return response()->json([
            'message' =>
                'Item registration submitted successfully.',

            'item' =>
                $item,
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | STUDENT - DELETE PENDING OR EXPIRED PERMIT
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        $id
    ) {
        $item =
            RegisteredItem::where(
                'id',
                $id
            )
                ->where(
                    'user_id',
                    $request->user()->id
                )
                ->firstOrFail();

        $isPending =
            $item->status ===
            'pending';

        $isExpired =
            $item->status ===
                'approved' &&
            !empty(
                $item->qr_expires_at
            ) &&
            now()->greaterThanOrEqualTo(
                $item->qr_expires_at
            );

        if (
            !$isPending &&
            !$isExpired
        ) {
            return response()->json([
                'message' =>
                    'Only Pending or Expired permits can be deleted.',
            ], 422);
        }

        $displayStatus =
            $isExpired
                ? 'Expired'
                : 'Pending';

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG BEFORE DELETE
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'delete_item_permit',

            description:
                $request->user()->name .
                ' deleted ' .
                strtolower(
                    $displayStatus
                ) .
                ' permit for item "' .
                $item->item_name .
                '".',

            eventType:
                'delete',

            module:
                'Item Registration',

            status:
                'success',

            metadata: [
                'item_id' =>
                    $item->id,

                'item_name' =>
                    $item->item_name,

                'item_type' =>
                    $item->item_type,

                'serial_number' =>
                    $item->serial_number,

                'permit_state' =>
                    $displayStatus,

                'qr_code' =>
                    $item->qr_code,

                'qr_expires_at' =>
                    $item->qr_expires_at,
            ],

            user:
                $request->user()
        );

        $item->delete();

        return response()->json([
            'message' =>
                $displayStatus .
                ' permit deleted successfully.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | PCO - VIEW ALL PENDING ITEM REQUESTS
    |--------------------------------------------------------------------------
    */
    public function pending()
    {
        $items = RegisteredItem::with('user')
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json([
            'items' => $items,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PCO / SYSTEM ADMIN - VIEW ITEM REGISTRY
    |--------------------------------------------------------------------------
    */
    public function allItems()
    {
        $flaggedItemIds = SecurityIncident::where(
            'status',
            'Flagged'
        )
            ->whereNotNull('registered_item_id')
            ->pluck('registered_item_id')
            ->unique();

        $items = RegisteredItem::with('user')
            ->latest()
            ->get()
            ->map(
                function ($item) use ($flaggedItemIds) {
                    $isFlagged = $flaggedItemIds->contains(
                        $item->id
                    );

                    $isExpired =
                        $item->qr_expires_at &&
                        now()->greaterThan(
                            $item->qr_expires_at
                        );

                    $isActiveQr =
                        $item->status === 'approved' &&
                        !empty($item->qr_code) &&
                        !$isExpired &&
                        !$isFlagged;

                    $item->is_flagged =
                        $isFlagged;

                    $item->is_expired =
                        $isExpired;

                    $item->is_active_qr =
                        $isActiveQr;

                    if ($isFlagged) {
                        $item->registry_status =
                            'Flagged';
                    } elseif ($isExpired) {
                        $item->registry_status =
                            'Expired';
                    } elseif ($isActiveQr) {
                        $item->registry_status =
                            'Active';
                    } elseif ($item->status === 'pending') {
                        $item->registry_status =
                            'Pending';
                    } else {
                        $item->registry_status =
                            ucfirst($item->status);
                    }

                    return $item;
                }
            );

        return response()->json([
            'items' => $items,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PCO - APPROVE ITEM AND GENERATE QR CODE
    |--------------------------------------------------------------------------
    |
    | This is the ONLY stage that assigns the QR/permit expiration. The number
    | of validity months comes from the admin-controlled System Settings.
    |
    */
    public function approve($id)
    {
        $item = RegisteredItem::findOrFail($id);

        if (
            $item->status === 'approved' &&
            !empty($item->qr_code)
        ) {
            return response()->json([
                'message' =>
                    'This item has already been approved.',

                'item' =>
                    $item,
            ], 422);
        }

        $settings = SystemSetting::first();

        $validityMonths = max(
            1,
            (int) (
                $settings
                    ?->qr_code_validity_months ??
                6
            )
        );

        $item->status =
            'approved';

        $item->qr_code =
            'QRPASS-' .
            str_pad(
                $item->id,
                5,
                '0',
                STR_PAD_LEFT
            );

        $item->qr_expires_at =
            now()->addMonths(
                $validityMonths
            );

        $item->save();

        Notification::create([
            'user_id' =>
                $item->user_id,

            'type' =>
                'item_approved',

            'title' =>
                'Item Registration Approved',

            'message' =>
                'Your item "' .
                $item->item_name .
                '" has been approved. Your QR code is now available and is valid for ' .
                $validityMonths .
                ' month' .
                (
                    $validityMonths === 1
                        ? ''
                        : 's'
                ) .
                '.',

            'is_read' =>
                false,

            'read_at' =>
                null,
        ]);

        AuditLogger::log(
            action:
                'approve_item',

            description:
                'Approved item "' .
                $item->item_name .
                '" and issued QR code ' .
                $item->qr_code .
                '.',

            eventType:
                'approval',

            module:
                'Item Registration',

            status:
                'success',

            metadata: [
                'item_id' =>
                    $item->id,

                'item_name' =>
                    $item->item_name,

                'item_type' =>
                    $item->item_type,

                'serial_number' =>
                    $item->serial_number,

                'qr_code' =>
                    $item->qr_code,

                'qr_validity_months' =>
                    $validityMonths,

                'qr_expires_at' =>
                    $item->qr_expires_at,

                'owner_id' =>
                    $item->user_id,

                'registration_status' =>
                    $item->status,
            ],

            user:
                auth()->user()
        );

        return response()->json([
            'message' =>
                'Item approved successfully.',

            'qr_validity_months' =>
                $validityMonths,

            'qr_expires_at' =>
                $item->qr_expires_at,

            'item' =>
                $item,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SECURITY - VERIFY ITEM USING QR CODE OR SERIAL NUMBER
    |--------------------------------------------------------------------------
    */
    public function verify(Request $request)
    {
        $request->validate([
            'code' =>
                'required|string',
        ]);

        $item = RegisteredItem::with('user')
            ->where(
                'qr_code',
                $request->code
            )
            ->orWhere(
                'serial_number',
                $request->code
            )
            ->first();

        if (!$item) {
            return response()->json([
                'message' =>
                    'Item not found.',

                'verified' =>
                    false,
            ], 404);
        }

        if ($item->status !== 'approved') {
            return response()->json([
                'message' =>
                    'Item is not approved.',

                'verified' =>
                    false,

                'item' =>
                    $item,
            ], 403);
        }

        if (empty($item->qr_code)) {
            return response()->json([
                'message' =>
                    'This item does not have an active QR code.',

                'verified' =>
                    false,

                'item' =>
                    $item,
            ], 403);
        }

        if (
            $item->qr_expires_at &&
            now()->greaterThan(
                $item->qr_expires_at
            )
        ) {
            return response()->json([
                'message' =>
                    'The QR permit for this item has expired.',

                'verified' =>
                    false,

                'item' =>
                    $item,
            ], 403);
        }

        $isFlagged = SecurityIncident::where(
            'registered_item_id',
            $item->id
        )
            ->where('status', 'Flagged')
            ->exists();

        if ($isFlagged) {
            return response()->json([
                'message' =>
                    'This item is currently flagged for security review.',

                'verified' =>
                    false,

                'item' =>
                    $item,
            ], 403);
        }

        return response()->json([
            'message' =>
                'Item verified successfully.',

            'verified' =>
                true,

            'item' =>
                $item,
        ]);
    }
}
