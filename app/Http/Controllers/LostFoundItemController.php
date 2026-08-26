<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LostFoundItem;
use App\Models\User;
use Illuminate\Http\Request;

class LostFoundItemController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | AUDIT LOG HELPER
    |--------------------------------------------------------------------------
    */

    private function writeAuditLog(
        Request $request,
        User $actor,
        string $eventType,
        string $action,
        string $description,
        string $status = 'success',
        array $metadata = []
    ): void {
        AuditLog::create([
            'user_id' => $actor->id,

            'actor_name' => $actor->name,

            'actor_username' => $actor->username,

            'event_type' => $eventType,

            'action' => $action,

            'module' => 'Lost & Found',

            'description' => $description,

            'status' => $status,

            'ip_address' => $request->ip(),

            'user_agent' => $request->userAgent(),

            'metadata' => $metadata,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | GET LOST & FOUND REGISTRY
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $items = LostFoundItem::with([
            'finder:id,name,username,role',
            'lostBy:id,name,username,role',
            'processor:id,name,username,role',
            'reporter:id,name,username,role',
            'claimant:id,name,username,role',
        ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'items' => $items,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE LOST OR FOUND REPORT
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'security') {
            return response()->json([
                'message' =>
                    'Only Security Personnel can create Lost & Found reports.',
            ], 403);
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'report_type' => [
                'required',
                'in:found,lost',
            ],


            /*
            |--------------------------------------------------------------------------
            | PERSON
            |--------------------------------------------------------------------------
            */

            'found_by_identifier' => [
                'nullable',
                'required_if:report_type,found',
                'string',
                'max:255',
            ],

            'lost_by_identifier' => [
                'nullable',
                'required_if:report_type,lost',
                'string',
                'max:255',
            ],


            /*
            |--------------------------------------------------------------------------
            | ITEM DETAILS
            |--------------------------------------------------------------------------
            */

            'item_name' => [
                'required',
                'string',
                'max:255',
            ],

            'category' => [
                'nullable',
                'string',
                'max:255',
            ],

            'brand_model' => [
                'nullable',
                'string',
                'max:255',
            ],

            'color' => [
                'nullable',
                'string',
                'max:100',
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],


            /*
            |--------------------------------------------------------------------------
            | FOUND DETAILS
            |--------------------------------------------------------------------------
            */

            'location_found' => [
                'nullable',
                'required_if:report_type,found',
                'string',
                'max:255',
            ],

                    'date_found' => [
                'nullable',
                'required_if:report_type,found',
                'date',
                'before_or_equal:' . now('Asia/Manila')->format('Y-m-d H:i:s'),
            ],


            /*
            |--------------------------------------------------------------------------
            | LOST DETAILS
            |--------------------------------------------------------------------------
            */

            'location_lost' => [
                'nullable',
                'required_if:report_type,lost',
                'string',
                'max:255',
            ],

                'date_lost' => [
            'nullable',
            'required_if:report_type,lost',
            'date',
            'before_or_equal:' . now('Asia/Manila')->format('Y-m-d H:i:s'),
        ],
        ]);


        /*
        |--------------------------------------------------------------------------
        | FOUND REPORT
        |--------------------------------------------------------------------------
        */

        if ($validated['report_type'] === 'found') {
            $finder = User::where(
                'username',
                trim($validated['found_by_identifier'])
            )->first();

            if (!$finder) {
                return response()->json([
                    'message' =>
                        'No QRPass user was found with that Student/Employee ID.',
                ], 422);
            }


            /*
            |--------------------------------------------------------------------------
            | CREATE FOUND ITEM
            |--------------------------------------------------------------------------
            */

            $item = LostFoundItem::create([
                'reported_by' =>
                    $user->id,

                'found_by_user_id' =>
                    $finder->id,

                'lost_by_user_id' =>
                    null,

                'processed_by_user_id' =>
                    $user->id,

                'report_type' =>
                    'found',

                'item_name' =>
                    trim($validated['item_name']),

                'category' =>
                    !empty($validated['category'])
                        ? trim($validated['category'])
                        : null,

                'brand_model' =>
                    !empty($validated['brand_model'])
                        ? trim($validated['brand_model'])
                        : null,

                'color' =>
                    !empty($validated['color'])
                        ? trim($validated['color'])
                        : null,

                'location_found' =>
                    trim($validated['location_found']),

                'location_lost' =>
                    null,

                'date_found' =>
                    $validated['date_found'],

                'date_lost' =>
                    null,

                'description' =>
                    !empty($validated['description'])
                        ? trim($validated['description'])
                        : null,

                'status' =>
                    'Found',

                'claimed_by' =>
                    null,

                'claimed_at' =>
                    null,
            ]);


            /*
            |--------------------------------------------------------------------------
            | AUDIT LOG - FOUND ITEM CREATED
            |--------------------------------------------------------------------------
            */

            $this->writeAuditLog(
                $request,
                $user,
                'data_change',
                'lost_found_created',
                'Security Personnel recorded a found item in the Lost & Found registry.',
                'success',
                [
                    'lost_found_id' => $item->id,

                    'report_type' => 'found',

                    'item_name' => $item->item_name,

                    'category' => $item->category,

                    'brand_model' => $item->brand_model,

                    'color' => $item->color,

                    'finder_user_id' => $finder->id,

                    'finder_name' => $finder->name,

                    'finder_username' => $finder->username,

                    'location_found' => $item->location_found,

                    'date_found' => $item->date_found,

                    'status' => $item->status,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | LOAD RELATIONSHIPS
            |--------------------------------------------------------------------------
            */

            $item->load([
                'finder:id,name,username,role',
                'lostBy:id,name,username,role',
                'processor:id,name,username,role',
                'reporter:id,name,username,role',
                'claimant:id,name,username,role',
            ]);


            return response()->json([
                'message' =>
                    'Found item recorded successfully. Credit was assigned to ' .
                    $finder->name .
                    '.',

                'item' => $item,
            ], 201);
        }


        /*
        |--------------------------------------------------------------------------
        | LOST REPORT
        |--------------------------------------------------------------------------
        */

        $lostBy = User::where(
            'username',
            trim($validated['lost_by_identifier'])
        )->first();

        if (!$lostBy) {
            return response()->json([
                'message' =>
                    'No QRPass user was found with that Student/Employee ID.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | CREATE LOST ITEM
        |--------------------------------------------------------------------------
        */

        $item = LostFoundItem::create([
            'reported_by' =>
                $user->id,

            'found_by_user_id' =>
                null,

            'lost_by_user_id' =>
                $lostBy->id,

            'processed_by_user_id' =>
                $user->id,

            'report_type' =>
                'lost',

            'item_name' =>
                trim($validated['item_name']),

            'category' =>
                !empty($validated['category'])
                    ? trim($validated['category'])
                    : null,

            'brand_model' =>
                !empty($validated['brand_model'])
                    ? trim($validated['brand_model'])
                    : null,

            'color' =>
                !empty($validated['color'])
                    ? trim($validated['color'])
                    : null,

            'location_found' =>
                null,

            'location_lost' =>
                trim($validated['location_lost']),

            'date_found' =>
                null,

            'date_lost' =>
                $validated['date_lost'],

            'description' =>
                !empty($validated['description'])
                    ? trim($validated['description'])
                    : null,

            'status' =>
                'Lost',

            'claimed_by' =>
                null,

            'claimed_at' =>
                null,
        ]);


        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG - LOST ITEM CREATED
        |--------------------------------------------------------------------------
        */

        $this->writeAuditLog(
            $request,
            $user,
            'data_change',
            'lost_found_created',
            'Security Personnel recorded a lost item in the Lost & Found registry.',
            'success',
            [
                'lost_found_id' => $item->id,

                'report_type' => 'lost',

                'item_name' => $item->item_name,

                'category' => $item->category,

                'brand_model' => $item->brand_model,

                'color' => $item->color,

                'lost_by_user_id' => $lostBy->id,

                'lost_by_name' => $lostBy->name,

                'lost_by_username' => $lostBy->username,

                'location_lost' => $item->location_lost,

                'date_lost' => $item->date_lost,

                'status' => $item->status,
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | LOAD RELATIONSHIPS
        |--------------------------------------------------------------------------
        */

        $item->load([
            'finder:id,name,username,role',
            'lostBy:id,name,username,role',
            'processor:id,name,username,role',
            'reporter:id,name,username,role',
            'claimant:id,name,username,role',
        ]);


        return response()->json([
            'message' =>
                'Lost item report recorded successfully for ' .
                $lostBy->name .
                '.',

            'item' => $item,
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | STUDENT CLAIM FOUND ITEM
    |--------------------------------------------------------------------------
    */

    public function claim(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'student') {
            return response()->json([
                'message' =>
                    'Only students can submit a claim for a found item.',
            ], 403);
        }

        $item = LostFoundItem::findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | ONLY FOUND REPORTS CAN BE CLAIMED
        |--------------------------------------------------------------------------
        */

        if (
            strtolower((string) $item->report_type) !==
            'found'
        ) {
            return response()->json([
                'message' =>
                    'Lost reports cannot be claimed.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | ALREADY RECOVERED
        |--------------------------------------------------------------------------
        */

        if (
            strtolower((string) $item->status) ===
            'recovered'
        ) {
            return response()->json([
                'message' =>
                    'This item has already been returned to its owner.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | DUPLICATE CLAIM
        |--------------------------------------------------------------------------
        */

        if ($item->claimed_by) {
            if (
                (int) $item->claimed_by ===
                (int) $user->id
            ) {
                return response()->json([
                    'message' =>
                        'You have already submitted a claim for this item.',
                ], 422);
            }

            return response()->json([
                'message' =>
                    'This item already has a pending claim for Security verification.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | SAVE CLAIM
        |--------------------------------------------------------------------------
        */

        $item->claimed_by =
            $user->id;

        $item->claimed_at =
            now();

        $item->status =
            'Claimed';

        $item->save();


        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG - STUDENT CLAIM
        |--------------------------------------------------------------------------
        */

        $this->writeAuditLog(
            $request,
            $user,
            'data_change',
            'lost_found_claimed',
            'Student submitted an ownership claim for a found item.',
            'success',
            [
                'lost_found_id' => $item->id,

                'report_type' => $item->report_type,

                'item_name' => $item->item_name,

                'claimant_user_id' => $user->id,

                'claimant_name' => $user->name,

                'claimant_username' => $user->username,

                'claimed_at' => $item->claimed_at,

                'status' => $item->status,
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | LOAD RELATIONSHIPS
        |--------------------------------------------------------------------------
        */

        $item->load([
            'finder:id,name,username,role',
            'lostBy:id,name,username,role',
            'processor:id,name,username,role',
            'reporter:id,name,username,role',
            'claimant:id,name,username,role',
        ]);


        return response()->json([
            'message' =>
                'Claim submitted successfully. Please proceed to the Security office for ownership verification.',

            'item' => $item,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SECURITY MARK ITEM AS RECOVERED
    |--------------------------------------------------------------------------
    */

    public function markRecovered(
        Request $request,
        $id
    ) {
        $user = $request->user();
    
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }
    
        if ($user->role !== 'security') {
            return response()->json([
                'message' =>
                    'Only Security Personnel can mark Lost & Found items as recovered.',
            ], 403);
        }
    
        $item = LostFoundItem::findOrFail($id);
    
    
        /*
        |--------------------------------------------------------------------------
        | ALREADY RECOVERED
        |--------------------------------------------------------------------------
        */
    
        if (
            strtolower((string) $item->status) ===
            'recovered'
        ) {
            return response()->json([
                'message' =>
                    'This item has already been marked as recovered.',
            ], 422);
        }
    
        $reportType =
            strtolower((string) $item->report_type);
    
    
        /*
        |--------------------------------------------------------------------------
        | FOUND ITEM REPORT
        |--------------------------------------------------------------------------
        |
        | A found item must first be claimed by a student.
        | Security verifies ownership before releasing the item.
        |
        */
    
        if ($reportType === 'found') {
            if (!$item->claimed_by) {
                return response()->json([
                    'message' =>
                        'This found item does not have a pending claimant.',
                ], 422);
            }
    
            $claimantId =
                $item->claimed_by;
    
            $previousStatus =
                $item->status;
    
            $item->status =
                'Recovered';
    
            $item->save();
    
    
            /*
            |--------------------------------------------------------------------------
            | AUDIT LOG - FOUND ITEM RECOVERED
            |--------------------------------------------------------------------------
            */
    
            $this->writeAuditLog(
                $request,
                $user,
                'status_change',
                'lost_found_recovered',
                'Security Personnel verified ownership and marked a found item as recovered.',
                'success',
                [
                    'lost_found_id' =>
                        $item->id,
    
                    'report_type' =>
                        'found',
    
                    'item_name' =>
                        $item->item_name,
    
                    'claimant_user_id' =>
                        $claimantId,
    
                    'previous_status' =>
                        $previousStatus,
    
                    'new_status' =>
                        $item->status,
    
                    'verified_by_user_id' =>
                        $user->id,
    
                    'verified_by_name' =>
                        $user->name,
    
                    'verified_by_username' =>
                        $user->username,
                ]
            );
    
    
            /*
            |--------------------------------------------------------------------------
            | LOAD RELATIONSHIPS
            |--------------------------------------------------------------------------
            */
    
            $item->load([
                'finder:id,name,username,role',
                'lostBy:id,name,username,role',
                'processor:id,name,username,role',
                'reporter:id,name,username,role',
                'claimant:id,name,username,role',
            ]);
    
    
            return response()->json([
                'message' =>
                    'Ownership verified. The found item has been returned to its rightful owner.',
    
                'item' =>
                    $item,
            ]);
        }
    
    
        /*
        |--------------------------------------------------------------------------
        | LOST ITEM REPORT
        |--------------------------------------------------------------------------
        |
        | When a previously lost item is recovered, Security records:
        |
        | - who found / returned the item
        | - where the item was found
        | - exact date and time it was found
        |
        */
    
        $validated = $request->validate([
            'found_by_identifier' => [
                'required',
                'string',
                'max:255',
            ],
    
            'location_found' => [
                'required',
                'string',
                'max:255',
            ],
    
            'date_found' => [
                'required',
                'date',
            ],
        ]);
    
    
        /*
        |--------------------------------------------------------------------------
        | VALIDATE DATE AND TIME
        |--------------------------------------------------------------------------
        |
        | Allow:
        | - today
        | - current time
        | - any past date/time
        |
        | Block:
        | - future date/time
        |
        */
    
        $dateFound = \Carbon\Carbon::parse(
            $validated['date_found'],
            'Asia/Manila'
        );
    
        if (
            $dateFound->gt(
                now('Asia/Manila')
            )
        ) {
            return response()->json([
                'message' =>
                    'The date found cannot be in the future.',
            ], 422);
        }
    
    
        /*
        |--------------------------------------------------------------------------
        | FIND PERSON WHO FOUND / RETURNED THE ITEM
        |--------------------------------------------------------------------------
        */
    
        $finder = User::where(
            'username',
            trim(
                $validated['found_by_identifier']
            )
        )->first();
    
        if (!$finder) {
            return response()->json([
                'message' =>
                    'No QRPass user was found with that Student/Employee ID.',
            ], 422);
        }
    
    
        /*
        |--------------------------------------------------------------------------
        | SAVE RECOVERY DETAILS
        |--------------------------------------------------------------------------
        */
    
        $previousStatus =
            $item->status;
    
        $item->found_by_user_id =
            $finder->id;
    
        $item->location_found =
            trim(
                $validated['location_found']
            );
    
        $item->date_found =
            $dateFound;
    
        $item->status =
            'Recovered';
    
        $item->save();
    
    
        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG - LOST ITEM RECOVERED
        |--------------------------------------------------------------------------
        */
    
        $this->writeAuditLog(
            $request,
            $user,
            'status_change',
            'lost_found_recovered',
            'Security Personnel recorded the recovery of a previously lost item.',
            'success',
            [
                'lost_found_id' =>
                    $item->id,
    
                'report_type' =>
                    'lost',
    
                'item_name' =>
                    $item->item_name,
    
                'finder_user_id' =>
                    $finder->id,
    
                'finder_name' =>
                    $finder->name,
    
                'finder_username' =>
                    $finder->username,
    
                'location_found' =>
                    $item->location_found,
    
                'date_found' =>
                    $item->date_found,
    
                'previous_status' =>
                    $previousStatus,
    
                'new_status' =>
                    $item->status,
    
                'verified_by_user_id' =>
                    $user->id,
    
                'verified_by_name' =>
                    $user->name,
    
                'verified_by_username' =>
                    $user->username,
            ]
        );
    
    
        /*
        |--------------------------------------------------------------------------
        | LOAD RELATIONSHIPS
        |--------------------------------------------------------------------------
        */
    
        $item->load([
            'finder:id,name,username,role',
            'lostBy:id,name,username,role',
            'processor:id,name,username,role',
            'reporter:id,name,username,role',
            'claimant:id,name,username,role',
        ]);
    
    
        return response()->json([
            'message' =>
                'Lost item recovered successfully. Turnover credit was assigned to ' .
                $finder->name .
                '.',
    
            'item' =>
                $item,
        ]);
    }
}