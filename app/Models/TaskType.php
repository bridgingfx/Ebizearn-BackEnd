<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 4: task-type system. One row per marketplace task type.
 *
 * The database row is authoritative at runtime; config/task_types.php holds
 * the canonical defaults used by the seeder and as a fallback. Reward bands
 * (Phase 12) are enforced by RewardBandService against these columns.
 */
class TaskType extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_allowed',
        'policy_note',
        'proof_required_json',
        'retention_period_days',
        'fraud_rules_json',
        'reward_band_min_cents',
        'reward_band_max_cents',
        'allowed_platforms_json',
        'is_active',
    ];

    protected $casts = [
        'is_allowed' => 'boolean',
        'proof_required_json' => 'array',
        'retention_period_days' => 'integer',
        'fraud_rules_json' => 'array',
        'reward_band_min_cents' => 'integer',
        'reward_band_max_cents' => 'integer',
        'allowed_platforms_json' => 'array',
        'is_active' => 'boolean',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Canonical defaults from config, keyed for the seeder.
     *
     * @return array<string, array>
     */
    public static function defaults(): array
    {
        $out = [];

        foreach (config('task_types.defaults', []) as $def) {
            $out[$def['key']] = [
                'name' => $def['name'],
                'description' => $def['description'] ?? null,
                'is_allowed' => $def['is_allowed'] ?? true,
                'policy_note' => $def['policy_note'] ?? null,
                'proof_required_json' => $def['proof_required'] ?? [],
                'retention_period_days' => $def['retention_period_days'] ?? 0,
                'fraud_rules_json' => $def['fraud_rules'] ?? [],
                'reward_band_min_cents' => $def['reward_band_min_cents'] ?? 0,
                'reward_band_max_cents' => $def['reward_band_max_cents'] ?? 0,
                'allowed_platforms_json' => $def['allowed_platforms'] ?? [],
                'is_active' => true,
            ];
        }

        return $out;
    }
}
