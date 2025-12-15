<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tournament;
use App\Models\TeamMatchup;
use Carbon\Carbon;

class UpdateTournamentStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tournaments:update-statuses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically update tournament statuses based on dates and match completion';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::today();
        $updated = 0;

        // Check registration deadlines - transition to registration_closed
        $tournaments = Tournament::where('status', 'open_registration')
            ->whereNotNull('registration_deadline')
            ->where('registration_deadline', '<', $today)
            ->get();

        foreach ($tournaments as $tournament) {
            $tournament->update(['status' => 'registration_closed']);
            $updated++;
            $this->info("Tournament {$tournament->id} ({$tournament->name}) moved to registration_closed");
        }

        // Check start dates - transition to ongoing
        $tournaments = Tournament::whereIn('status', ['draft', 'open_registration', 'registration_closed'])
            ->where('start_date', '<=', $today)
            ->get();

        foreach ($tournaments as $tournament) {
            $tournament->update(['status' => 'ongoing']);
            $updated++;
            $this->info("Tournament {$tournament->id} ({$tournament->name}) moved to ongoing");
        }

        // Check end dates - transition to completed
        $tournaments = Tournament::where('status', 'ongoing')
            ->where('end_date', '<', $today)
            ->get();

        foreach ($tournaments as $tournament) {
            $tournament->update(['status' => 'completed']);
            $updated++;
            $this->info("Tournament {$tournament->id} ({$tournament->name}) moved to completed");
        }

        // Check if all matches are completed - transition to completed
        $ongoingTournaments = Tournament::where('status', 'ongoing')->get();
        
        foreach ($ongoingTournaments as $tournament) {
            $totalMatches = TeamMatchup::where('tournament_id', $tournament->id)->count();
            $completedMatches = TeamMatchup::where('tournament_id', $tournament->id)
                ->whereIn('status', ['completed', 'forfeited', 'bye'])
                ->count();

            if ($totalMatches > 0 && $totalMatches === $completedMatches) {
                $tournament->update(['status' => 'completed']);
                $updated++;
                $this->info("Tournament {$tournament->id} ({$tournament->name}) moved to completed (all matches finished)");
            }
        }

        $this->info("Updated {$updated} tournament(s)");
        return Command::SUCCESS;
    }
}

