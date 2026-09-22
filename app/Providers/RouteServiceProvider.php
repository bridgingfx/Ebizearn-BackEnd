<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Brute-force protection: 5 attempts/min per email+IP. Keyed on the
        // normalized email (not just IP) so a shared NAT IP can't be
        // lockout-poisoned for other users, and so an attacker can't rotate
        // IPs cheaply. Laravel's throttle middleware answers 429 with a
        // Retry-After header — that is the backoff.
        RateLimiter::for('login', function (Request $request) {
            $key = strtolower(trim((string) $request->input('email'))).'|'.$request->ip();

            return Limit::perMinute(5)->by($key)->response(function (Request $request) {
                return response()->json([
                    'success' => false,
                    'code' => 'rate_limited',
                    'message' => 'Too many login attempts. Please wait and try again.',
                ], 429);
            });
        });

        // Password-reset abuse (email bombing / SMTP budget burn): 5/min per
        // email+IP, same key scheme as login.
        RateLimiter::for('password-reset', function (Request $request) {
            $key = strtolower(trim((string) $request->input('email'))).'|'.$request->ip();

            return Limit::perMinute(5)->by($key)->response(function (Request $request) {
                return response()->json([
                    'success' => false,
                    'code' => 'rate_limited',
                    'message' => 'Too many password-reset requests. Please wait and try again.',
                ], 429);
            });
        });

        // Super-Admin ops surface: tighter than the generic api limiter
        // because these endpoints manage staff, money rules, and settings.
        RateLimiter::for('ops', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Round 2 — public registration: 5 attempts/min per IP. Keyed on IP
        // (no email normalization possible before validation); the strong
        // password rule + email verification gate sit behind this.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip())->response(function (Request $request) {
                return response()->json([
                    'success' => false,
                    'code' => 'rate_limited',
                    'message' => 'Too many registration attempts. Please wait and try again.',
                ], 429);
            });
        });

        // Round 2 — social login: 10 attempts/min per IP. Slightly looser
        // than password login because the ID token itself is the credential,
        // but still bounded against token-replay probing.
        RateLimiter::for('social-login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip())->response(function (Request $request) {
                return response()->json([
                    'success' => false,
                    'code' => 'rate_limited',
                    'message' => 'Too many social sign-in attempts. Please wait and try again.',
                ], 429);
            });
        });

        // Round 2 — verification-email resend: 3/min per authenticated user
        // (falls back to IP for safety). Bounds SMTP budget burn.
        RateLimiter::for('email-resend', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip())->response(function (Request $request) {
                return response()->json([
                    'success' => false,
                    'code' => 'rate_limited',
                    'message' => 'Too many verification email requests. Please wait and try again.',
                ], 429);
            });
        });

        // Public demo-request form: cheap to abuse for spam / DB bloat, so
        // cap it at 10 submissions/min per IP with a friendly 429 payload.
        RateLimiter::for('demo-requests', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip())->response(function (Request $request) {
                return response()->json([
                    'success' => false,
                    'code' => 'rate_limited',
                    'message' => 'Too many demo requests. Please wait and try again.',
                ], 429);
            });
        });
    }
}
