<?php

namespace App\Console\Commands;

use App\Services\Tasks\NewTaskAnnouncer;
use Illuminate\Console\Command;

/**
 * Finishes "new task available" emails that the after-response send didn't
 * complete (big audiences, or a request that ended early).
 */
class AnnounceNewTasks extends Command
{
    protected $signature = 'tasks:announce-new {--seconds=50 : Time budget for this run}';

    protected $description = 'Email contributors about newly published tasks (batched).';

    public function handle(NewTaskAnnouncer $announcer): int
    {
        $sent = $announcer->processPending((int) $this->option('seconds'));
        $this->info("Sent {$sent} new-task email(s).");

        return self::SUCCESS;
    }
}
