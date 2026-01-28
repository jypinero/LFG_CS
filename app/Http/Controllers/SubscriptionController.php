<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PayMongoService;
use App\Models\VenueSubscription;

class SubscriptionController extends Controller
{
    public function createSubscriptionIntent(Request $request, \App\Services\PayMongoService $paymongo)
    {
        $data = $request->validate(['plan_key' => 'required|string']);

        $plans = config('subscriptions', []);
        $plan = $plans[$data['plan_key']] ?? null;
        if (! $plan) {
            return response()->json(['status' => 'error', 'message' => 'invalid_plan'], 400);
        }

        $amount = (int) ($plan['amount'] ?? 0);
        if ($amount <= 0) {
            return response()->json(['status' => 'error', 'message' => 'plan_amount_not_configured'], 500);
        }

        // call service with correct param order: amount, description, currency
        $intent = $paymongo->createPaymentIntent($amount, $plan['name'] ?? $data['plan_key'], 'PHP');

        $intentId = data_get($intent, 'data.id') ?? data_get($intent, 'id');
        if (! $intentId) {
            return response()->json(['status' => 'error', 'message' => 'paymongo_error', 'payment_intent' => $intent], 422);
        }

        $subscription = \App\Models\VenueSubscription::create([
            'user_id' => auth()->id(),
            'plan' => $data['plan_key'],
            'amount' => $amount,
            'paymongo_intent_id' => $intentId,
            'status' => 'pending',
            'starts_at' => now(),
            'ends_at' => now()->addDays($plan['duration_days'] ?? 30),
        ]);

        return response()->json(['status' => 'success', 'subscription' => $subscription, 'payment_intent' => $intent], 200);
    }
}
