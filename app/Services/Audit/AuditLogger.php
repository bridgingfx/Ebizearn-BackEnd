<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Phase 13: single writer for the append-only audit_logs table.
 *
 * Column mapping (existing table, kept for compatibility):
 *   subject_type -> entity_type, subject_id -> entity_id,
 *   meta         -> after_state_json (when no richer after-state exists),
 *   ip           -> ip_address, created_at -> created_at.
 *
 * Callers wrap money paths in DB transactions, so a failed audit write rolls
 * back together with the operation it records — audit and action stay
 * atomic by construction.
 */
class AuditLogger
{
    public static function log(
        ?User $actor,
        string $action,
        string $subjectType,
        int|string|null $subjectId,
        array $meta = [],
        ?array $before = null,
        ?array $after = null
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $subjectType,
            'entity_id' => (int) $subjectId,
            'before_state_json' => $before,
            'after_state_json' => $after ?? ($meta === [] ? null : $meta),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
