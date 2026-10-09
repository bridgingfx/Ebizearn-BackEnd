<?php

namespace App\Console\Commands;

use App\Models\SocialChannel;
use App\Services\Social\SocialRoboVerifier;
use Illuminate\Console\Command;

/**
 * Our robo's daily round: re-check every OAuth-connected social channel —
 * refresh expiring tokens, re-read the profile via the platform's official
 * API, sync username + followers. Channels whose connection dies three days
 * in a row drop back to unverified so the contributor reconnects.
 *
 * Idempotent: re-running only updates last_robo_check_at / counters.
 */
class VerifySocialChannels extends Command
{
    protected $signature = 'robo:verify-social-channels';

    protected $description = 'Re-check all OAuth-connected contributor social channels (the social robo).';

    public function handle(SocialRoboVerifier $robo): int
    {
        $checked = 0;
        SocialChannel::where('connected_via', 'oauth')
            ->whereNotNull('oauth_access_token')
            ->orderBy('id')
            ->chunkById(100, function ($channels) use ($robo, &$checked) {
                foreach ($channels as $channel) {
                    $robo->recheck($channel);
                    $checked++;
                }
            });

        $this->info("Robo checked {$checked} connected social channel(s).");

        return self::SUCCESS;
    }
}
