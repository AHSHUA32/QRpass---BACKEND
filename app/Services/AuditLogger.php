<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogger
{
    /**
     * Record a QRPass system activity.
     */
    public static function log(
        string $action,
        string $description,
        string $eventType = 'activity',
        ?string $module = null,
        string $status = 'success',
        ?array $metadata = null,
        ?User $user = null
    ): AuditLog {
        $actor = $user ?? auth()->user();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'System',
            'actor_username' => $actor?->username,
            'event_type' => $eventType,
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'status' => $status,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $metadata,
        ]);
    }
}