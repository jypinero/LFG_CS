<?php

// php artisan make:command CloseTournamentRegistration

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tournament;
use Carbon\Carbon;

class CloseTournamentRegistration extends Command
{
    protected $signature = 'tournaments:close-registration';
    protected $description = 'Auto-close tournament registrations';

    public function handle()
    {
        Tournament::where('status', 'open_registration')
            ->whereNotNull('registration_deadline')
            ->where('registration_deadline', '<=', now())
            ->update(['status' => 'registration_closed']);

        $this->info('Tournament registrations closed.');
    }
}
