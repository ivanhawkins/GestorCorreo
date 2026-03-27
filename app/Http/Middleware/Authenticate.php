<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * For API requests this returns null so that a 401 JSON response is sent
     * instead of a redirect to a login page.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }

    /**
     * Handle unauthenticated API requests with a JSON 401 response.
     */
    protected function unauthenticated($request, array $guards): void
    {
        abort(response()->json([
            'message' => 'Unauthenticated.',
            'status'  => 'error',
        ], 401));
    }
}
