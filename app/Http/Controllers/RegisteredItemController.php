<?php

namespace App\Http\Controllers;

use App\Models\SecurityIncident;
use App\Models\RegisteredItem;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;

class RegisteredItemController extends Controller
{
    // =========================================================
    // STUDENT - VIEW OWN REGISTERED ITEMS
    // =========================================================

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

    // =========================================================
    // STUDENT - REGISTER NEW ITEM
    // =========================================================

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

            /*
            |--------------------------------------------------------------------------
            | VALID UNTIL
            |--------------------------------------------------------------------------
            |
            | Frontend sends this using:
            |
            | <input type="datetime-local" />
            |
            | It is stored in registered_items.qr_expires_at.
            |
            */

            'valid_until' =>
                'nullable|date',
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

            /*
            |--------------------------------------------------------------------------
            | QR EXPIRATION DATE + TIME
            |--------------------------------------------------------------------------
            */

            'qr_expires_at' =>
                $request->valid_until,
        ]);

        // =====================================================
        // NOTIFICATION FOR STUDENT
        // =====================================================

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

        // =====================================================
        // NOTIFICATIONS FOR ALL PCO STAFF
        // =====================================================

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

        return response()->json([
            'message' =>
                'Item registration submitted successfully.',

            'item' =>
                $item,
        ], 201);
    }

    // =========================================================
    // PCO - VIEW ALL PENDING ITEM REQUESTS
    // =========================================================

    public function pending()
    {
        $items = RegisteredItem::with('user')
            ->where(
                'status',
                'pending'
            )
            ->latest()
            ->get();

        return response()->json([
            'items' => $items,
        ]);
    }

    // =========================================================
    // PCO - VIEW ALL REGISTERED ITEMS
    // =========================================================

    public function allItems()
    {
        /*
        |--------------------------------------------------------------------------
        | GET FLAGGED REGISTERED ITEMS
        |--------------------------------------------------------------------------
        */

        $flaggedItemIds =
            SecurityIncident::where(
                'status',
                'Flagged'
            )
                ->whereNotNull(
                    'registered_item_id'
                )
                ->pluck(
                    'registered_item_id'
                )
                ->unique();

        /*
        |--------------------------------------------------------------------------
        | LOAD ITEM REGISTRY
        |--------------------------------------------------------------------------
        */

        $items = RegisteredItem::with('user')
            ->latest()
            ->get()
            ->map(
                function ($item) use (
                    $flaggedItemIds
                ) {
                    $isFlagged =
                        $flaggedItemIds->contains(
                            $item->id
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | EXPIRED
                    |--------------------------------------------------------------------------
                    |
                    | qr_expires_at now contains both date and time.
                    |
                    */

                    $isExpired =
                        $item->qr_expires_at &&
                        now()->greaterThan(
                            $item->qr_expires_at
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | ACTIVE QR
                    |--------------------------------------------------------------------------
                    */

                    $isActiveQr =
                        $item->status ===
                            'approved' &&
                        !empty(
                            $item->qr_code
                        ) &&
                        !$isExpired &&
                        !$isFlagged;

                    $item->is_flagged =
                        $isFlagged;

                    $item->is_expired =
                        $isExpired;

                    $item->is_active_qr =
                        $isActiveQr;

                    /*
                    |--------------------------------------------------------------------------
                    | REGISTRY STATUS
                    |--------------------------------------------------------------------------
                    */

                    if ($isFlagged) {
                        $item->registry_status =
                            'Flagged';
                    } elseif ($isExpired) {
                        $item->registry_status =
                            'Expired';
                    } elseif ($isActiveQr) {
                        $item->registry_status =
                            'Active';
                    } elseif (
                        $item->status ===
                        'pending'
                    ) {
                        $item->registry_status =
                            'Pending';
                    } else {
                        $item->registry_status =
                            ucfirst(
                                $item->status
                            );
                    }

                    return $item;
                }
            );

        return response()->json([
            'items' => $items,
        ]);
    }

    // =========================================================
    // PCO - APPROVE ITEM AND GENERATE QR CODE
    // =========================================================

    public function approve($id)
    {
        $item =
            RegisteredItem::findOrFail(
                $id
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

        $item->save();

        // =====================================================
        // NOTIFY ITEM OWNER
        // =====================================================

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
                '" has been approved. Your QR code is now available.',

            'is_read' =>
                false,

            'read_at' =>
                null,
        ]);

        return response()->json([
            'message' =>
                'Item approved successfully.',

            'item' =>
                $item,
        ]);
    }

    // =========================================================
    // SECURITY - VERIFY ITEM USING QR CODE OR SERIAL NUMBER
    // =========================================================

    public function verify(Request $request)
    {
        $request->validate([
            'code' =>
                'required|string',
        ]);

        $item =
            RegisteredItem::with(
                'user'
            )
                ->where(
                    'qr_code',
                    $request->code
                )
                ->orWhere(
                    'serial_number',
                    $request->code
                )
                ->first();

        /*
        |--------------------------------------------------------------------------
        | ITEM NOT FOUND
        |--------------------------------------------------------------------------
        */

        if (!$item) {
            return response()->json([
                'message' =>
                    'Item not found.',

                'verified' =>
                    false,
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | ITEM NOT APPROVED
        |--------------------------------------------------------------------------
        */

        if (
            $item->status !==
            'approved'
        ) {
            return response()->json([
                'message' =>
                    'Item is not approved.',

                'verified' =>
                    false,

                'item' =>
                    $item,
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | ITEM EXPIRED
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | VERIFIED
        |--------------------------------------------------------------------------
        */

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