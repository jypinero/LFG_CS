<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;

class WelcomeNotificationService
{
    /**
     * Random welcome messages
     */
    private $welcomeMessages = [
        "We missed you! Welcome back! 🎉",
        "Hey there! Ready to play some games? 🏀",
        "Welcome back! Let's get active! 💪",
        "Good to see you again! Time to find your next match! ⚽",
        "You're back! Check out what's happening in your area! 🎯",
        "Welcome home! New events are waiting for you! 🏐",
        "Hey! We've got some exciting updates for you! 🎊",
        "Welcome back! Your next game is just a click away! 🏈",
        "Good to have you here! Let's make today active! 🏃",
        "Welcome! Don't miss out on upcoming tournaments! 🏆",
        "Hey there! Ready to join the action? 🎮",
        "Welcome back! Your community is waiting! 👥",
        "Good to see you! New matches are available! 🎲",
        "Welcome home! Let's find your perfect game! 🎯",
        "Hey! Check out what's new in your area! 📍",
    ];

    /**
     * Send a random welcome notification to user
     */
    public function sendWelcomeNotification(int $userId): void
    {
        try {
            // Get random message
            $message = $this->welcomeMessages[array_rand($this->welcomeMessages)];

            // Create notification
            $notification = Notification::create([
                'type' => 'welcome_back',
                'data' => [
                    'message' => $message,
                ],
                'created_by' => $userId, // System notification
            ]);

            // Create user notification (this will trigger push notification via observer)
            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);

            Log::info('Welcome notification sent', [
                'user_id' => $userId,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the login
            Log::error('Failed to send welcome notification', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}





