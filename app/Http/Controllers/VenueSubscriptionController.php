<?php

namespace App\Http\Controllers;

use App\Models\VenueSubscription;
use App\Services\PayMongoService;
use Illuminate\Http\Request;

class VenueSubscriptionController extends Controller
{
    public function start(Request $request, PayMongoService $paymongo)
    {
        $request->validate([
            'plan' => 'required|in:monthly,yearly,promo',
        ]);

        $planKey = $request->plan;
        $plan = config("subscriptions.$planKey");

        // Optional: prevent multiple active subscriptions
        $hasActive = VenueSubscription::where('user_id', auth()->id())
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->exists();

        if ($hasActive) {
            return response()->json([
                'message' => 'You already have an active subscription'
            ], 422);
        }

        // Create local subscription
        $subscription = VenueSubscription::create([
            'user_id' => auth()->id(),
            'plan' => $planKey,
            'amount' => $plan['amount'],
            'status' => 'pending',
        ]);

        // Create PayMongo payment intent
        $intent = $paymongo->createPaymentIntent($plan['amount']);

        $subscription->update([
            'paymongo_intent_id' => $intent['data']['id'] ?? null,
        ]);

        return response()->json([
            'subscription' => $subscription,
            'intent' => $intent,
        ]);
    }
}
