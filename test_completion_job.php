<?php

/**
 * Test script for CompletePastEventsJob
 * Run this to verify the completion logic is working correctly
 * 
 * Usage: php test_completion_job.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Event;
use Carbon\Carbon;

echo "=== Testing Event Completion Logic ===\n\n";

$now = now();

// Get all events that should be completed
$events = Event::whereNull('cancelled_at')
    ->where(function($q) {
        $q->whereNull('game_status')->orWhere('game_status', '!=', 'completed');
    })
    ->get();

echo "Total events to check: " . $events->count() . "\n\n";

$shouldBeCompleted = [];
$errors = [];

foreach ($events as $event) {
    $endDateTime = null;
    $calculationMethod = '';
    
        try {
            // Multi-day events
            if ($event->end_date && $event->end_date_end_time) {
                $endDateTime = Carbon::parse($event->end_date . ' ' . $event->end_date_end_time);
                $calculationMethod = 'multi-day (end_date + end_date_end_time)';
            }
            // Single-day events (end_time is always required)
            else {
                $endDateTime = Carbon::parse($event->date . ' ' . $event->end_time);
                $calculationMethod = 'single-day (date + end_time)';
            }
        
        if ($endDateTime && $endDateTime->lt($now)) {
            $shouldBeCompleted[] = [
                'id' => $event->id,
                'name' => $event->name,
                'event_type' => $event->event_type,
                'is_tournament_game' => $event->is_tournament_game,
                'game_status' => $event->game_status,
                'date' => $event->date,
                'end_time' => $event->end_time,
                'end_date' => $event->end_date,
                'end_date_end_time' => $event->end_date_end_time,
                'should_have_ended_at' => $endDateTime->format('Y-m-d H:i:s'),
                'hours_ago' => $now->diffInHours($endDateTime),
                'calculation_method' => $calculationMethod,
            ];
        }
    } catch (\Exception $e) {
        $errors[] = [
            'event_id' => $event->id,
            'error' => $e->getMessage(),
        ];
    }
}

echo "Events that should be marked as completed: " . count($shouldBeCompleted) . "\n";
echo "Errors encountered: " . count($errors) . "\n\n";

if (count($shouldBeCompleted) > 0) {
    echo "=== Events That Should Be Completed ===\n";
    echo str_repeat("-", 120) . "\n";
    printf(
        "%-5s | %-20s | %-15s | %-10s | %-15s | %-20s | %-10s\n",
        "ID",
        "Name",
        "Event Type",
        "Tournament",
        "Current Status",
        "Should Have Ended",
        "Hours Ago"
    );
    echo str_repeat("-", 120) . "\n";
    
    foreach ($shouldBeCompleted as $e) {
        printf(
            "%-5d | %-20s | %-15s | %-10s | %-15s | %-20s | %-10d\n",
            $e['id'],
            substr($e['name'], 0, 20),
            $e['event_type'],
            $e['is_tournament_game'] ? 'Yes' : 'No',
            $e['game_status'] ?? 'NULL',
            $e['should_have_ended_at'],
            $e['hours_ago']
        );
    }
    echo str_repeat("-", 120) . "\n\n";
    
    echo "Calculation methods used:\n";
    $methods = array_count_values(array_column($shouldBeCompleted, 'calculation_method'));
    foreach ($methods as $method => $count) {
        echo "  - $method: $count events\n";
    }
    echo "\n";
}

if (count($errors) > 0) {
    echo "=== Errors Encountered ===\n";
    foreach ($errors as $error) {
        echo "Event ID {$error['event_id']}: {$error['error']}\n";
    }
    echo "\n";
}

// Check recent completions
echo "=== Recent Completions (Last 24 Hours) ===\n";
$recentCompletions = Event::where('game_status', 'completed')
    ->where('updated_at', '>=', now()->subDay())
    ->orderBy('updated_at', 'desc')
    ->limit(10)
    ->get(['id', 'name', 'event_type', 'is_tournament_game', 'updated_at']);

echo "Count: " . $recentCompletions->count() . "\n";
if ($recentCompletions->count() > 0) {
    echo str_repeat("-", 100) . "\n";
    printf(
        "%-5s | %-20s | %-15s | %-10s | %-20s\n",
        "ID",
        "Name",
        "Event Type",
        "Tournament",
        "Updated At"
    );
    echo str_repeat("-", 100) . "\n";
    
    foreach ($recentCompletions as $e) {
        printf(
            "%-5d | %-20s | %-15s | %-10s | %-20s\n",
            $e->id,
            substr($e->name, 0, 20),
            $e->event_type,
            $e->is_tournament_game ? 'Yes' : 'No',
            $e->updated_at->format('Y-m-d H:i:s')
        );
    }
    echo str_repeat("-", 100) . "\n";
}
echo "\n";

// Test the job
echo "=== Testing Job Execution ===\n";
echo "To actually run the job and mark events as completed, run:\n";
echo "  php artisan tinker\n";
echo "  (new \\App\\Jobs\\CompletePastEventsJob)->handle();\n";
echo "\n";

echo "=== Test Complete ===\n";
