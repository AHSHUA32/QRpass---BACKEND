<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | ACTIVE ANNOUNCEMENTS FOR LOGGED-IN USER
    |--------------------------------------------------------------------------
    |
    | Used by Student, CSU, PCO and SysAdmin dashboards.
    |
    | Only returns announcements that:
    | - are published
    | - are intended for the user's role or everyone
    | - have already started
    | - have not expired
    |
    */

    public function index(Request $request)
    {
        $user = $request->user();

        $announcements =
            Announcement::query()
                ->with([
                    'creator:id,name,username',
                ])
                ->where(
                    'is_published',
                    true
                )
                ->where(
                    function ($query) use ($user) {
                        $query
                            ->where(
                                'audience',
                                'everyone'
                            )
                            ->orWhere(
                                'audience',
                                $user->role
                            );
                    }
                )
                ->where(
                    function ($query) {
                        $query
                            ->whereNull(
                                'start_at'
                            )
                            ->orWhere(
                                'start_at',
                                '<=',
                                now()
                            );
                    }
                )
                ->where(
                    function ($query) {
                        $query
                            ->whereNull(
                                'end_at'
                            )
                            ->orWhere(
                                'end_at',
                                '>=',
                                now()
                            );
                    }
                )
                ->orderByRaw(
                    "
                    CASE priority
                        WHEN 'urgent' THEN 1
                        WHEN 'important' THEN 2
                        ELSE 3
                    END
                    "
                )
                ->latest(
                    'created_at'
                )
                ->get();

        return response()->json([
            'announcements' =>
                $announcements,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SYSADMIN - VIEW ALL ANNOUNCEMENTS
    |--------------------------------------------------------------------------
    */

    public function adminIndex(
        Request $request
    ) {
        $announcements =
            Announcement::query()
                ->with([
                    'creator:id,name,username',
                ])
                ->latest(
                    'created_at'
                )
                ->get()
                ->map(
                    function ($announcement) {
                        return array_merge(
                            $announcement->toArray(),
                            [
                                'display_status' =>
                                    $this->getDisplayStatus(
                                        $announcement
                                    ),
                            ]
                        );
                    }
                );

        return response()->json([
            'announcements' =>
                $announcements,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SYSADMIN - CREATE ANNOUNCEMENT
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request
    ) {
        $validated =
            $request->validate([
                'title' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'message' => [
                    'required',
                    'string',
                    'max:5000',
                ],

                'audience' => [
                    'required',
                    'in:everyone,student,security,pco,sysadmin',
                ],

                'priority' => [
                    'required',
                    'in:info,important,urgent',
                ],

                'start_at' => [
                    'nullable',
                    'date',
                ],

                'end_at' => [
                    'nullable',
                    'date',
                    'after_or_equal:start_at',
                ],

                'is_published' => [
                    'required',
                    'boolean',
                ],
            ]);

        $announcement =
            Announcement::create([
                'title' =>
                    $validated['title'],

                'message' =>
                    $validated['message'],

                'audience' =>
                    $validated['audience'],

                'priority' =>
                    $validated['priority'],

                'start_at' =>
                    $validated['start_at']
                    ?? null,

                'end_at' =>
                    $validated['end_at']
                    ?? null,

                'is_published' =>
                    $validated[
                        'is_published'
                    ],

                'created_by' =>
                    $request->user()->id,
            ]);

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'create_announcement',

            description:
                $request->user()->name .
                ' created announcement "' .
                $announcement->title .
                '".',

            eventType:
                'data_change',

            module:
                'Announcement Management',

            status:
                'success',

            metadata: [
                'announcement_id' =>
                    $announcement->id,

                'audience' =>
                    $announcement->audience,

                'priority' =>
                    $announcement->priority,

                'is_published' =>
                    $announcement
                        ->is_published,

                'start_at' =>
                    $announcement
                        ->start_at,

                'end_at' =>
                    $announcement
                        ->end_at,
            ],

            user:
                $request->user()
        );

        $announcement->load(
            'creator:id,name,username'
        );

        return response()->json([
            'message' =>
                'Announcement created successfully.',

            'announcement' =>
                $announcement,
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | SYSADMIN - UPDATE ANNOUNCEMENT
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        $id
    ) {
        $announcement =
            Announcement::findOrFail(
                $id
            );

        $validated =
            $request->validate([
                'title' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'message' => [
                    'required',
                    'string',
                    'max:5000',
                ],

                'audience' => [
                    'required',
                    'in:everyone,student,security,pco,sysadmin',
                ],

                'priority' => [
                    'required',
                    'in:info,important,urgent',
                ],

                'start_at' => [
                    'nullable',
                    'date',
                ],

                'end_at' => [
                    'nullable',
                    'date',
                    'after_or_equal:start_at',
                ],

                'is_published' => [
                    'required',
                    'boolean',
                ],
            ]);

        $announcement->update([
            'title' =>
                $validated['title'],

            'message' =>
                $validated['message'],

            'audience' =>
                $validated['audience'],

            'priority' =>
                $validated['priority'],

            'start_at' =>
                $validated['start_at']
                ?? null,

            'end_at' =>
                $validated['end_at']
                ?? null,

            'is_published' =>
                $validated[
                    'is_published'
                ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'update_announcement',

            description:
                $request->user()->name .
                ' updated announcement "' .
                $announcement->title .
                '".',

            eventType:
                'data_change',

            module:
                'Announcement Management',

            status:
                'success',

            metadata: [
                'announcement_id' =>
                    $announcement->id,

                'audience' =>
                    $announcement->audience,

                'priority' =>
                    $announcement->priority,

                'is_published' =>
                    $announcement
                        ->is_published,
            ],

            user:
                $request->user()
        );

        $announcement->load(
            'creator:id,name,username'
        );

        return response()->json([
            'message' =>
                'Announcement updated successfully.',

            'announcement' =>
                $announcement,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SYSADMIN - PUBLISH / UNPUBLISH
    |--------------------------------------------------------------------------
    */

    public function setPublished(
        Request $request,
        $id
    ) {
        $validated =
            $request->validate([
                'is_published' => [
                    'required',
                    'boolean',
                ],
            ]);

        $announcement =
            Announcement::findOrFail(
                $id
            );

        $announcement
            ->is_published =
            $validated[
                'is_published'
            ];

        $announcement->save();

        $action =
            $announcement
                ->is_published
                ? 'published'
                : 'unpublished';

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                $announcement
                    ->is_published
                    ? 'publish_announcement'
                    : 'unpublish_announcement',

            description:
                $request->user()->name .
                ' ' .
                $action .
                ' announcement "' .
                $announcement->title .
                '".',

            eventType:
                'status_change',

            module:
                'Announcement Management',

            status:
                'success',

            metadata: [
                'announcement_id' =>
                    $announcement->id,

                'is_published' =>
                    $announcement
                        ->is_published,
            ],

            user:
                $request->user()
        );

        return response()->json([
            'message' =>
                $announcement
                    ->is_published
                    ? 'Announcement published successfully.'
                    : 'Announcement unpublished successfully.',

            'announcement' =>
                $announcement,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SYSADMIN - DELETE ANNOUNCEMENT
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        $id
    ) {
        $announcement =
            Announcement::findOrFail(
                $id
            );

        $announcementId =
            $announcement->id;

        $announcementTitle =
            $announcement->title;

        $announcement->delete();

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */

        AuditLogger::log(
            action:
                'delete_announcement',

            description:
                $request->user()->name .
                ' deleted announcement "' .
                $announcementTitle .
                '".',

            eventType:
                'data_change',

            module:
                'Announcement Management',

            status:
                'success',

            metadata: [
                'announcement_id' =>
                    $announcementId,

                'title' =>
                    $announcementTitle,
            ],

            user:
                $request->user()
        );

        return response()->json([
            'message' =>
                'Announcement deleted successfully.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | DETERMINE ADMIN DISPLAY STATUS
    |--------------------------------------------------------------------------
    */

    private function getDisplayStatus(
        Announcement $announcement
    ): string {
        if (
            !$announcement
                ->is_published
        ) {
            return 'draft';
        }

        if (
            $announcement->start_at &&
            $announcement
                ->start_at
                ->isFuture()
        ) {
            return 'scheduled';
        }

        if (
            $announcement->end_at &&
            $announcement
                ->end_at
                ->isPast()
        ) {
            return 'expired';
        }

        return 'active';
    }
}