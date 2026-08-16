<?php

namespace App\Http\Controllers;

use App\Models\RegisteredItem;
use App\Models\ScanLog;
use App\Models\SecurityIncident;
use App\Models\LostFoundItem;

class SystemRecordController extends Controller
{
    public function index()
    {
        /*
        |--------------------------------------------------------------------------
        | ITEM REGISTRATION RECORDS
        |--------------------------------------------------------------------------
        */

        $registrations = RegisteredItem::with('user')
            ->latest()
            ->get()
            ->map(function ($item) {
                return [
                    'id' => 'registration-' . $item->id,
                    'record_type' => 'Item Registration',
                    'reference_id' => $item->id,

                    'title' => $item->item_name,

                    'description' =>
                        'Item registration submitted by ' .
                        ($item->user?->name ?? 'Unknown User'),

                    'user_name' =>
                        $item->user?->name ?? 'Unknown User',

                    'user_id' =>
                        $item->user?->username ?? null,

                    'status' => ucfirst(
                        $item->status ?? 'pending'
                    ),

                    'date' => $item->created_at,

                    'details' => [
                        'item_name' => $item->item_name,
                        'brand_model' => $item->brand_model,
                        'serial_number' => $item->serial_number,
                        'item_type' => $item->item_type,
                        'qr_code' => $item->qr_code,
                    ],
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | QR SCAN RECORDS
        |--------------------------------------------------------------------------
        */

        $scanLogs = ScanLog::with([
            'item.user',
            'scanner',
        ])
            ->latest('scanned_at')
            ->get()
            ->map(function ($log) {
                return [
                    'id' => 'scan-' . $log->id,
                    'record_type' => 'QR Scan',
                    'reference_id' => $log->id,

                    'title' =>
                        $log->item?->item_name ??
                        'Registered Item',

                    'description' =>
                        ($log->direction ?? 'QR') .
                        ' scan at ' .
                        ($log->gate ?? 'Campus Gate'),

                    'user_name' =>
                        $log->item?->user?->name ??
                        'Unknown User',

                    'user_id' =>
                        $log->item?->user?->username ??
                        null,

                    'status' =>
                        $log->result ?? 'Verified',

                    'date' =>
                        $log->scanned_at ??
                        $log->created_at,

                    'details' => [
                        'qr_code' => $log->qr_code,
                        'gate' => $log->gate,
                        'direction' => $log->direction,
                        'scanned_by' =>
                            $log->scanner?->name,
                    ],
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | SECURITY INCIDENT RECORDS
        |--------------------------------------------------------------------------
        */

        $incidents = SecurityIncident::with([
            'reporter',
            'item.user',
        ])
            ->latest('reported_at')
            ->get()
            ->map(function ($incident) {
                return [
                    'id' => 'incident-' . $incident->id,
                    'record_type' => 'Security Incident',
                    'reference_id' => $incident->id,

                    'title' =>
                        $incident->incident_type ??
                        'Security Incident',

                    'description' =>
                        $incident->description ??
                        'Security incident reported at ' .
                        ($incident->gate ?? 'Campus Gate'),

                    'user_name' =>
                        $incident->reporter?->name ??
                        'Security Personnel',

                    'user_id' =>
                        $incident->reporter?->username ??
                        null,

                    'status' =>
                        $incident->status ?? 'Flagged',

                    'date' =>
                        $incident->reported_at ??
                        $incident->created_at,

                    'details' => [
                        'scanned_code' =>
                            $incident->scanned_code,

                        'item_name' =>
                            $incident->item?->item_name ??
                            $incident->item_name,

                        'serial_number' =>
                            $incident->item?->serial_number ??
                            $incident->serial_number,

                        'gate' => $incident->gate,
                    ],
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | LOST & FOUND RECORDS
        |--------------------------------------------------------------------------
        */

        $lostFound = LostFoundItem::with([
            'reporter',
            'claimant',
        ])
            ->latest()
            ->get()
            ->map(function ($item) {
                return [
                    'id' => 'lost-found-' . $item->id,
                    'record_type' => 'Lost & Found',
                    'reference_id' => $item->id,

                    'title' => $item->item_name,

                    'description' =>
                        'Lost & Found item reported at ' .
                        ($item->location_found ??
                            'Unknown Location'),

                    'user_name' =>
                        $item->reporter?->name ??
                        'Unknown User',

                    'user_id' =>
                        $item->reporter?->username ??
                        null,

                    'status' =>
                        $item->status ?? 'Found',

                    'date' => $item->created_at,

                    'details' => [
                        'category' => $item->category,
                        'brand_model' => $item->brand_model,
                        'color' => $item->color,
                        'location_found' =>
                            $item->location_found,
                        'claimed_by' =>
                            $item->claimant?->name,
                    ],
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | COMBINE ALL RECORDS
        |--------------------------------------------------------------------------
        */

        $records = collect()
            ->concat($registrations)
            ->concat($scanLogs)
            ->concat($incidents)
            ->concat($lostFound)
            ->sortByDesc(function ($record) {
                return $record['date'];
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | SUMMARY
        |--------------------------------------------------------------------------
        */

        $thisMonth = $records->filter(function ($record) {
            if (!$record['date']) {
                return false;
            }

            return $record['date']->isSameMonth(now());
        })->count();

        $flagged = SecurityIncident::where(
            'status',
            'Flagged'
        )->count();

        return response()->json([
            'summary' => [
                'total_records' => $records->count(),
                'this_month' => $thisMonth,
                'flagged' => $flagged,
            ],

            'records' => $records,
        ]);
    }
}