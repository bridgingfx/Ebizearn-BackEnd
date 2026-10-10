<?php

use App\Http\Controllers\Api\V1\SocialConnectController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
]));

// "Connect with …" OAuth: the platform sends the contributor back here after
// login. No session needed — the encrypted state binds it to their account.
Route::get('/oauth/social/{platform}/callback', [SocialConnectController::class, 'callback'])
    ->whereIn('platform', ['tiktok', 'x', 'facebook', 'google', 'instagram'])
    ->middleware('throttle:30,1');
