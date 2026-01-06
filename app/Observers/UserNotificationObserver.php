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
            // Load the notification relationship
            $notification = $userNotification->notification;
            
            if (!$notification) {
                return;
            }

            // Send push notification
            $pushService = app(PushNotificationService::class);
            $pushService->sendToUser($userNotification->user_id, $notification);
        } catch (\Exception $e) {
            // Log error but don't fail the notification creation
            Log::error('Failed to send push notification', [
                'user_notification_id' => $userNotification->id,
                'error' => $e->getMessage()
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
