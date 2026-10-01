<?php

namespace App\Http\Controllers;

use App\Models\LostFoundItem;
use App\Models\User;
use Illuminate\Http\Request;

class LostFoundItemController extends Controller
{
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
                'before_or_equal:today',
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
                'before_or_equal:today',
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
    | UPDATE LOST OR FOUND REPORT
    |--------------------------------------------------------------------------
    |
    | Security can correct typographical mistakes or incorrect report details.
    | The report type and workflow status are intentionally not changed here.
    |
    */

    public function update(Request $request, $id)
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
                    'Only Security Personnel can edit Lost & Found reports.',
            ], 403);
        }

        $item = LostFoundItem::findOrFail($id);

        $reportType = strtolower(
            (string) ($item->report_type ?? 'found')
        );

        $rules = [
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
        ];

        if ($reportType === 'found') {
            $rules['found_by_identifier'] = [
                'required',
                'string',
                'max:255',
            ];

            $rules['location_found'] = [
                'required',
                'string',
                'max:255',
            ];

            $rules['date_found'] = [
                'required',
                'date',
                'before_or_equal:now',
            ];
        } else {
            $rules['lost_by_identifier'] = [
                'required',
                'string',
                'max:255',
            ];

            $rules['location_lost'] = [
                'required',
                'string',
                'max:255',
            ];

            $rules['date_lost'] = [
                'required',
                'date',
                'before_or_equal:now',
            ];
        }

        $validated = $request->validate($rules);

        $item->item_name =
            trim($validated['item_name']);

        $item->category =
            !empty($validated['category'])
                ? trim($validated['category'])
                : null;

        $item->brand_model =
            !empty($validated['brand_model'])
                ? trim($validated['brand_model'])
                : null;

        $item->color =
            !empty($validated['color'])
                ? trim($validated['color'])
                : null;

        $item->description =
            !empty($validated['description'])
                ? trim($validated['description'])
                : null;

        if ($reportType === 'found') {
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

            $item->found_by_user_id =
                $finder->id;

            $item->location_found =
                trim($validated['location_found']);

            $item->date_found =
                $validated['date_found'];
        } else {
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

            $item->lost_by_user_id =
                $lostBy->id;

            $item->location_lost =
                trim($validated['location_lost']);

            $item->date_lost =
                $validated['date_lost'];
        }

        $item->processed_by_user_id =
            $user->id;

        $item->save();

        $item->load([
            'finder:id,name,username,role',
            'lostBy:id,name,username,role',
            'processor:id,name,username,role',
            'reporter:id,name,username,role',
            'claimant:id,name,username,role',
        ]);

        return response()->json([
            'message' =>
                'Lost & Found report updated successfully.',
            'item' => $item,
        ]);
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
        | FOUND REPORT
        |--------------------------------------------------------------------------
        | A found item must have an approved claimant before release.
        */

        if ($reportType === 'found') {
            if (!$item->claimed_by) {
                return response()->json([
                    'message' =>
                        'This found item does not have a pending claimant.',
                ], 422);
            }

            $item->status = 'Recovered';
            $item->save();

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
                'item' => $item,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | LOST REPORT
        |--------------------------------------------------------------------------
        | Before marking a lost item recovered, Security must record:
        | - who found / returned it
        | - where it was found / turned over
        | - exact date and time
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
                'before_or_equal:now',
            ],
        ]);

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

        $item->found_by_user_id =
            $finder->id;

        $item->location_found =
            trim($validated['location_found']);

        $item->date_found =
            $validated['date_found'];

        $item->status =
            'Recovered';

        $item->save();

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

            'item' => $item,
        ]);
    }
}
