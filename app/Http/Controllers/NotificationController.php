<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // Get notifications for the logged-in user
    public function index(Request $request)
    {
        $notifications = Notification::where(
            'user_id',
            $request->user()->id
        )
            ->latest()
            ->get();

        $unreadCount = $notifications
            ->where('is_read', false)
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    // Mark one notification as read
    public function markRead(Request $request, $id)
    {
        $notification = Notification::where(
            'user_id',
            $request->user()->id
        )
            ->findOrFail($id);

        $notification->is_read = true;
        $notification->read_at = now();
        $notification->save();

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => $notification,
        ]);
    }

    // Mark all notifications as read
    public function markAllRead(Request $request)
    {
        Notification::where(
            'user_id',
            $request->user()->id
        )
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }
}