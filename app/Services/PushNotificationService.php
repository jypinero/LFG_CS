<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\Notification;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    private $webPush;

    public function __construct()
    {
        // Ensure OpenSSL config is available (required on Windows for EC key generation)
        $this->ensureOpenSSLConfig();

        $publicKey = config('push.vapid.public_key');
        $privateKey = config('push.vapid.private_key');
        $email = config('push.vapid.email', 'mailto:admin@example.com');

        Log::info('PushNotificationService initialized', [
            'has_public_key' => !empty($publicKey),
            'has_private_key' => !empty($privateKey),
        ]);

        if (!$publicKey || !$privateKey) {
            Log::warning('VAPID keys not configured. Push notifications will not work.');
            return;
        }

        $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => $email,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
    }

    /**
     * Ensure OpenSSL configuration file is available.
     * This is required on Windows for EC key generation used in web push encryption.
     * 
     * NOTE: OPENSSL_CONF must be set BEFORE PHP starts for it to take effect.
     * If push notifications fail with "Unable to create the local key" error:
     * 
     * 1. Set the OPENSSL_CONF environment variable in Windows:
     *    - Open System Properties > Environment Variables
     *    - Add new User variable: OPENSSL_CONF = C:\xampp\apache\conf\openssl.cnf
     *    - Restart Apache/PHP
     * 
     * 2. Or run in PowerShell (persistent):
     *    [System.Environment]::SetEnvironmentVariable('OPENSSL_CONF', 'C:\xampp\apache\conf\openssl.cnf', 'User')
     */
    private function ensureOpenSSLConfig(): void
    {
        // Check if OpenSSL EC key creation works
        $testKey = @openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC
        ]);

        if ($testKey !== false) {
            // OpenSSL is working correctly
            return;
        }

        // OpenSSL EC key creation failed - this is a configuration issue
        $existingConf = getenv('OPENSSL_CONF');
        
        $possibleConfigs = [
            'C:/xampp/apache/conf/openssl.cnf',
            'C:/xampp/php/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            'C:/laragon/etc/ssl/openssl.cnf',
            'C:/php/extras/ssl/openssl.cnf',
        ];

        $foundConfig = null;
        foreach ($possibleConfigs as $config) {
            if (file_exists($config)) {
                $foundConfig = $config;
                break;
            }
        }

        Log::error('OpenSSL EC key generation failed. Push notifications will not work.', [
            'current_openssl_conf' => $existingConf ?: '(not set)',
            'found_config' => $foundConfig,
            'fix' => $foundConfig 
                ? "Set OPENSSL_CONF environment variable to: $foundConfig (must be set before PHP starts)"
                : 'Could not find openssl.cnf file. Install OpenSSL properly.',
            'powershell_command' => $foundConfig 
                ? "[System.Environment]::SetEnvironmentVariable('OPENSSL_CONF', '$foundConfig', 'User')"
                : null,
        ]);
    }

    /**
     * Send push notification to a user
     */
    public function sendToUser(int $userId, Notification $notification, array $options = [])
    {
        Log::info('sendToUser called', [
            'user_id' => $userId,
            'notification_type' => $notification->type,
            'notification_id' => $notification->id,
        ]);

        if (!$this->webPush) {
            Log::warning('WebPush not initialized. Cannot send push notification.');
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $userId)->get();

        Log::info('Found subscriptions for user', [
            'user_id' => $userId,
            'subscription_count' => $subscriptions->count(),
        ]);

        if ($subscriptions->isEmpty()) {
            Log::warning('No push subscriptions found for user', ['user_id' => $userId]);
            return;
        }

        // Get notification title and body from notification data
        $title = $options['title'] ?? $this->getNotificationTitle($notification);
        $body = $options['body'] ?? $this->getNotificationBody($notification);
        $url = $options['url'] ?? $this->getNotificationUrl($notification);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'data' => [
                'url' => $url,
                'notificationId' => $notification->id,
                'type' => $notification->type,
            ],
            'icon' => $options['icon'] ?? '/favicon.ico',
        ]);

        Log::info('Prepared push payload', [
            'title' => $title,
            'body' => $body,
        ]);

        foreach ($subscriptions as $subscription) {
            try {
                // Sanitize keys - ensure they're properly base64url encoded (no padding)
                $p256dh = rtrim(strtr($subscription->p256dh, '+/', '-_'), '=');
                $auth = rtrim(strtr($subscription->auth, '+/', '-_'), '=');
                
                // Debug: Log the actual key data
                Log::debug('Subscription key details', [
                    'subscription_id' => $subscription->id,
                    'endpoint_sample' => substr($subscription->endpoint, 0, 60) . '...',
                    'p256dh_length' => strlen($p256dh),
                    'auth_length' => strlen($auth),
                    'p256dh_sample' => substr($p256dh, 0, 30) . '...',
                    'auth_sample' => substr($auth, 0, 15) . '...',
                    'p256dh_looks_valid' => strlen($p256dh) >= 80,
                    'auth_looks_valid' => strlen($auth) >= 20,
                ]);

                $pushSubscription = Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $p256dh,
                        'auth' => $auth,
                    ],
                ]);

                $this->webPush->queueNotification($pushSubscription, $payload);
                
                Log::debug('Notification queued successfully', [
                    'subscription_id' => $subscription->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to queue push notification', [
                    'user_id' => $userId,
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'p256dh_length' => strlen($subscription->p256dh),
                    'auth_length' => strlen($subscription->auth),
                ]);
            }
        }

        // Send all queued notifications
        $this->flush();
    }

    /**
     * Flush queued notifications and handle responses
     */
    private function flush()
    {
        if (!$this->webPush) {
            Log::warning('WebPush is null when trying to flush');
            return;
        }

        Log::info('Flushing push notifications');
        $successCount = 0;
        $failureCount = 0;

        try {
            foreach ($this->webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                if ($report->isSuccess()) {
                    Log::info('Push notification sent successfully', ['endpoint' => substr($endpoint, 0, 50) . '...']);
                    $successCount++;
                } else {
                    Log::warning('Push notification failed', [
                        'endpoint' => substr($endpoint, 0, 50) . '...',
                        'status' => $report->getStatusCode(),
                        'reason' => $report->getReason()
                    ]);
                    $failureCount++;

                    // If subscription is invalid (404/410), remove it
                    if (in_array($report->getStatusCode(), [404, 410])) {
                        PushSubscription::where('endpoint', $endpoint)->delete();
                        Log::info('Removed invalid push subscription', ['endpoint' => substr($endpoint, 0, 50) . '...']);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Error during flush', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        Log::info('Push flush complete', [
            'success' => $successCount,
            'failed' => $failureCount,
        ]);
    }

    /**
     * Get notification title from notification data
     */
    private function getNotificationTitle(Notification $notification): string
    {
        $type = $notification->type;
        $data = $notification->data ?? [];

        // Map notification types to titles
        $titles = [
            'message_received' => 'New Message',
            'event_joined' => 'Event Joined',
            'team_invitation' => 'Team Invitation',
            'booking_approved' => 'Booking Approved',
            'booking_rejected' => 'Booking Rejected',
            'post_liked' => 'Post Liked',
            'post_commented' => 'New Comment',
            'document_verified' => 'Document Verified',
            'document_rejected' => 'Document Rejected',
            'document_processing_complete' => 'Document Processed',
            'document_manual_review_needed' => 'Document Review Needed',
            'tournament_announcement' => 'Tournament Announcement',
            'team_join_request' => 'Team Join Request',
            'coach_match' => 'Coach Match',
            'coach_match_response' => 'Coach Match Response',
            'welcome_back' => 'Welcome Back!',
        ];

        return $titles[$type] ?? 'New Notification';
    }

    /**
     * Get notification body from notification data
     */
    private function getNotificationBody(Notification $notification): string
    {
        $data = $notification->data ?? [];
        
        if (isset($data['message'])) {
            return $data['message'];
        }

        // Fallback to type-based messages
        $type = $notification->type;
        $messages = [
            'message_received' => 'You have a new message',
            'event_joined' => 'You joined an event',
            'team_invitation' => 'You have a team invitation',
        ];

        return $messages[$type] ?? 'You have a new notification';
    }

    /**
     * Get notification URL from notification data
     * Uses the same mapping logic as NotifController::getRedirectPath
     */
    private function getNotificationUrl(Notification $notification): ?string
    {
        $data = $notification->data ?? [];
        $type = $notification->type;

        // Map notification types to URLs (matching NotifController logic)
        $routes = [
            // Tournament notifications
            'participant_no_show' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'document_verified' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : (isset($data['document_id']) ? "/profile/documents" : null),
            'participant_approved' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'participant_rejected' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'participant_banned' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'waitlist_promoted' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'participant_withdrawn' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'match_disputed' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}/matches" : null,
            'tournament_registration_closed' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'tournament_started' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'tournament_completed' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'tournament_cancelled' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'tournament_announcement' => (isset($data['tournament_id']) && isset($data['announcement_id'])) ? "/tournaments/{$data['tournament_id']}/announcements/{$data['announcement_id']}" : (isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null),
            
            // Event notifications
            'event_joined' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'team_joined_event' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'team_invitation' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'event_updated' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'event_cancelled' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'event_left' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'removed_from_event' => "/events",
            'team_invitation_declined' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            
            // Venue notifications
            'booking_approved' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            'booking_rejected' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            'booking_pending' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            'booking_cancelled' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            'booking_rescheduled' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            
            // Team notifications
            'team_join_request' => isset($data['team_id']) ? "/teams/{$data['team_id']}/requests" : null,
            
            // Post notifications
            'post_liked' => isset($data['post_id']) ? "/posts/{$data['post_id']}" : null,
            'post_commented' => isset($data['post_id']) ? "/posts/{$data['post_id']}" : null,
            
            // Messaging notifications
            'message_received' => isset($data['thread_id']) ? "/messages/{$data['thread_id']}" : null,
            
            // Document notifications
            'document_rejected' => "/profile/documents",
            'document_processing_complete' => "/profile/documents",
            'document_manual_review_needed' => "/profile/documents",
            
            // Coach notifications
            'coach_liked' => isset($data['coach_id']) ? "/coaches/{$data['coach_id']}" : null,
            'coach_match' => isset($data['match_id']) ? "/coaches/matches/{$data['match_id']}" : null,
            'coach_match_response' => isset($data['match_id']) ? "/coaches/matches/{$data['match_id']}" : null,
            
            // Event group chat notifications
            'event_groupchat_created' => isset($data['thread_id']) ? "/messages/{$data['thread_id']}" : (isset($data['event_id']) ? "/events/{$data['event_id']}" : null),
            'event_groupchat_joined' => isset($data['thread_id']) ? "/messages/{$data['thread_id']}" : (isset($data['event_id']) ? "/events/{$data['event_id']}" : null),
            'event_groupchat_closed' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            
            // Welcome notifications
            'welcome_back' => '/home',
        ];

        return $routes[$type] ?? null;
    }
}

