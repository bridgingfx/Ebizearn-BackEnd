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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 13: explicit authorization policies (this project has no
        // AuthServiceProvider; policies are registered on the Gate here).
        Gate::policy(\App\Models\Campaign::class, \App\Policies\CampaignPolicy::class);
        Gate::policy(\App\Models\Wallet::class, \App\Policies\WalletPolicy::class);
    }
}
