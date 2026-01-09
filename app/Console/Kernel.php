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
        $schedule->command('notify:players_to_rate')->dailyAt('08:00');
        // run daily; adjust as needed
        $schedule->command('challonge:refresh-tokens --days=1')->daily();
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}