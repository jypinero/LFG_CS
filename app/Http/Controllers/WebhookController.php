<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription; // or your relevant model
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function handlePaymongoWebhook(Request $request)
    {
        $payload = $request->all();

        if ($payload['type'] === 'payment.intent.succeeded') {
            $intentId = $payload['data']['attributes']['id'];
            
            $subscription = VenueSubscription::where('paymongo_intent_id', $intentId)->first();
            
            if ($subscription) {
                $subscription->status = 'active';
                $subscription->save();
            }
        }

        return response()->json(['received' => true]);
    }

    protected function verifySignature(string $payload, ?string $signature): bool
    {
        if (!$signature) return false;

        $secret = config('paymongo.webhook_secret');

        // PayMongo's signature is computed as: hash_hmac('sha256', $payload, $secret)
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    protected function handlePaymentSucceeded(array $data)
    {
        $paymentIntentId = $data['data']['id'] ?? null;

        if (!$paymentIntentId) {
            Log::warning('No payment intent ID in webhook data');
            return;
        }

        // Update your subscription or order in DB based on paymentIntentId
        $subscription = Subscription::where('paymongo_intent_id', $paymentIntentId)->first();

        if (!$subscription) {
            Log::warning("Subscription not found for PaymentIntent: {$paymentIntentId}");
            return;
        }

        $subscription->status = 'active';
        $subscription->save();

        Log::info("Subscription {$subscription->id} marked as active.");
    }

    protected function handlePaymentFailed(array $data)
    {
        // Similar logic for failed payment - update DB, notify user, etc.
    }
}
