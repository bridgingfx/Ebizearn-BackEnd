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
    }
}
