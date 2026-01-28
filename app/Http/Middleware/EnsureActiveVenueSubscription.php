<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\VenueSubscription;

class EnsureActiveVenueSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $subscription = VenueSubscription::where('user_id', auth()->id())
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json([
                'message' => 'Active subscription required to access this resource.'
            ], 403);
        }

        return $next($request);
    }
}
