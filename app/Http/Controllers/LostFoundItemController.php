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
    | CREATE FOUND ITEM RECORD
    |--------------------------------------------------------------------------
    |
    | Workflow:
    |
    | 1. A person finds an item.
    | 2. The person physically turns the item over to CSU.
    | 3. CSU enters the finder Student/Employee ID.
    | 4. QRPass resolves that ID to FoundByUserID.
    | 5. Logged-in CSU becomes ProcessedByUserID.
    |
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
                    'Only CSU Security Personnel can create Lost & Found records.',
            ], 403);
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE INPUT
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'found_by_identifier' => [
                'required',
                'string',
                'max:255',
            ],

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

            'location_found' => [
                'required',
                'string',
                'max:255',
            ],

            'date_found' => [
                'required',
                'date',
                'before_or_equal:today',
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);


        /*
        |--------------------------------------------------------------------------
        | FIND PERSON WHO TURNED OVER THE ITEM
        |--------------------------------------------------------------------------
        |
        | username currently contains the Student/Employee ID in QRPass.
        |
        */

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
        | CREATE RECORD
        |--------------------------------------------------------------------------
        */

        $item = LostFoundItem::create([
            'reported_by' =>
                $user->id,

            'found_by_user_id' =>
                $finder->id,

            'processed_by_user_id' =>
                $user->id,

            'report_type' =>
                'found',

            'item_name' =>
                trim($validated['item_name']),

            'category' =>
                isset($validated['category'])
                    ? trim($validated['category'])
                    : null,

            'brand_model' =>
                isset($validated['brand_model'])
                    ? trim($validated['brand_model'])
                    : null,

            'color' =>
                isset($validated['color'])
                    ? trim($validated['color'])
                    : null,

            'location_found' =>
                trim($validated['location_found']),

            'description' =>
                isset($validated['description'])
                    ? trim($validated['description'])
                    : null,

            'date_found' =>
                $validated['date_found'],

            'status' =>
                'Found',

            'claimed_by' =>
                null,

            'claimed_at' =>
                null,
        ]);


        /*
        |--------------------------------------------------------------------------
        | LOAD RELATIONSHIPS
        |--------------------------------------------------------------------------
        */

        $item->load([
            'finder:id,name,username,role',
            'processor:id,name,username,role',
            'claimant:id,name,username,role',
        ]);


        return response()->json([
            'message' =>
                'Found item recorded successfully. Credit was assigned to ' .
                $finder->name .
                '.',

            'item' =>
                $item,
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | STUDENT CLAIM
    |--------------------------------------------------------------------------
    */

    public function claim(
        Request $request,
        $id
    ) {
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

        $item =
            LostFoundItem::findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | MUST STILL BE AVAILABLE
        |--------------------------------------------------------------------------
        */

        if (
            strtolower(
                (string) $item->status
            ) === 'recovered'
        ) {
            return response()->json([
                'message' =>
                    'This item has already been returned to its owner.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | PREVENT DUPLICATE CLAIM
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
                    'This item already has a pending claim for CSU verification.',
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
            'processor:id,name,username,role',
            'claimant:id,name,username,role',
        ]);


        return response()->json([
            'message' =>
                'Claim submitted successfully. Please proceed to the CSU office for ownership verification.',

            'item' =>
                $item,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CSU MARK AS RECOVERED
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
                    'Only CSU Security Personnel can release Lost & Found items.',
            ], 403);
        }

        $item =
            LostFoundItem::findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | CLAIM REQUIRED
        |--------------------------------------------------------------------------
        */

        if (!$item->claimed_by) {
            return response()->json([
                'message' =>
                    'This item does not have a pending claimant.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | ALREADY RECOVERED
        |--------------------------------------------------------------------------
        */

        if (
            strtolower(
                (string) $item->status
            ) === 'recovered'
        ) {
            return response()->json([
                'message' =>
                    'This item has already been marked as recovered.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | RECOVERED
        |--------------------------------------------------------------------------
        */

        $item->status =
            'Recovered';

        $item->save();


        $item->load([
            'finder:id,name,username,role',
            'processor:id,name,username,role',
            'claimant:id,name,username,role',
        ]);


        return response()->json([
            'message' =>
                'Ownership verified. The item has been marked as recovered.',

            'item' =>
                $item,
        ]);
    }
}