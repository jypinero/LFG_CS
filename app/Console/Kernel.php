<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\CloseTournamentRegistration::class,
        \App\Console\Commands\SeedDoubleEliminationBracket::class,
        
    ];

    protected function schedule(Schedule $schedule)
    {
        // All schedules have been moved to routes/console.php
        // This method is kept for Laravel compatibility but is now empty
    }

    protected $routeMiddleware = [
        // existing middleware...
        'active.subscription' => \App\Http\Middleware\EnsureActiveVenueSubscription::class,
        'active.subscription' => \App\Http\Middleware\CheckActiveSubscription::class,

    ];

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}