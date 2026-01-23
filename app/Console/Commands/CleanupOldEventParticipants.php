<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\EventParticipant;
use App\Models\Event;
use App\Models\Tournament;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupOldEventParticipants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'participants:cleanup {--days=30 : Number of days after tournament end to keep pending participants}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup old unacted (pending) event participants from completed tournaments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("Starting cleanup of old pending participants (older than {$days} days after tournament completion)...");

        // Find participants with status 'pending' where:
        // 1. Tournament is completed, OR
        // 2. Registration datetime is older than cutoff date
        $participantsToDelete = EventParticipant::where('status', 'pending')
            ->where(function($query) use ($cutoffDate) {
                // Participants from completed tournaments
                $query->whereHas('event.tournament', function($q) {
                    $q->where('status', 'completed');
                })
                // OR participants with registration_datetime older than cutoff
                ->orWhere(function($q) use ($cutoffDate) {
                    $q->whereNotNull('registration_datetime')
                      ->where('registration_datetime', '<', $cutoffDate);
                })
                // OR participants from tournaments that ended more than X days ago
                ->orWhereHas('event.tournament', function($q) use ($cutoffDate) {
                    $q->where('status', 'completed')
                      ->where(function($tq) use ($cutoffDate) {
                          $tq->whereNotNull('end_date')
                             ->where('end_date', '<', $cutoffDate);
                      });
                });
            })
            ->get();

        $count = $participantsToDelete->count();
        
        if ($count === 0) {
            $this->info("No old pending participants found to cleanup.");
            return Command::SUCCESS;
        }

        $this->info("Found {$count} pending participant(s) to cleanup.");

        $deleted = 0;
        foreach ($participantsToDelete as $participant) {
            $event = $participant->event;
            $tournament = $event ? $event->tournament : null;
            
            Log::info('Cleaning up old pending participant', [
                'participant_id' => $participant->id,
                'event_id' => $participant->event_id,
                'tournament_id' => $tournament ? $tournament->id : null,
                'tournament_name' => $tournament ? $tournament->name : null,
                'registration_datetime' => $participant->registration_datetime,
                'user_id' => $participant->user_id,
            ]);

            $participant->delete();
            $deleted++;
        }

        $this->info("Successfully cleaned up {$deleted} pending participant(s).");
        Log::info("Cleanup completed: {$deleted} old pending participants removed");

        return Command::SUCCESS;
    }
}
