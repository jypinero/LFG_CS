<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Scheduler Verification ===\n\n";

// 1. Check scheduled tasks
echo "1. Scheduled tasks:\n";
echo "-------------------\n";
$schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
$events = $schedule->events();
foreach ($events as $event) {
    if (strpos($event->command, 'NotifyParticipantsToRateJob') !== false) {
        echo "✓ Found: NotifyParticipantsToRateJob\n";
        echo "  Expression: {$event->expression}\n";
        echo "  Description: {$event->description}\n";
    }
}
echo "\n";

// 2. Check recent notifications
echo "2. Recent venue rating notifications (last 2 hours):\n";
echo "-------------------\n";
$recent = \App\Models\Notification::where('type', 'rate_venue')
    ->where('created_at', '>=', now()->subHours(2))
    ->count();
echo "Count: {$recent}\n";
if ($recent > 0) {
    $notifications = \App\Models\Notification::where('type', 'rate_venue')
        ->where('created_at', '>=', now()->subHours(2))
        ->get(['id', 'data', 'created_at']);
    foreach ($notifications as $n) {
        echo "  - ID: {$n->id}, Event: " . ($n->data['event_id'] ?? 'N/A') . ", Venue: " . ($n->data['venue_id'] ?? 'N/A') . ", Created: {$n->created_at}\n";
    }
}
echo "\n";

// 3. Check events marked as notified
echo "3. Events marked as notified (last 2 hours):\n";
echo "-------------------\n";
$notified = \App\Models\Event::where('is_rating_notified', true)
    ->where('updated_at', '>=', now()->subHours(2))
    ->count();
echo "Count: {$notified}\n";
if ($notified > 0) {
    $events = \App\Models\Event::where('is_rating_notified', true)
        ->where('updated_at', '>=', now()->subHours(2))
        ->get(['id', 'name', 'venue_id', 'updated_at']);
    foreach ($events as $e) {
        echo "  - ID: {$e->id}, Name: {$e->name}, Venue: {$e->venue_id}, Updated: {$e->updated_at}\n";
    }
}
echo "\n";

// 4. Check eligible events
echo "4. Eligible events waiting to be processed:\n";
echo "-------------------\n";
$now = now();
$eligible = \App\Models\Event::where('is_approved', true)
    ->whereNull('cancelled_at')
    ->whereRaw("CONCAT(date,' ',end_time) < ?", [$now->format('Y-m-d H:i:s')])
    ->where('is_rating_notified', false)
    ->get(['id', 'name', 'venue_id', 'date', 'end_time']);
echo "Count: {$eligible->count()}\n";
if ($eligible->count() > 0) {
    echo "These events should be processed:\n";
    foreach ($eligible as $e) {
        $endTime = \Carbon\Carbon::parse($e->date . ' ' . $e->end_time);
        $hoursAgo = now()->diffInHours($endTime);
        echo "  - ID: {$e->id}, Name: {$e->name}, Venue: {$e->venue_id}, Ended: {$hoursAgo} hours ago\n";
    }
} else {
    echo "No eligible events found (all have been processed or none have ended yet)\n";
}
echo "\n";

// 5. Check user notifications
echo "5. User notifications created (last 2 hours):\n";
echo "-------------------\n";
$userNotifs = \App\Models\UserNotification::whereHas('notification', function($q) {
        $q->where('type', 'rate_venue')
          ->where('created_at', '>=', now()->subHours(2));
    })
    ->count();
echo "Count: {$userNotifs}\n";
echo "\n";

echo "=== Verification Complete ===\n";
echo "\nTo test the job manually, run:\n";
echo "php artisan tinker\n";
echo "Then: (new \\App\\Jobs\\NotifyParticipantsToRateJob)->handle();\n";
