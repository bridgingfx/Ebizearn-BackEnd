<?php

namespace App\Console\Commands;

use App\Services\Maintenance\DemoDataCleaner;
use Illuminate\Console\Command;

/**
 * Removes the seeded demo data (fake campaigns / tasks / demo proof and the
 * demo contributor's fake wallet history). Every user account, task
 * category, task type, template and real record stays.
 *
 *   php artisan cleanup:demo-data                    # dry run: shows what would go
 *   php artisan cleanup:demo-data --force            # removes it
 *   php artisan cleanup:demo-data --include-marked --force
 *                                                    # also campaigns titled "test", "dummy"…
 */
class CleanupDemoData extends Command
{
    protected $signature = 'cleanup:demo-data
        {--force : Actually delete (without it, nothing changes)}
        {--include-marked : Also remove campaigns whose title / description says test, dummy, demo, sample…}';

    protected $description = 'Remove seeded demo / dummy task and payment data. Keeps all accounts and configuration.';

    public function handle(DemoDataCleaner $cleaner): int
    {
        $includeMarked = (bool) $this->option('include-marked');
        $plan = $cleaner->plan($includeMarked);

        $this->line('');
        $this->info($this->option('force') ? 'Removing demo data…' : 'DRY RUN — nothing is changed. Add --force to delete.');
        $this->table(['Records', 'Count'], collect($plan['counts'])->map(fn ($n, $t) => [$t, $n])->values()->all());

        if (!empty($plan['marked'])) {
            $this->warn(($includeMarked ? 'Included' : 'Not included (add --include-marked)') . ' — campaigns that look like test data:');
            $this->table(['ID', 'Title', 'Status', 'Created'], collect($plan['marked'])->map(fn ($c) => [$c['id'], $c['title'], $c['status'], $c['created_at']])->all());
        }

        if (!$this->option('force')) {
            if (!empty($plan['wallets_to_review'])) {
                $this->line('Wallets touched (zeroed after removal only if no other ledger rows remain):');
                $this->table(['Wallet', 'Owner', 'Available', 'Pending', 'Other rows'], collect($plan['wallets_to_review'])->map(fn ($w) => [
                    $w['id'], $w['email'], $w['available_balance_cents'], $w['pending_balance_cents'], $w['other_transactions'],
                ])->all());
            }

            return self::SUCCESS;
        }

        $result = $cleaner->run($includeMarked);
        $this->info('Done. Accounts, categories, task types and templates were kept.');

        if (!empty($result['wallets_to_review'])) {
            $this->warn('These wallets still have real ledger rows — their balance was NOT changed; please check them:');
            $this->table(['Wallet', 'Owner'], collect($result['wallets_to_review'])->map(fn ($w) => [$w['id'], $w['email']])->all());
        }

        return self::SUCCESS;
    }
}
