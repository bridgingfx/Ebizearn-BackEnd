<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PageView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Website traffic tracking + analytics for the Super Admin command center.
 */
class TrafficAnalyticsController extends Controller
{
    /**
     * Log a page view. Public endpoint (no auth) so anonymous visitors are tracked.
     * Rate-limited by the route definition.
     */
    public function track(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string|max:500',
            'referrer' => 'nullable|string|max:500',
            'session_id' => 'nullable|string|max:64',
        ]);

        $sessionId = $request->input('session_id') ?: Str::uuid()->toString();

        PageView::create([
            'session_id' => $sessionId,
            'user_id' => $request->user()?->id,
            'path' => $request->input('path'),
            'referrer' => $request->input('referrer'),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'ip_address' => $request->ip(),
            // Country resolved from IP by a GeoIP service when available.
            'country_code' => $this->resolveCountry($request->ip()),
        ]);

        return response()->json(['success' => true, 'session_id' => $sessionId]);
    }

    /**
     * Traffic overview for the admin dashboard.
     * Query params: from (Y-m-d), to (Y-m-d). Defaults to last 7 days.
     */
    public function overview(Request $request): JsonResponse
    {
        $to = $request->input('to') ? \Carbon\Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay();
        $from = $request->input('from') ? \Carbon\Carbon::parse($request->input('from'))->startOfDay() : now()->subDays(6)->startOfDay();

        $base = PageView::whereBetween('created_at', [$from, $to]);

        $totalViews = (clone $base)->count();
        $uniqueVisitors = (clone $base)->distinct('session_id')->count('session_id');

        // Today / yesterday quick stats
        $todayViews = PageView::whereDate('created_at', today())->count();
        $todayVisitors = PageView::whereDate('created_at', today())->distinct('session_id')->count('session_id');
        $yesterdayViews = PageView::whereDate('created_at', today()->subDay())->count();
        $yesterdayVisitors = PageView::whereDate('created_at', today()->subDay())->distinct('session_id')->count('session_id');

        // Live now: distinct sessions active in the last 5 minutes
        $liveNow = PageView::where('created_at', '>=', now()->subMinutes(5))
            ->distinct('session_id')->count('session_id');

        // Views per day
        $perDay = PageView::whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        // Top pages
        $topPages = (clone $base)
            ->selectRaw('path, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors')
            ->groupBy('path')
            ->orderByDesc('views')
            ->take(10)
            ->get();

        // Top countries (full name resolved on the frontend from the code)
        $topCountries = (clone $base)
            ->whereNotNull('country_code')
            ->selectRaw('country_code, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors')
            ->groupBy('country_code')
            ->orderByDesc('visitors')
            ->take(15)
            ->get();

        // Recent signups with country (from profile)
        $recentSignups = \App\Models\User::with('profile:country_code,user_id')
            ->latest()
            ->take(10)
            ->get(['id', 'name', 'email', 'role', 'created_at'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'country_code' => $u->profile?->country_code,
                'created_at' => $u->created_at,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'totals' => [
                    'views' => $totalViews,
                    'visitors' => $uniqueVisitors,
                    'today_views' => $todayViews,
                    'today_visitors' => $todayVisitors,
                    'yesterday_views' => $yesterdayViews,
                    'yesterday_visitors' => $yesterdayVisitors,
                    'live_now' => $liveNow,
                ],
                'per_day' => $perDay,
                'top_pages' => $topPages,
                'top_countries' => $topCountries,
                'recent_signups' => $recentSignups,
            ],
        ]);
    }

    private function resolveCountry(?string $ip): ?string
    {
        // Hook for a GeoIP lookup (MaxMind / ip-api). Returns ISO code or null.
        // Keeping it null-safe: traffic still records without a country.
        return null;
    }
}
