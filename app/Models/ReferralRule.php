<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-controllable affiliate reward rule for one chain level.
 *
 * reward_mode 'flat':    reward_cents is the fixed payout per qualified referee.
 * reward_mode 'percent': percent_bps (basis points, 1000 = 10%) of the
 *                        referee's first approved task reward is paid instead.
 *
 * The rules table is the source of truth; config('referrals') is the
 * fallback when a row is missing or disabled. Seeded with the owner-approved
 * defaults: flat L1 $1.00 / L2 $0.50 / L3 $0.25 (percent equivalents
 * 10% / 5% / 2% ready for an admin to switch on).
 */
class ReferralRule extends Model
{
    use HasFactory;

    public const MODE_FLAT = 'flat';

    public const MODE_PERCENT = 'percent';

    protected $fillable = [
        'level',
        'reward_mode',
        'reward_cents',
        'percent_bps',
        'is_enabled',
    ];

    protected $casts = [
        'level' => 'integer',
        'reward_cents' => 'integer',
        'percent_bps' => 'integer',
        'is_enabled' => 'boolean',
    ];

    /**
     * The rule for a level: the DB row when present and enabled, otherwise
     * the config fallback shaped like a row.
     */
    public static function forLevel(int $level): self
    {
        $row = static::where('level', $level)->where('is_enabled', true)->first();

        if ($row) {
            return $row;
        }

        return new static([
            'level' => $level,
            'reward_mode' => static::MODE_FLAT,
            'reward_cents' => static::configFlatCents($level),
            'percent_bps' => 0,
            'is_enabled' => true,
        ]);
    }

    /**
     * Flat-mode amount for a level in cents, honoring the config's
     * missing-level fallback (a level inherits the previous level's amount).
     */
    public static function configFlatCents(int $level): int
    {
        $rewards = config('referrals.rewards_cents', [1 => 100]);
        $amount = null;

        for ($l = 1; $l <= $level; $l++) {
            if (array_key_exists($l, $rewards)) {
                $amount = (int) $rewards[$l];
            }
        }

        return max(0, (int) $amount);
    }

    /**
     * The actual payout in cents for a level.
     *
     * Percent mode needs the referee's first approved task reward as the
     * basis; when no basis is available it falls back to the level's flat
     * amount so qualification can never pay zero unexpectedly.
     */
    public function payoutCents(?int $basisCents = null): int
    {
        if ($this->reward_mode === static::MODE_PERCENT && (int) $this->percent_bps > 0) {
            if ($basisCents !== null && $basisCents > 0) {
                return max(0, (int) round($basisCents * ((int) $this->percent_bps) / 10000));
            }
        }

        return max(0, (int) ($this->reward_cents ?? static::configFlatCents($this->level)));
    }

    /**
     * Human-readable description for contributor-facing display.
     */
    public function describe(): string
    {
        if ($this->reward_mode === static::MODE_PERCENT && (int) $this->percent_bps > 0) {
            $pct = rtrim(rtrim(number_format(((int) $this->percent_bps) / 100, 2), '0'), '.');

            return "{$pct}% of your referral's first approved task reward";
        }

        return '$' . number_format($this->payoutCents() / 100, 2) . ' per qualified referral';
    }
}
