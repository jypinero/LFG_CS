<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing push notification for user_id 2...\n";

// Create a test notification
$notification = \App\Models\Notification::create([
    'type' => 'message_received',
    'data' => [
        'message' => 'Test push notification! 🔔 This is a test message.',
        'thread_id' => 1,
        'sender_name' => 'Test User'
    ],
    'created_by' => 1,
]);

echo "✅ Created notification: {$notification->id}\n";

// Create user notification (this triggers the observer)
$userNotification = \App\Models\UserNotification::create([
    'notification_id' => $notification->id,
    'user_id' => 2,
    'is_read' => false,
]);

echo "✅ Created user notification: {$userNotification->id}\n";
echo "\n📋 Check the logs to see if push notification was sent:\n";
echo "   storage/logs/laravel.log\n\n";
echo "🔍 Look for:\n";
echo "   - 'UserNotificationObserver triggered'\n";
echo "   - 'Found subscriptions for user' with count: 1\n";
echo "   - 'Push notification sent successfully'\n";
