<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WithdrawalRule extends Model
{
    use HasFactory;

    public const CACHE_KEY = 'withdrawal.min_cents';

    protected $fillable = [
        'amount_cents',
        'is_active',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * The single active minimum-withdrawal threshold in cents.
     * This is what every withdrawal enforcement path reads; the
     * config('payouts.withdrawal_min_cents') value is only the fallback
     * when no rule is active (e.g. before seeding).
     */
    public static function currentMinCents(): int
    {
        return (int) Cache::remember(self::CACHE_KEY, 300, function () {
            $active = static::where('is_active', true)->first();

            return $active
                ? (int) $active->amount_cents
                : (int) config('payouts.withdrawal_min_cents', 5000);
        });
    }

    /**
     * Activate exactly one rule (Super Admin only via API).
     */
    public static function activate(int $id): self
    {
        return DB::transaction(function () use ($id) {
            $rule = static::lockForUpdate()->findOrFail($id);

            static::where('is_active', true)->update(['is_active' => false]);
            $rule->update(['is_active' => true]);

            Cache::forget(self::CACHE_KEY);

            return $rule->fresh();
        });
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
