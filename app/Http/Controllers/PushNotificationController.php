<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PushSubscription;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class PushNotificationController extends Controller
{
    /**
     * Get VAPID public key
     * GET /api/push/vapid
     */
    public function getVapidKey()
    {
        $publicKey = config('push.vapid.public_key');
        
        if (!$publicKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'VAPID public key not configured'
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'publicKey' => $publicKey
        ]);
    }

    /**
     * Subscribe to push notifications
     * POST /api/push/subscribe
     */
    public function subscribe(Request $request)
    {
        // Debug: Log incoming request
        Log::info('Push subscribe request received', [
            'endpoint_length' => strlen($request->input('endpoint', '')),
            'endpoint_sample' => substr($request->input('endpoint', ''), 0, 60) . '...',
            'has_keys' => $request->has('keys'),
            'keys_p256dh_length' => strlen($request->input('keys.p256dh', '')),
            'keys_auth_length' => strlen($request->input('keys.auth', '')),
            'keys_p256dh_sample' => substr($request->input('keys.p256dh', ''), 0, 30) . '...',
            'keys_auth_sample' => substr($request->input('keys.auth', ''), 0, 15) . '...',
            'raw_keys' => $request->input('keys'),
        ]);

        $validator = Validator::make($request->all(), [
            'endpoint' => 'required|string|max:500',
            'keys' => 'required|array',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            Log::warning('Push subscribe validation failed', [
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid subscription data',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not authenticated'
            ], 401);
        }

        try {
            $keys = $request->input('keys');
            
            // Validate key format - p256dh should be ~87 chars, auth ~22 chars (base64)
            $p256dh = $keys['p256dh'] ?? '';
            $auth = $keys['auth'] ?? '';
            
            Log::info('Saving push subscription', [
                'user_id' => $user->id,
                'p256dh_length' => strlen($p256dh),
                'auth_length' => strlen($auth),
                'p256dh_looks_valid' => strlen($p256dh) >= 80 && strlen($p256dh) <= 100,
                'auth_looks_valid' => strlen($auth) >= 20 && strlen($auth) <= 30,
            ]);

            $subscription = PushSubscription::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'endpoint' => $request->input('endpoint'),
                ],
                [
                    'p256dh' => $p256dh,
                    'auth' => $auth,
                ]
            );

            Log::info('Push subscription saved successfully', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription saved successfully',
                'subscription' => $subscription
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to save push subscription', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save subscription'
            ], 500);
        }
    }

    /**
     * Unsubscribe from push notifications
     * POST /api/push/unsubscribe
     */
    public function unsubscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'endpoint' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid request',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not authenticated'
            ], 401);
        }

        try {
            $deleted = PushSubscription::where('user_id', $user->id)
                ->where('endpoint', $request->input('endpoint'))
                ->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription removed successfully',
                'deleted' => $deleted > 0
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove push subscription', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove subscription'
            ], 500);
        }
    }

    /**
     * Send test welcome notification
     * POST /api/push/test-welcome
     */
    public function sendTestWelcome(Request $request)
    {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not authenticated'
            ], 401);
        }

        // Check if user has any subscriptions
        $subscriptions = PushSubscription::where('user_id', $user->id)->get();
        
        if ($subscriptions->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No push subscriptions found. Please subscribe first.',
                'subscriptionCount' => 0
            ], 400);
        }

        try {
            $welcomeMessages = [
                "Welcome back! Ready to make some plays? 🏀",
                "Great to see you again! Let's find your next game! 🎯",
                "Hello champion! Time to dominate the court! 💪",
                "Hey there! Your next adventure awaits! 🚀",
                "Welcome back, athlete! Let's get moving! ⚡",
            ];
            
            $randomMessage = $welcomeMessages[array_rand($welcomeMessages)];

            // Create a notification record
            $notification = Notification::create([
                'type' => 'welcome_back',
                'data' => [
                    'message' => $randomMessage,
                    'test' => true,
                ],
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            // Create UserNotification (this will trigger the observer to send push)
            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $user->id,
                'created_at' => now(),
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Test notification sent successfully',
                'subscriptionCount' => $subscriptions->count(),
                'welcomeMessage' => $randomMessage,
                'notificationId' => $notification->id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send test notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send test notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
