<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'before_state_json',
        'after_state_json',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'before_state_json' => 'array',
        'after_state_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Phase 13: audit_logs is append-only. Any attempt to mutate or delete a
     * written row is a programming error — fail loudly instead of silently
     * rewriting history.
     */
    protected static function booted(): void
    {
        $deny = function () {
            throw new \LogicException('audit_logs is append-only: rows cannot be updated or deleted.');
        };

        static::updating($deny);
        static::deleting($deny);
    }
}
