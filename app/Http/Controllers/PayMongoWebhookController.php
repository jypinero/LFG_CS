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

        // PayMongo sends event type nested in data.attributes.type, not at top level
        // Handle both structures for compatibility
        $event = $payload['type'] ?? $payload['data']['attributes']['type'] ?? null;
        
        // Extract the actual data object (link/payment) from nested structure
        $eventData = $payload['data']['attributes']['data'] ?? $payload['data'] ?? null;

        // Log webhook for debugging
        Log::info('PayMongo Webhook Received', [
            'event_type' => $event,
            'event_data_id' => $eventData['id'] ?? null,
            'payload_structure' => [
                'has_top_level_type' => isset($payload['type']),
                'has_nested_type' => isset($payload['data']['attributes']['type']),
                'data_keys' => array_keys($payload['data'] ?? []),
            ],
        ]);

        // Optionally, verify signature here for security

        $subscriptionActivated = false; // Track if subscription was found and activated

        // Handle Payment Intent success
        if ($event === 'payment_intent.succeeded') {
            $intentId = $eventData['id'] ?? null;

            $subscription = VenueSubscription::where('paymongo_intent_id', $intentId)
                ->where('status', 'pending')
                ->first();

            if ($subscription) {
                $planDuration = config("subscriptions.{$subscription->plan}.duration_days");
                
                // Get payment ID from nested structure
                $paymentId = $eventData['attributes']['payments'][0]['id'] ?? 
                           $eventData['attributes']['payments'][0]['data']['id'] ?? null;

                $subscription->update([
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addDays($planDuration),
                    'paymongo_payment_id' => $paymentId,
                ]);

                Log::info('Subscription activated via Payment Intent', [
                    'subscription_id' => $subscription->id,
                    'intent_id' => $intentId,
                    'payment_id' => $paymentId,
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
            $sessionData = $eventData ?? [];
            $sessionId = $sessionData['id'] ?? null;
            $paymentId = $sessionData['attributes']['payments'][0]['data']['id'] ?? 
                        $sessionData['attributes']['payments'][0]['id'] ?? null;
            
            Log::info('Checkout Session Payment Paid', [
                'event' => $event,
                'session_id' => $sessionId,
                'payment_id' => $paymentId,
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
            // Use eventData which has the correct nested structure
            $linkData = $eventData ?? [];
            $linkId = $linkData['id'] ?? null;
            
            // Get payment ID from nested payments array
            $paymentId = null;
            if (isset($linkData['attributes']['payments'][0]['data']['id'])) {
                $paymentId = $linkData['attributes']['payments'][0]['data']['id'];
            } elseif (isset($linkData['attributes']['payments'][0]['id'])) {
                $paymentId = $linkData['attributes']['payments'][0]['id'];
            }
            
            Log::info('Payment Link Webhook Processing', [
                'event' => $event,
                'link_id' => $linkId,
                'payment_id' => $paymentId,
                'link_data_structure' => [
                    'has_id' => isset($linkData['id']),
                    'has_attributes' => isset($linkData['attributes']),
                    'has_payments' => isset($linkData['attributes']['payments']),
                    'payments_count' => count($linkData['attributes']['payments'] ?? []),
                ],
            ]);
            
            if (!$linkId) {
                Log::warning('Payment Link ID not found in webhook payload', [
                    'event_data' => $eventData,
                    'link_data' => $linkData,
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
        // Note: payment.paid doesn't directly contain link_id, but we can find it via external_reference_number
        // or wait for link.payment.paid which always comes after payment.paid
        elseif ($event === 'payment.paid') {
            // Use eventData which has the correct nested structure
            $paymentData = $eventData ?? [];
            $paymentId = $paymentData['id'] ?? null;
            
            // payment.paid events from links have origin="links" but don't have direct link_id
            // We need to find the link by querying PayMongo API or by external_reference_number
            // For now, we'll log it but rely on link.payment.paid to activate
            $externalRef = $paymentData['attributes']['external_reference_number'] ?? null;
            $origin = $paymentData['attributes']['origin'] ?? null;
            
            Log::info('Payment Paid Webhook (Generic)', [
                'event' => $event,
                'payment_id' => $paymentId,
                'external_ref' => $externalRef,
                'origin' => $origin,
                'note' => 'link.payment.paid should follow this event and will activate subscription',
            ]);
            
            // If origin is "links", we know it's from a payment link
            // But we can't get link_id directly from payment.paid
            // The link.payment.paid event will handle activation
            // So we'll just log and wait for link.payment.paid
        }
        // Handle Payment failures
        elseif (in_array($event, ['payment_intent.payment_failed', 'payment.failed'])) {
            $intentId = $eventData['id'] ?? null;
            $linkId = $eventData['attributes']['source']['id'] ?? 
                     $eventData['attributes']['link_id'] ?? null;

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
        if (!$subscriptionActivated && $eventData) {
            Log::info('Trying catch-all subscription lookup', [
                'event' => $event,
                'event_data_id' => $eventData['id'] ?? null,
            ]);
            
            // Try to extract link/payment IDs from the correct nested structure
            $possibleIds = [];
            
            // From eventData (the actual link/payment object)
            if (isset($eventData['id'])) {
                $possibleIds[] = $eventData['id'];
            }
            
            // From nested attributes
            if (isset($eventData['attributes']['source']['id'])) {
                $possibleIds[] = $eventData['attributes']['source']['id'];
            }
            
            // Try to find subscription by any of these IDs
            foreach ($possibleIds as $id) {
                if (is_string($id) && (strpos($id, 'link_') === 0 || strpos($id, 'pi_') === 0)) {
                    $subscription = VenueSubscription::where('paymongo_intent_id', $id)
                        ->where('status', 'pending')
                        ->first();
                    
                    if ($subscription) {
                        $planDuration = config("subscriptions.{$subscription->plan}.duration_days");
                        
                        // Get payment ID from eventData
                        $paymentId = null;
                        if (isset($eventData['attributes']['payments'][0]['data']['id'])) {
                            $paymentId = $eventData['attributes']['payments'][0]['data']['id'];
                        } elseif (isset($eventData['attributes']['payments'][0]['id'])) {
                            $paymentId = $eventData['attributes']['payments'][0]['id'];
                        } elseif (isset($eventData['id']) && strpos($eventData['id'], 'pay_') === 0) {
                            $paymentId = $eventData['id'];
                        }
                        
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
                            'payment_id' => $paymentId,
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
                    'event_data_id' => $eventData['id'] ?? null,
                    'all_pending' => VenueSubscription::where('status', 'pending')
                        ->get(['id', 'paymongo_intent_id', 'user_id', 'plan'])->toArray(),
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }
}
