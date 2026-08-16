<?php

namespace App\Http\Controllers;

use App\Models\SecurityIncident;
use Illuminate\Http\Request;

class SecurityIncidentController extends Controller
{
    // View all security incidents
    public function index()
    {
        $incidents = SecurityIncident::with([
            'reporter',
            'item.user',
        ])
            ->latest('reported_at')
            ->get();

        return response()->json([
            'incidents' => $incidents,
        ]);
    }

    // Create a flagged / unregistered item incident
    public function store(Request $request)
    {
        $request->validate([
            'registered_item_id' => 'nullable|exists:registered_items,id',
            'scanned_code' => 'nullable|string|max:255',
            'incident_type' => 'required|string|max:100',
            'item_name' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'gate' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
        ]);

        $incident = SecurityIncident::create([
            'reported_by' => $request->user()->id,
            'registered_item_id' => $request->registered_item_id,
            'scanned_code' => $request->scanned_code,
            'incident_type' => $request->incident_type,
            'item_name' => $request->item_name,
            'serial_number' => $request->serial_number,
            'gate' => $request->gate,
            'status' => 'Flagged',
            'description' => $request->description,
            'reported_at' => now(),
        ]);

        $incident->load([
            'reporter',
            'item.user',
        ]);

        return response()->json([
            'message' => 'Security incident recorded successfully.',
            'incident' => $incident,
        ], 201);
    }

    // Mark incident as resolved
    public function resolve($id)
    {
        $incident = SecurityIncident::findOrFail($id);

        $incident->status = 'Resolved';
        $incident->save();

        return response()->json([
            'message' => 'Security incident resolved successfully.',
            'incident' => $incident,
        ]);
    }
}