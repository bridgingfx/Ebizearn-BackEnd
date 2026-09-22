<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     * This API has no login view; unauthenticated requests get a 401 JSON response instead.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
