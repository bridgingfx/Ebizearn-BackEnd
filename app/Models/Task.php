<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'campaign_id',
        'category_id',
        'task_type_id',
        'title',
        'reward_cents',
        'estimated_minutes',
        'difficulty',
        'status',
        'slots_total',
        'slots_taken',
        // Phase 4: task-type contract fields
        'platform',
        'country_code',
        'instructions',
        'proof_required_json',
        'retention_days',
        'fraud_rules_json',
        'company_name',
        'company_logo_url',
    ];

    protected $casts = [
        'reward_cents' => 'integer',
        'estimated_minutes' => 'integer',
        'slots_total' => 'integer',
        'slots_taken' => 'integer',
        'task_type_id' => 'integer',
        'proof_required_json' => 'array',
        'retention_days' => 'integer',
        'fraud_rules_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Task $t) {
            if (empty($t->uuid)) {
                $t->uuid = (string) Str::uuid();
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaskCategory::class, 'category_id');
    }

    /**
     * Phase 4: the task-type contract (bands, proof rules, platforms).
     */
    public function taskType(): BelongsTo
    {
        return $this->belongsTo(TaskType::class, 'task_type_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }
}
