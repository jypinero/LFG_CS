<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PayMongoService;
use App\Models\VenueSubscription;
use App\Notifications\SubscriptionCancelledNotification;
use App\Notifications\SubscriptionUpgradedNotification;

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

        // Create subscription record first
        $subscription = \App\Models\VenueSubscription::create([
            'user_id' => auth()->id(),
            'plan' => $data['plan_key'],
            'amount' => $amount,
            'status' => 'pending',
            'starts_at' => now(),
            'ends_at' => now()->addDays($plan['duration_days'] ?? 30),
        ]);

        // Create PayMongo Payment Link (provides checkout_url for redirect)
        $successUrl = url('/management/venues?subscription=success&subscription_id=' . $subscription->id);
        $failedUrl = url('/subscription/checkout?plan=' . $data['plan_key'] . '&payment=failed');
        
        $paymentLink = $paymongo->createPaymentLink(
            $amount, 
            $plan['name'] ?? $data['plan_key'], 
            'PHP',
            $successUrl,
            $failedUrl
        );

        $linkId = data_get($paymentLink, 'data.id') ?? data_get($paymentLink, 'id');
        $checkoutUrl = data_get($paymentLink, 'data.attributes.checkout_url');
        
        if (! $linkId) {
            // Fallback to Payment Intent if Payment Link fails
            $intent = $paymongo->createPaymentIntent($amount, $plan['name'] ?? $data['plan_key'], 'PHP');
            $intentId = data_get($intent, 'data.id') ?? data_get($intent, 'id');
            
            if ($intentId) {
                $subscription->update(['paymongo_intent_id' => $intentId]);
                return response()->json([
                    'status' => 'success', 
                    'subscription' => $subscription, 
                    'payment_intent' => $intent,
                    'checkout_url' => null, // Will need to use SDK
                ], 200);
            }
            
            return response()->json(['status' => 'error', 'message' => 'paymongo_error', 'details' => $paymentLink], 422);
        }

        // Update subscription with payment link ID
        $subscription->update(['paymongo_intent_id' => $linkId]);

        return response()->json([
            'status' => 'success', 
            'subscription' => $subscription, 
            'payment_link' => $paymentLink,
            'checkout_url' => $checkoutUrl,
        ], 200);
    }

    public function getSubscriptionStatus(Request $request)
    {
        $user = auth()->user();
        
        $subscription = VenueSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => 'success',
                'subscription' => null,
                'has_active_subscription' => false,
            ], 200);
        }

        $plan = config("subscriptions.{$subscription->plan}", []);

        return response()->json([
            'status' => 'success',
            'subscription' => [
                'id' => $subscription->id,
                'plan' => $subscription->plan,
                'plan_name' => $plan['name'] ?? $subscription->plan,
                'amount' => $subscription->amount,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'created_at' => $subscription->created_at,
            ],
            'has_active_subscription' => true,
        ], 200);
    }

    public function getSubscriptionHistory(Request $request)
    {
        $user = auth()->user();
        
        $subscriptions = VenueSubscription::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($subscription) {
                $plan = config("subscriptions.{$subscription->plan}", []);
                return [
                    'id' => $subscription->id,
                    'plan' => $subscription->plan,
                    'plan_name' => $plan['name'] ?? $subscription->plan,
                    'amount' => $subscription->amount,
                    'status' => $subscription->status,
                    'starts_at' => $subscription->starts_at,
                    'ends_at' => $subscription->ends_at,
                    'created_at' => $subscription->created_at,
                    'cancelled_at' => $subscription->cancelled_at ?? null,
                ];
            });

        return response()->json([
            'status' => 'success',
            'subscriptions' => $subscriptions,
        ], 200);
    }

    public function cancelSubscription(Request $request)
    {
        $user = auth()->user();
        
        $subscription = VenueSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active subscription found',
            ], 404);
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        // Send cancellation email notification
        $user->notify(new SubscriptionCancelledNotification($subscription));

        return response()->json([
            'status' => 'success',
            'message' => 'Subscription cancelled successfully',
            'subscription' => $subscription,
        ], 200);
    }

    public function upgradeSubscription(Request $request)
    {
        $request->validate([
            'new_plan' => 'required|in:monthly,yearly,promo',
        ]);

        $user = auth()->user();
        $newPlanKey = $request->input('new_plan');
        
        // Get current active subscription
        $currentSubscription = VenueSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->latest()
            ->first();

        if (!$currentSubscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active subscription found',
            ], 404);
        }

        // Validate upgrade path
        $upgradePaths = [
            'monthly' => ['yearly', 'promo'],
            'yearly' => ['promo'],
            'promo' => [], // No upgrades available
        ];

        $currentPlan = $currentSubscription->plan;
        if (!in_array($newPlanKey, $upgradePaths[$currentPlan] ?? [])) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot upgrade from {$currentPlan} to {$newPlanKey}",
            ], 422);
        }

        $newPlan = config("subscriptions.{$newPlanKey}", []);
        if (!$newPlan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid plan selected',
            ], 400);
        }

        // Create new subscription with upgrade plan
        $newSubscription = VenueSubscription::create([
            'user_id' => $user->id,
            'plan' => $newPlanKey,
            'amount' => $newPlan['amount'],
            'status' => 'pending', // Will be activated after payment
            'starts_at' => now(),
            'ends_at' => now()->addDays($newPlan['duration_days'] ?? 365),
        ]);

        // Mark old subscription as upgraded (but keep it active until expiration)
        $currentSubscription->update([
            'status' => 'upgraded',
        ]);

        // Send upgrade email notification
        $user->notify(new SubscriptionUpgradedNotification($currentSubscription, $newSubscription));

        return response()->json([
            'status' => 'success',
            'message' => 'Subscription upgrade initiated',
            'old_subscription' => $currentSubscription,
            'new_subscription' => $newSubscription,
        ], 200);
    }
}
