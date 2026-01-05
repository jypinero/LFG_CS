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
        // Run your custom command every minute (adjust as needed)
        $schedule->command('tournaments:close-registration')->everyMinute();
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}