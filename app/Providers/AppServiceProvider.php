<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Sanctum::ignoreMigrations();

        // Round 2 — social login: bind the token verifier contract to the
        // JWT implementation. Tests swap this for a fake.
        $this->app->singleton(
            \App\Services\Auth\SocialTokenVerifier::class,
            \App\Services\Auth\JwtSocialTokenVerifier::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 13: explicit authorization policies (this project has no
        // AuthServiceProvider; policies are registered on the Gate here).
        Gate::policy(\App\Models\Campaign::class, \App\Policies\CampaignPolicy::class);
        Gate::policy(\App\Models\Task::class, \App\Policies\TaskPolicy::class);
        Gate::policy(\App\Models\Wallet::class, \App\Policies\WalletPolicy::class);

        // New sign-ups, tasks, campaigns, proofs, withdrawals and tickets go
        // into the audit trail — the source of the staff notification feed.
        \App\Services\Audit\ActivityRecorder::register();

        // Email every eligible contributor when a task goes live.
        \App\Services\Tasks\NewTaskAnnouncer::register();

        // Look a record up by numeric id OR uuid. Never "id = ? OR uuid = ?"
        // with the same value: MySQL casts a uuid like "6c199a0f-…" to the
        // number 6 when comparing with the integer id, so it matched record
        // #6 — the wrong task / campaign.
        \Illuminate\Database\Eloquent\Builder::macro('whereKeyOrUuid', function ($value, string $prefix = '') {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            $value = (string) $value;

            return ctype_digit($value)
                ? $this->where($prefix . 'id', (int) $value)
                : $this->where($prefix . 'uuid', $value);
        });
    }
}
