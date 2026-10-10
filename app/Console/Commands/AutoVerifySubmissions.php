<?php

namespace App\Console\Commands;

use App\Services\Verification\PostVerificationService;
use Illuminate\Console\Command;

/**
 * Runs the automatic proof check (AI + Instagram API) for submissions the
 * after-response run didn't finish (server restart, long AI call…).
 */
class AutoVerifySubmissions extends Command
{
    protected $signature = 'submissions:auto-verify {--seconds=50 : Time budget for this run}';

    protected $description = 'Automatic proof check (AI vision + Instagram API) for new submissions.';

    public function handle(PostVerificationService $posts): int
    {
        $n = $posts->processPending((int) $this->option('seconds'));
        $this->info("Checked {$n} submission(s).");

        return self::SUCCESS;
    }
}
