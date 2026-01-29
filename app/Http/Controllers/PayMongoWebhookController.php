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

        // Log webhook for debugging
        Log::info('PayMongo Webhook Received', [
            'type' => $payload['type'] ?? 'unknown',
            'data' => $payload['data'] ?? null,
        ]);

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
        // PayMongo Payment Links trigger: link.payment.paid
        elseif ($event === 'link.payment.paid') {
            $linkData = $payload['data'] ?? [];
            $linkId = $linkData['id'] ?? null;
            
            // Get payment ID from attributes
            $paymentId = $linkData['attributes']['payments'][0]['id'] ?? null;
            
            Log::info('Payment Link Webhook Processing', [
                'event' => $event,
                'link_id' => $linkId,
                'payment_id' => $paymentId,
                'link_data' => $linkData,
            ]);
            
            if (!$linkId) {
                Log::warning('Payment Link ID not found in webhook payload', ['payload' => $payload]);
                return response()->json(['status' => 'error', 'message' => 'link_id_not_found'], 400);
            }
            
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

                Log::info('Subscription activated via Payment Link', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'plan' => $subscription->plan,
                    'link_id' => $linkId,
                ]);

                // Send activation email notification
                $user = $subscription->user;
                if ($user) {
                    $user->notify(new SubscriptionActivatedNotification($subscription));
                }
            } else {
                Log::warning('Subscription not found for Payment Link', [
                    'link_id' => $linkId,
                    'payment_id' => $paymentId,
                    'all_subscriptions' => VenueSubscription::where('status', 'pending')->pluck('paymongo_intent_id'),
                ]);
            }
        }
        // Also handle generic payment.paid event (fallback)
        elseif ($event === 'payment.paid') {
            $paymentData = $payload['data'] ?? [];
            $paymentId = $paymentData['id'] ?? null;
            
            // Try to find link ID from payment attributes
            $linkId = $paymentData['attributes']['source']['id'] ?? 
                     $paymentData['attributes']['link_id'] ?? null;
            
            Log::info('Payment Paid Webhook (Generic)', [
                'event' => $event,
                'payment_id' => $paymentId,
                'link_id' => $linkId,
            ]);
            
            if ($linkId) {
                $subscription = VenueSubscription::where('paymongo_intent_id', $linkId)->first();
                
                if ($subscription && $subscription->status === 'pending') {
                    $planDuration = config("subscriptions.{$subscription->plan}.duration_days");
                    
                    $subscription->update([
                        'status' => 'active',
                        'starts_at' => now(),
                        'ends_at' => now()->addDays($planDuration),
                        'paymongo_payment_id' => $paymentId,
                    ]);
                    
                    $user = $subscription->user;
                    if ($user) {
                        $user->notify(new SubscriptionActivatedNotification($subscription));
                    }
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
