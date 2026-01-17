<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\Event;
use App\Models\User;
use App\Mail\RateVenueNotificationMail;
use Illuminate\Support\Facades\Mail;

$eventId = 30; // Change this if needed

echo "Looking for rate_venue notification for event_id={$eventId}...\n\n";

$notification = Notification::where('type', 'rate_venue')
    ->whereJsonContains('data->event_id', $eventId)
    ->first();

if (!$notification) {
    echo "No notification found for event_id={$eventId}\n";
    exit(1);
}

echo "Found notification ID: {$notification->id}\n";
echo "Event ID: " . ($notification->data['event_id'] ?? 'N/A') . "\n";
echo "Venue ID: " . ($notification->data['venue_id'] ?? 'N/A') . "\n\n";

$userNotifications = UserNotification::where('notification_id', $notification->id)->get();
echo "User notifications count: {$userNotifications->count()}\n\n";

$event = Event::find($notification->data['event_id'] ?? null);
if (!$event) {
    echo "Event not found!\n";
    exit(1);
}

$event->load('venue');
if (!$event->venue) {
    echo "Venue not found for event!\n";
    exit(1);
}

$emailsSent = 0;
foreach ($userNotifications as $un) {
    $user = User::find($un->user_id);
    if ($user && $user->email) {
        echo "Resending email to: {$user->email} (User ID: {$user->id})...\n";
        try {
            Mail::to($user->email)->send(new RateVenueNotificationMail($event, $user, $event->venue));
            echo "✓ Email sent successfully!\n\n";
            $emailsSent++;
        } catch (\Exception $e) {
            echo "✗ Failed to send email: " . $e->getMessage() . "\n\n";
        }
    } else {
        echo "Skipping user ID {$un->user_id} - no email address\n\n";
    }
}

echo "Total emails sent: {$emailsSent}\n";
