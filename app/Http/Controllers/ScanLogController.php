<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
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

                        /*
            |--------------------------------------------------------------------------
            | Audit Log - QR Scan
            |--------------------------------------------------------------------------
            */

            AuditLogger::log(
                action: 'scan_item',
                description: $request->user()->name .
                    ' scanned "' .
                    $item->item_name .
                    '" at ' .
                    $request->gate .
                    ' (' .
                    $request->direction .
                    ').',
                eventType: 'qr_scan',
                module: 'QR Verification',
                status: 'success',
                metadata: [
                    'scan_log_id' => $log->id,
                    'item_id' => $item->id,
                    'item_name' => $item->item_name,
                    'item_type' => $item->item_type,
                    'serial_number' => $item->serial_number,
                    'qr_code' => $item->qr_code,
                    'gate' => $request->gate,
                    'direction' => $request->direction,
                    'result' => 'Verified',
                ],
                user: $request->user()
            );

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