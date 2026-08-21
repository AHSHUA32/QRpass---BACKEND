<?php

namespace App\Http\Controllers;

use App\Models\RegisteredItem;
use App\Models\ScanLog;
use Illuminate\Http\Request;

class ScanLogController extends Controller
{
    // Security - save an IN or OUT scan
    public function store(Request $request)
    {
        $request->validate([
            'registered_item_id' => 'required|exists:registered_items,id',
            'gate' => 'required|string|max:100',
            'direction' => 'required|in:IN,OUT',
        ]);

        $item = RegisteredItem::findOrFail(
            $request->registered_item_id
        );

        if ($item->status !== 'approved') {
            return response()->json([
                'message' => 'Only approved items can be logged.',
            ], 403);
        }

        $log = ScanLog::create([
            'registered_item_id' => $item->id,
            'scanned_by' => $request->user()->id,
            'qr_code' => $item->qr_code,
            'gate' => $request->gate,
            'direction' => $request->direction,
            'result' => 'Verified',
            'scanned_at' => now(),
        ]);

        return response()->json([
            'message' => 'Scan logged successfully.',
            'log' => $log,
        ], 201);
    }

    // Security - view scan logs
    public function index()
    {
        $logs = ScanLog::with([
            'item.user',
            'scanner',
        ])
            ->latest('scanned_at')
            ->get();

        return response()->json([
            'logs' => $logs,
        ]);
    }
}