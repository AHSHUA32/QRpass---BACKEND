<?php

namespace App\Http\Controllers;

use App\Models\RegisteredItem;
use App\Models\ScanLog;
use App\Models\SecurityIncident;
use App\Models\LostFoundItem;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // Main statistics
        $totalItems = RegisteredItem::count();

        $activeQrCodes = RegisteredItem::where('status', 'approved')
            ->whereNotNull('qr_code')
            ->count();

        $pendingRegistrations = RegisteredItem::where(
            'status',
            'pending'
        )->count();

        $scansToday = ScanLog::whereDate(
            'scanned_at',
            today()
        )->count();

        $flaggedIncidents = SecurityIncident::where(
            'status',
            'Flagged'
        )->count();

        $lostFoundItems = LostFoundItem::where(
            'status',
            'Found'
        )->count();

        // Item type statistics for chart
        $itemTypes = RegisteredItem::select(
            'item_type',
            DB::raw('COUNT(*) as total')
        )
            ->groupBy('item_type')
            ->orderByDesc('total')
            ->get();

        // Recent successful QR scans
        $recentScans = ScanLog::with([
            'item.user',
            'scanner',
        ])
            ->latest('scanned_at')
            ->limit(5)
            ->get();

        // Recent security incidents
        $recentIncidents = SecurityIncident::with([
            'reporter',
            'item.user',
        ])
            ->latest('reported_at')
            ->limit(5)
            ->get();

        return response()->json([
            'stats' => [
                'total_items' => $totalItems,
                'active_qr_codes' => $activeQrCodes,
                'pending_registrations' => $pendingRegistrations,
                'scans_today' => $scansToday,
                'flagged_incidents' => $flaggedIncidents,
                'lost_found_items' => $lostFoundItems,
            ],

            'item_types' => $itemTypes,

            'recent_scans' => $recentScans,

            'recent_incidents' => $recentIncidents,
        ]);
    }
}