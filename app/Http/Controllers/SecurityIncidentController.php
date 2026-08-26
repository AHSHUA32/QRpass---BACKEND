<?php

namespace App\Http\Controllers;

use App\Models\SecurityIncident;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SecurityIncidentController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | View All Security Incidents
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | Create Security Incident
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        $request->validate([
            'registered_item_id' =>
                'nullable|exists:registered_items,id',

            'scanned_code' =>
                'nullable|string|max:255',

            'incident_type' =>
                'required|string|max:100',

            'item_name' =>
                'nullable|string|max:255',

            'serial_number' =>
                'nullable|string|max:255',

            'gate' =>
                'required|string|max:100',

            'description' =>
                'nullable|string|max:1000',
        ]);

        $incident = SecurityIncident::create([
            'reported_by' =>
                $request->user()->id,

            'registered_item_id' =>
                $request->registered_item_id,

            'scanned_code' =>
                $request->scanned_code,

            'incident_type' =>
                $request->incident_type,

            'item_name' =>
                $request->item_name,

            'serial_number' =>
                $request->serial_number,

            'gate' =>
                $request->gate,

            'status' =>
                'Flagged',

            'description' =>
                $request->description,

            'reported_at' =>
                now(),
        ]);

        $incident->load([
            'reporter',
            'item.user',
        ]);


        /*
        |--------------------------------------------------------------------------
        | Audit Log - Security Incident Created
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action: 'create_security_incident',

            description:
                $request->user()->name .
                ' recorded a security incident at ' .
                $incident->gate .
                '.',

            eventType: 'security_incident',

            module: 'Security Incidents',

            status: 'warning',

            metadata: [
                'incident_id' =>
                    $incident->id,

                'incident_type' =>
                    $incident->incident_type,

                'registered_item_id' =>
                    $incident->registered_item_id,

                'scanned_code' =>
                    $incident->scanned_code,

                'item_name' =>
                    $incident->item_name,

                'serial_number' =>
                    $incident->serial_number,

                'gate' =>
                    $incident->gate,

                'incident_status' =>
                    $incident->status,
            ],

            user: $request->user()
        );


        return response()->json([
            'message' =>
                'Security incident recorded successfully.',

            'incident' =>
                $incident,
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | Resolve Security Incident
    |--------------------------------------------------------------------------
    */

    public function resolve($id)
    {
        $incident =
            SecurityIncident::findOrFail($id);

        $incident->status =
            'Resolved';

        $incident->save();


        /*
        |--------------------------------------------------------------------------
        | Audit Log - Security Incident Resolved
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action: 'resolve_security_incident',

            description:
                auth()->user()->name .
                ' resolved security incident #' .
                $incident->id .
                '.',

            eventType: 'status_change',

            module: 'Security Incidents',

            status: 'success',

            metadata: [
                'incident_id' =>
                    $incident->id,

                'incident_type' =>
                    $incident->incident_type,

                'registered_item_id' =>
                    $incident->registered_item_id,

                'scanned_code' =>
                    $incident->scanned_code,

                'item_name' =>
                    $incident->item_name,

                'serial_number' =>
                    $incident->serial_number,

                'gate' =>
                    $incident->gate,

                'incident_status' =>
                    $incident->status,
            ],

            user: auth()->user()
        );


        return response()->json([
            'message' =>
                'Security incident resolved successfully.',

            'incident' =>
                $incident,
        ]);
    }
}