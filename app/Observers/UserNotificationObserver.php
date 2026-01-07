<?php

namespace App\Observers;

use App\Models\UserNotification;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

class UserNotificationObserver
{
    /**
     * Handle the UserNotification "created" event.
     */
    public function created(UserNotification $userNotification): void
    {
        try {
            Log::info('UserNotificationObserver triggered', [
                'user_notification_id' => $userNotification->id,
                'user_id' => $userNotification->user_id,
                'notification_id' => $userNotification->notification_id,
            ]);

            // Refresh the model to ensure relationships are loaded
            $userNotification->refresh();
            
            // Get the notification - either from relationship or direct query
            $notification = $userNotification->notification;
            
            if (!$notification) {
                // Try direct query as fallback
                $notification = \App\Models\Notification::find($userNotification->notification_id);
            }
            
            if (!$notification) {
                Log::warning('Notification not found for UserNotification', [
                    'user_notification_id' => $userNotification->id,
                    'notification_id' => $userNotification->notification_id,
                ]);
                return;
            }

            Log::info('Sending push notification', [
                'user_id' => $userNotification->user_id,
                'notification_type' => $notification->type,
                'notification_id' => $notification->id,
            ]);

            // Send push notification
            $pushService = app(PushNotificationService::class);
            $pushService->sendToUser($userNotification->user_id, $notification);
            
            Log::info('Push notification sent successfully', [
                'user_notification_id' => $userNotification->id,
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the notification creation
            Log::error('Failed to send push notification', [
                'user_notification_id' => $userNotification->id,
                'notification_id' => $userNotification->notification_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle the UserNotification "updated" event.
     */
    public function updated(UserNotification $userNotification): void
    {
        //
    }

    /**
     * Handle the UserNotification "deleted" event.
     */
    public function deleted(UserNotification $userNotification): void
    {
        //
    }

    /**
     * Handle the UserNotification "restored" event.
     */
    public function restored(UserNotification $userNotification): void
    {
        //
    }

    /**
     * Handle the UserNotification "force deleted" event.
     */
    public function forceDeleted(UserNotification $userNotification): void
    {
        //
    }
}
