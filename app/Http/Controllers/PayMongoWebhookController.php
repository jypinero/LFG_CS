<?php

namespace App\Http\Controllers;

use App\Models\VenueSubscription;
use App\Notifications\SubscriptionActivatedNotification;
use App\Notifications\SubscriptionPaymentFailedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayMongoWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        // Optionally, verify signature here for security

        $event = $payload['type'] ?? null;

        // Handle Payment Intent success
        if ($event === 'payment_intent.succeeded') {
            $intentId = $payload['data']['id'] ?? null;

            $subscription = VenueSubscription::where('paymongo_intent_id', $intentId)->first();

            if ($subscription) {
                $planDuration = config("subscriptions.{$subscription->plan}.duration_days");

                $subscription->update([
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addDays($planDuration),
                    'paymongo_payment_id' => $payload['data']['attributes']['payments'][0]['id'] ?? null,
                ]);

                // Send activation email notification
                $user = $subscription->user;
                if ($user) {
                    $user->notify(new SubscriptionActivatedNotification($subscription));
                }
            }
        }
        // Handle Payment Link payment success
        elseif ($event === 'payment.paid') {
            $paymentId = $payload['data']['id'] ?? null;
            $linkId = $payload['data']['attributes']['source']['id'] ?? null;
            
            // Find subscription by payment link ID (stored in paymongo_intent_id)
            $subscription = VenueSubscription::where('paymongo_intent_id', $linkId)->first();

            if ($subscription) {
                $planDuration = config("subscriptions.{$subscription->plan}.duration_days");

                $subscription->update([
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addDays($planDuration),
                    'paymongo_payment_id' => $paymentId,
                ]);

                // Send activation email notification
                $user = $subscription->user;
                if ($user) {
                    $user->notify(new SubscriptionActivatedNotification($subscription));
                }
            }
        }
        // Handle Payment Intent failure
        elseif ($event === 'payment_intent.payment_failed') {
            $intentId = $payload['data']['id'] ?? null;

            $subscription = VenueSubscription::where('paymongo_intent_id', $intentId)->first();

            if ($subscription) {
                $subscription->update([
                    'status' => 'failed',
                ]);

                // Send payment failure email notification
                $user = $subscription->user;
                if ($user) {
                    $user->notify(new SubscriptionPaymentFailedNotification());
                }
            }
        }

        // Handle other events if needed

        return response()->json(['status' => 'success']);
    }
}
