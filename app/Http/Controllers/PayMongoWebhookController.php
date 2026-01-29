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
        // Log raw request for debugging
        Log::info('=== PAYMONGO WEBHOOK RECEIVED ===', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'raw_body' => $request->getContent(),
        ]);
        
        $payload = $request->all();

        // Log webhook for debugging
        Log::info('PayMongo Webhook Received', [
            'type' => $payload['type'] ?? 'unknown',
            'data' => $payload['data'] ?? null,
            'full_payload' => $payload,
        ]);

        // Optionally, verify signature here for security

        $event = $payload['type'] ?? null;
        $subscriptionActivated = false; // Track if subscription was found and activated

        // Handle Payment Intent success
        if ($event === 'payment_intent.succeeded') {
            $intentId = $payload['data']['id'] ?? null;

            $subscription = VenueSubscription::where('paymongo_intent_id', $intentId)
                ->where('status', 'pending')
                ->first();

            if ($subscription) {
                $planDuration = config("subscriptions.{$subscription->plan}.duration_days");

                $subscription->update([
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addDays($planDuration),
                    'paymongo_payment_id' => $payload['data']['attributes']['payments'][0]['id'] ?? null,
                ]);

                Log::info('Subscription activated via Payment Intent', [
                    'subscription_id' => $subscription->id,
                    'intent_id' => $intentId,
                ]);

                // Send activation email notification
                $user = $subscription->user;
                if ($user) {
                    $user->notify(new SubscriptionActivatedNotification($subscription));
                }
                $subscriptionActivated = true;
            }
        }
        // Handle Checkout Session payment success
        // PayMongo Checkout Sessions can also trigger payment events
        elseif ($event === 'checkout_session.payment.paid') {
            $sessionData = $payload['data'] ?? [];
            $sessionId = $sessionData['id'] ?? null;
            $paymentId = $sessionData['attributes']['payments'][0]['id'] ?? null;
            
            Log::info('Checkout Session Payment Paid', [
                'event' => $event,
                'session_id' => $sessionId,
                'payment_id' => $paymentId,
                'session_data' => $sessionData,
            ]);
            
            // Try to find subscription by session ID or payment ID
            if ($sessionId) {
                $subscription = VenueSubscription::where('paymongo_intent_id', $sessionId)
                    ->where('status', 'pending')
                    ->first();
                
                if ($subscription) {
                    $planDuration = config("subscriptions.{$subscription->plan}.duration_days");
                    
                    $subscription->update([
                        'status' => 'active',
                        'starts_at' => now(),
                        'ends_at' => now()->addDays($planDuration),
                        'paymongo_payment_id' => $paymentId,
                    ]);
                    
                    Log::info('Subscription activated via Checkout Session', [
                        'subscription_id' => $subscription->id,
                        'session_id' => $sessionId,
                    ]);
                    
                    $user = $subscription->user;
                    if ($user) {
                        $user->notify(new SubscriptionActivatedNotification($subscription));
                    }
                    $subscriptionActivated = true;
                }
            }
        }
        // Handle Payment Link payment success
        // PayMongo Payment Links trigger: link.payment.paid
        elseif ($event === 'link.payment.paid') {
            $linkData = $payload['data'] ?? [];
            $linkId = $linkData['id'] ?? null;
            
            // Get payment ID from attributes - try multiple possible locations
            $paymentId = $linkData['attributes']['payments'][0]['id'] ?? 
                        $linkData['attributes']['payment']['id'] ?? 
                        $linkData['attributes']['payment_id'] ?? null;
            
            Log::info('Payment Link Webhook Processing', [
                'event' => $event,
                'link_id' => $linkId,
                'payment_id' => $paymentId,
                'link_data' => $linkData,
                'link_attributes' => $linkData['attributes'] ?? null,
            ]);
            
            if (!$linkId) {
                Log::warning('Payment Link ID not found in webhook payload', [
                    'payload' => $payload,
                    'data_keys' => array_keys($payload['data'] ?? []),
                ]);
                return response()->json(['status' => 'error', 'message' => 'link_id_not_found'], 400);
            }
            
            // Find subscription by payment link ID (stored in paymongo_intent_id)
            $subscription = VenueSubscription::where('paymongo_intent_id', $linkId)
                ->where('status', 'pending')
                ->first();

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
                    'payment_id' => $paymentId,
                    'status' => 'active',
                ]);

                // Send activation email notification
                $user = $subscription->user;
                if ($user) {
                    $user->notify(new SubscriptionActivatedNotification($subscription));
                }
                $subscriptionActivated = true;
            } else {
                // Try to find by any status
                $anySubscription = VenueSubscription::where('paymongo_intent_id', $linkId)->first();
                Log::warning('Subscription not found for Payment Link', [
                    'link_id' => $linkId,
                    'payment_id' => $paymentId,
                    'found_subscription' => $anySubscription ? [
                        'id' => $anySubscription->id,
                        'status' => $anySubscription->status,
                    ] : null,
                    'all_pending_subscriptions' => VenueSubscription::where('status', 'pending')
                        ->get(['id', 'paymongo_intent_id', 'user_id', 'plan'])->toArray(),
                ]);
            }
        }
        // Also handle generic payment.paid event (fallback)
        elseif ($event === 'payment.paid') {
            $paymentData = $payload['data'] ?? [];
            $paymentId = $paymentData['id'] ?? null;
            
            // Try to find link ID from payment attributes - check multiple possible locations
            $linkId = $paymentData['attributes']['source']['id'] ?? 
                     $paymentData['attributes']['link_id'] ??
                     $paymentData['attributes']['source']['data']['id'] ??
                     $paymentData['attributes']['data']['attributes']['link_id'] ?? null;
            
            Log::info('Payment Paid Webhook (Generic)', [
                'event' => $event,
                'payment_id' => $paymentId,
                'link_id' => $linkId,
                'payment_attributes' => $paymentData['attributes'] ?? null,
            ]);
            
            if ($linkId) {
                $subscription = VenueSubscription::where('paymongo_intent_id', $linkId)
                    ->where('status', 'pending')
                    ->first();
                
                if ($subscription) {
                    $planDuration = config("subscriptions.{$subscription->plan}.duration_days");
                    
                    $subscription->update([
                        'status' => 'active',
                        'starts_at' => now(),
                        'ends_at' => now()->addDays($planDuration),
                        'paymongo_payment_id' => $paymentId,
                    ]);
                    
                    Log::info('Subscription activated via Payment Paid event', [
                        'subscription_id' => $subscription->id,
                        'link_id' => $linkId,
                        'payment_id' => $paymentId,
                    ]);
                    
                    $user = $subscription->user;
                    if ($user) {
                        $user->notify(new SubscriptionActivatedNotification($subscription));
                    }
                    $subscriptionActivated = true;
                } else {
                    Log::warning('Subscription not found for payment.paid event', [
                        'link_id' => $linkId,
                        'payment_id' => $paymentId,
                    ]);
                }
            } else {
                Log::warning('No link_id found in payment.paid event', [
                    'payment_data' => $paymentData,
                ]);
            }
        }
        // Handle Payment failures
        elseif (in_array($event, ['payment_intent.payment_failed', 'payment.failed'])) {
            $intentId = $payload['data']['id'] ?? null;
            $linkId = $payload['data']['attributes']['source']['id'] ?? 
                     $payload['data']['attributes']['link_id'] ?? null;

            Log::info('Payment Failed Webhook', [
                'event' => $event,
                'intent_id' => $intentId,
                'link_id' => $linkId,
            ]);

            // Try to find subscription by intent ID or link ID
            $subscription = null;
            if ($intentId) {
                $subscription = VenueSubscription::where('paymongo_intent_id', $intentId)->first();
            }
            if (!$subscription && $linkId) {
                $subscription = VenueSubscription::where('paymongo_intent_id', $linkId)->first();
            }

            if ($subscription) {
                $subscription->update([
                    'status' => 'failed',
                ]);

                Log::info('Subscription marked as failed', [
                    'subscription_id' => $subscription->id,
                ]);

                // Send payment failure email notification
                $user = $subscription->user;
                if ($user) {
                    $user->notify(new SubscriptionPaymentFailedNotification());
                }
            }
        }

        // Catch-all: Try to find subscription by any ID in the payload
        // This handles cases where event structure might be different
        if (!$subscriptionActivated) {
            Log::info('Trying catch-all subscription lookup', [
                'event' => $event,
                'payload_structure' => array_keys($payload),
            ]);
            
            // Try to extract any ID from the payload
            $possibleIds = [];
            if (isset($payload['data']['id'])) {
                $possibleIds[] = $payload['data']['id'];
            }
            if (isset($payload['data']['attributes']['source']['id'])) {
                $possibleIds[] = $payload['data']['attributes']['source']['id'];
            }
            if (isset($payload['data']['attributes']['link_id'])) {
                $possibleIds[] = $payload['data']['attributes']['link_id'];
            }
            if (isset($payload['data']['attributes']['payments'][0]['id'])) {
                $possibleIds[] = $payload['data']['attributes']['payments'][0]['id'];
            }
            
            // Try to find subscription by any of these IDs
            foreach ($possibleIds as $id) {
                if (is_string($id) && (strpos($id, 'link_') === 0 || strpos($id, 'pi_') === 0)) {
                    $subscription = VenueSubscription::where('paymongo_intent_id', $id)
                        ->where('status', 'pending')
                        ->first();
                    
                    if ($subscription) {
                        $planDuration = config("subscriptions.{$subscription->plan}.duration_days");
                        
                        // Get payment ID
                        $paymentId = $payload['data']['attributes']['payments'][0]['id'] ?? 
                                   $payload['data']['id'] ?? null;
                        
                        $subscription->update([
                            'status' => 'active',
                            'starts_at' => now(),
                            'ends_at' => now()->addDays($planDuration),
                            'paymongo_payment_id' => $paymentId,
                        ]);
                        
                        Log::info('Subscription activated via catch-all handler', [
                            'subscription_id' => $subscription->id,
                            'found_by_id' => $id,
                            'event' => $event,
                        ]);
                        
                        $user = $subscription->user;
                        if ($user) {
                            $user->notify(new SubscriptionActivatedNotification($subscription));
                        }
                        $subscriptionActivated = true;
                        break;
                    }
                }
            }
            
            if (!$subscriptionActivated) {
                Log::warning('No subscription activated from webhook', [
                    'event' => $event,
                    'possible_ids' => $possibleIds,
                    'all_pending' => VenueSubscription::where('status', 'pending')
                        ->get(['id', 'paymongo_intent_id', 'user_id', 'plan'])->toArray(),
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }
}
