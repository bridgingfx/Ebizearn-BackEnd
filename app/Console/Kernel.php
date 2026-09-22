<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Keep the personal_access_tokens table tidy now that API tokens have
        // a real expiry (config/sanctum.php). Tokens expired >24h ago are safe
        // to delete; this does not affect live sessions.
        $schedule->command('sanctum:prune-expired --hours=24')->daily();

        // Task-reward retention: matured pending holds become available.
        $schedule->command('retention:release')->daily();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
