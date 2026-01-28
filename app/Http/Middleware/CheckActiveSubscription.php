<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckActiveSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Replace with your actual subscription check logic
        if (!$user || !$user->hasActiveSubscription()) {
            return response()->json([
                'message' => 'Subscription required to access this resource.'
            ], 403);
        }

        return $next($request);
    }
}
