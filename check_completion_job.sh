#!/bin/bash

echo "=== Event Completion Job Verification ==="
echo ""

echo "1. Checking if Laravel scheduler is in crontab:"
echo "------------------------------"
if crontab -l 2>/dev/null | grep -q "schedule:run"; then
    echo "✓ Laravel scheduler found in crontab"
    crontab -l 2>/dev/null | grep "schedule:run"
else
    echo "✗ Laravel scheduler NOT found in crontab"
    echo "  You need to add: * * * * * cd /path/to/your/project && php artisan schedule:run >> /dev/null 2>&1"
fi
echo ""

echo "2. Testing scheduler manually:"
echo "------------------------------"
php artisan schedule:run -v
echo ""

echo "3. Checking CompletePastEventsJob in schedule:"
echo "------------------------------"
php artisan schedule:list | grep -i "CompletePastEvents" || echo "Job not found in schedule list"
echo ""

echo "4. Events that should be completed (past end time but not marked completed):"
echo "------------------------------"
php artisan tinker --execute="
\$now = now();
\$events = \App\Models\Event::whereNull('cancelled_at')
    ->where(function(\$q) {
        \$q->whereNull('game_status')->orWhere('game_status', '!=', 'completed');
    })
    ->get();

\$shouldBeCompleted = [];
foreach (\$events as \$event) {
    \$endDateTime = null;
    
    // Multi-day events
    if (\$event->end_date && \$event->end_date_end_time) {
        try {
            \$endDateTime = \Carbon\Carbon::parse(\$event->end_date . ' ' . \$event->end_date_end_time);
        } catch (\Exception \$e) {
            continue;
        }
    }
    // Single-day events (end_time is always required)
    else {
        try {
            \$endDateTime = \Carbon\Carbon::parse(\$event->date . ' ' . \$event->end_time);
        } catch (\Exception \$e) {
            continue;
        }
    }
    
    if (\$endDateTime && \$endDateTime->lt(\$now)) {
        \$shouldBeCompleted[] = [
            'id' => \$event->id,
            'name' => \$event->name,
            'event_type' => \$event->event_type,
            'is_tournament_game' => \$event->is_tournament_game,
            'game_status' => \$event->game_status,
            'date' => \$event->date,
            'end_time' => \$event->end_time,
            'end_date' => \$event->end_date,
            'end_date_end_time' => \$event->end_date_end_time,
            'should_have_ended_at' => \$endDateTime->format('Y-m-d H:i:s'),
            'hours_ago' => \$now->diffInHours(\$endDateTime),
        ];
    }
}

echo 'Count: ' . count(\$shouldBeCompleted) . PHP_EOL;
if (count(\$shouldBeCompleted) > 0) {
    echo PHP_EOL . 'Events that should be marked as completed:' . PHP_EOL;
    foreach (\$shouldBeCompleted as \$e) {
        echo sprintf(
            '  - ID: %d | Type: %s | Tournament: %s | Status: %s | Ended: %s (%d hours ago)',
            \$e['id'],
            \$e['event_type'],
            \$e['is_tournament_game'] ? 'Yes' : 'No',
            \$e['game_status'] ?? 'NULL',
            \$e['should_have_ended_at'],
            \$e['hours_ago']
        ) . PHP_EOL;
    }
} else {
    echo 'No events found that should be completed.' . PHP_EOL;
}
"
echo ""

echo "5. Recent completion activity (last 24 hours):"
echo "------------------------------"
php artisan tinker --execute="
\$count = \App\Models\Event::where('game_status', 'completed')
    ->where('updated_at', '>=', now()->subDay())
    ->count();
echo 'Events marked as completed in last 24 hours: ' . \$count . PHP_EOL;

if (\$count > 0) {
    \$events = \App\Models\Event::where('game_status', 'completed')
        ->where('updated_at', '>=', now()->subDay())
        ->orderBy('updated_at', 'desc')
        ->limit(10)
        ->get(['id', 'name', 'event_type', 'is_tournament_game', 'updated_at']);
    
    echo PHP_EOL . 'Recently completed events:' . PHP_EOL;
    foreach (\$events as \$e) {
        echo sprintf(
            '  - ID: %d | %s | Type: %s | Tournament: %s | Updated: %s',
            \$e->id,
            \$e->name,
            \$e->event_type,
            \$e->is_tournament_game ? 'Yes' : 'No',
            \$e->updated_at->format('Y-m-d H:i:s')
        ) . PHP_EOL;
    }
}
"
echo ""

echo "6. Testing the job manually:"
echo "------------------------------"
echo "To test the job manually, run:"
echo "php artisan tinker"
echo "Then: (new \\App\\Jobs\\CompletePastEventsJob)->handle();"
echo ""

echo "7. Checking queue worker status (if using queues):"
echo "------------------------------"
if command -v supervisorctl &> /dev/null; then
    supervisorctl status | grep -i "laravel-worker\|queue" || echo "No queue workers found in supervisor"
else
    echo "Supervisor not found. Check if queue workers are running manually."
fi
echo ""

echo "=== Verification Complete ==="
