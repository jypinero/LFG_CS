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
        $publicKey = env('VAPID_PUBLIC_KEY');
        $privateKey = env('VAPID_PRIVATE_KEY');
        $email = env('VAPID_EMAIL', 'mailto:admin@example.com');

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
     * Send push notification to a user
     */
    public function sendToUser(int $userId, Notification $notification, array $options = [])
    {
        if (!$this->webPush) {
            Log::warning('WebPush not initialized. Cannot send push notification.');
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $userId)->get();

        if ($subscriptions->isEmpty()) {
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

        foreach ($subscriptions as $subscription) {
            try {
                $pushSubscription = Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $subscription->p256dh,
                        'auth' => $subscription->auth,
                    ],
                ]);

                $this->webPush->queueNotification($pushSubscription, $payload);
            } catch (\Exception $e) {
                Log::error('Failed to queue push notification', [
                    'user_id' => $userId,
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage()
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
            return;
        }

        foreach ($this->webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                Log::debug('Push notification sent successfully', ['endpoint' => $endpoint]);
            } else {
                Log::warning('Push notification failed', [
                    'endpoint' => $endpoint,
                    'reason' => $report->getReason()
                ]);

                // If subscription is invalid (404/410), remove it
                if (in_array($report->getStatusCode(), [404, 410])) {
                    PushSubscription::where('endpoint', $endpoint)->delete();
                    Log::info('Removed invalid push subscription', ['endpoint' => $endpoint]);
                }
            }
        }
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

