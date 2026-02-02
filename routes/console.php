<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tournament and event management schedules
Schedule::command('tournaments:update-statuses')->daily();
Schedule::command('events:close-groupchats')->hourly();
Schedule::command('tournaments:close-registration')->everyMinute();

// Notification schedules
Schedule::command('notify:players_to_rate')->dailyAt('08:00');
Schedule::job(new \App\Jobs\NotifyParticipantsToRateJob)->hourly()->withoutOverlapping();

// Event status management schedules
Schedule::job(new \App\Jobs\StartOngoingEventsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new \App\Jobs\CompletePastEventsJob)->everyMinute()->withoutOverlapping();

// Cleanup schedules
Schedule::command('participants:cleanup')->daily();
Schedule::command('subscriptions:expire')->daily();
Schedule::command('notifications:cleanup --days=30')->daily();

// External service schedules
Schedule::command('challonge:refresh-tokens --days=1')->daily();