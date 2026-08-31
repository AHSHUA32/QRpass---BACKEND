<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementRead extends Model
{
    protected $table = 'announcement_reads';

    protected $fillable = [
        'user_id',
        'announcement_id',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(
            Announcement::class,
            'announcement_id'
        );
    }
}