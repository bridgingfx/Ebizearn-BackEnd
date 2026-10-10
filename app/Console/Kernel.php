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

        // End of task duration: re-check Instagram posts, then release (or
        // refund) pending rewards. Hourly so failed checks retry on time.
        $schedule->command('retention:release')->hourly()->withoutOverlapping();

        // Automatic proof check (AI + Instagram API) for anything not finished
        // right after submit.
        $schedule->command('submissions:auto-verify')->everyMinute()->withoutOverlapping();

        // Social robo: re-check every OAuth-connected social channel daily.
        $schedule->command('robo:verify-social-channels')->daily();

        // "New task available" emails: finish any batch still pending.
        $schedule->command('tasks:announce-new')->everyMinute()->withoutOverlapping();
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
