<?php

namespace App\Http\Controllers;

use App\Models\RegisteredItem;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;

class RegisteredItemController extends Controller
{
    // Student - view own registered items
    public function index(Request $request)
    {
        $items = RegisteredItem::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'items' => $items
        ]);
    }

    // Student - register a new item
    public function store(Request $request)
    {
        $request->validate([
            'item_name' => 'required|string|max:255',
            'brand_model' => 'nullable|string|max:255',
            'serial_number' => 'required|string|max:255|unique:registered_items,serial_number',
            'color' => 'nullable|string|max:100',
            'item_type' => 'required|string|max:100',
            'purpose' => 'nullable|string|max:255',
        ]);

        $item = RegisteredItem::create([
            'user_id' => $request->user()->id,
            'item_name' => $request->item_name,
            'brand_model' => $request->brand_model,
            'serial_number' => $request->serial_number,
            'color' => $request->color,
            'item_type' => $request->item_type,
            'purpose' => $request->purpose,
            'status' => 'pending',
            'qr_code' => null,
        ]);

        // Notification for the student
        Notification::create([
            'user_id' => $request->user()->id,
            'type' => 'item_submitted',
            'title' => 'Item Registration Submitted',
            'message' =>
                'Your item "' .
                $item->item_name .
                '" has been submitted and is waiting for PCO approval.',
            'is_read' => false,
            'read_at' => null,
        ]);

        // Notifications for all PCO staff
<<<<<<< HEAD
        $pcoUsers = User::where('role', 'sao')->get();
=======
        $pcoUsers = User::where('role', 'pco')->get();
>>>>>>> 6354b62 (Standardize PCO role and update QRPass backend)

        foreach ($pcoUsers as $pcoUser) {
            Notification::create([
                'user_id' => $pcoUser->id,
                'type' => 'new_item_registration',
                'title' => 'New Item Registration',
                'message' =>
                    $request->user()->name .
                    ' submitted "' .
                    $item->item_name .
                    '" for PCO approval.',
                'is_read' => false,
                'read_at' => null,
            ]);
        }

        return response()->json([
            'message' => 'Item registration submitted successfully.',
            'item' => $item,
        ], 201);
    }
    // PCO - view all pending item requests
    public function pending()
    {
        $items = RegisteredItem::with('user')
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json([
            'items' => $items
        ]);
    }

    // PCO - view all registered items
    public function allItems()
    {
        $items = RegisteredItem::with('user')
            ->latest()
            ->get();

        return response()->json([
            'items' => $items
        ]);
    }

    // PCO - approve item and generate QR code
    public function approve($id)
{
    $item = RegisteredItem::findOrFail($id);

    $item->status = 'approved';

    $item->qr_code =
        'QRPASS-' . str_pad(
            $item->id,
            5,
            '0',
            STR_PAD_LEFT
        );

    $item->save();

    Notification::create([
        'user_id' => $item->user_id,
        'type' => 'item_approved',
        'title' => 'Item Registration Approved',
        'message' =>
            'Your item "' .
            $item->item_name .
            '" has been approved. Your QR code is now available.',
        'is_read' => false,
        'read_at' => null,
    ]);

    return response()->json([
        'message' => 'Item approved successfully.',
        'item' => $item,
    ]);
}
    // Security - verify item using QR code or serial number
    public function verify(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $item = RegisteredItem::with('user')
            ->where('qr_code', $request->code)
            ->orWhere('serial_number', $request->code)
            ->first();

        if (!$item) {
            return response()->json([
                'message' => 'Item not found.',
                'verified' => false,
            ], 404);
        }

        if ($item->status !== 'approved') {
            return response()->json([
                'message' => 'Item is not approved.',
                'verified' => false,
                'item' => $item,
            ], 403);
        }

        return response()->json([
            'message' => 'Item verified successfully.',
            'verified' => true,
            'item' => $item,
        ]);
    }
}