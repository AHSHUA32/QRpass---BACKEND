<?php

namespace App\Http\Controllers;

use App\Models\SecurityIncident;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SecurityIncidentController extends Controller
{
    public function index()
    {
        $incidents = SecurityIncident::with([
            'reporter',
            'resolver',
            'item.user',
        ])
            ->latest('reported_at')
            ->get();

        return response()->json([
            'incidents' => $incidents,
        ]);
    }

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

            'owner_name' =>
                'nullable|string|max:255',

            'owner_id_number' =>
                'nullable|string|max:100',

            'carrier_name' =>
                'nullable|string|max:255',

            'carrier_id_number' =>
                'nullable|string|max:100',

            'direction' =>
                'nullable|in:IN,OUT',

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

            'owner_name' =>
                $request->owner_name,

            'owner_id_number' =>
                $request->owner_id_number,

            'carrier_name' =>
                $request->carrier_name,

            'carrier_id_number' =>
                $request->carrier_id_number,

            'direction' =>
                $request->direction,

            'gate' =>
                $request->gate,

            'status' =>
                'Flagged',

            'description' =>
                $request->description,

            'reported_at' =>
                now(),

            'resolution_type' =>
                null,

            'resolution_details' =>
                null,

            'resolved_by' =>
                null,

            'resolved_at' =>
                null,
        ]);

        $incident->load([
            'reporter',
            'resolver',
            'item.user',
        ]);

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

                'owner_name' =>
                    $incident->owner_name,

                'owner_id_number' =>
                    $incident->owner_id_number,

                'carrier_name' =>
                    $incident->carrier_name,

                'carrier_id_number' =>
                    $incident->carrier_id_number,

                'direction' =>
                    $incident->direction,

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

    public function resolve(
        Request $request,
        $id
    ) {
        $validated = $request->validate([
            'resolution_type' => [
                'required',
                'string',
                'in:Ownership Verified,Returned to Owner,Authorized by Owner,Item Held by Security,Registration Required,Invalid QR / Manual Verification,Other',
            ],

            'resolution_details' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        $incident =
            SecurityIncident::findOrFail(
                $id
            );

        if (
            strtolower(
                (string) $incident->status
            ) === 'resolved'
        ) {
            return response()->json([
                'message' =>
                    'This security incident has already been resolved.',
            ], 422);
        }

        $incident->status =
            'Resolved';

        $incident->resolution_type =
            $validated[
                'resolution_type'
            ];

        $incident->resolution_details =
            trim(
                $validated[
                    'resolution_details'
                ]
            );

        $incident->resolved_by =
            $request->user()->id;

        $incident->resolved_at =
            now();

        $incident->save();

        $incident->load([
            'reporter',
            'resolver',
            'item.user',
        ]);

        AuditLogger::log(
            action: 'resolve_security_incident',

            description:
                $request->user()->name .
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

                'owner_name' =>
                    $incident->owner_name,

                'owner_id_number' =>
                    $incident->owner_id_number,

                'carrier_name' =>
                    $incident->carrier_name,

                'carrier_id_number' =>
                    $incident->carrier_id_number,

                'direction' =>
                    $incident->direction,

                'gate' =>
                    $incident->gate,

                'incident_status' =>
                    $incident->status,

                'resolution_type' =>
                    $incident->resolution_type,

                'resolution_details' =>
                    $incident->resolution_details,

                'resolved_by' =>
                    $incident->resolved_by,

                'resolved_at' =>
                    optional(
                        $incident->resolved_at
                    )->toDateTimeString(),
            ],

            user: $request->user()
        );

        return response()->json([
            'message' =>
                'Security incident resolved successfully.',

            'incident' =>
                $incident,
        ]);
    }
}
