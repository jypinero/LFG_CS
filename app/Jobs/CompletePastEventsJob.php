<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;
use App\Models\Event;
use App\Jobs\NotifyParticipantsToRateJob;
use Illuminate\Support\Facades\Schema;

class CompletePastEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $now = now();

        // Get all events that should be marked as completed
        // This includes: free for all, team vs team, tournament events, and is_tournament_game = 1
        Event::whereNull('cancelled_at')
            ->where(function($q) {
                $q->whereNull('game_status')->orWhere('game_status', '!=', 'completed');
            })
            ->chunkById(100, function($events) use ($now) {
                foreach ($events as $event) {
                    // Determine the event end datetime
                    $eventEndDateTime = $this->getEventEndDateTime($event);
                    
                    // If event end time has passed, mark as completed
                    if ($eventEndDateTime && $eventEndDateTime->lt($now)) {
                        $event->update([
                            'game_status' => 'completed',
                        ]);
                    }
                }
            });

        // Dispatch notification job (it will only notify events not already marked notified)
        NotifyParticipantsToRateJob::dispatch();
    }

    /**
     * Get the end datetime for an event, handling multi-day events
     * 
     * @param Event $event
     * @return Carbon|null
     */
    private function getEventEndDateTime($event)
    {
        try {
            // For multi-day events, use end_date and end_date_end_time
            if ($event->end_date && $event->end_date_end_time) {
                return Carbon::parse($event->end_date . ' ' . $event->end_date_end_time);
            }
            
            // For single-day events, use date and end_time (end_time is always required)
            return Carbon::parse($event->date . ' ' . $event->end_time);
            
        } catch (\Exception $e) {
            // Log error but don't break the job
            \Log::warning("Error parsing event end datetime for event {$event->id}: " . $e->getMessage());
            return null;
        }
    }
}