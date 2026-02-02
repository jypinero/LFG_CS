<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;
use App\Models\Event;

class StartOngoingEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $now = now();

        // Get events that should be in progress
        // Events that have started but not ended, and are still "scheduled" or null
        Event::whereNull('cancelled_at')
            ->where(function($q) {
                $q->whereNull('game_status')
                  ->orWhere('game_status', 'scheduled');
            })
            ->chunkById(100, function($events) use ($now) {
                foreach ($events as $event) {
                    $eventStartDateTime = $this->getEventStartDateTime($event);
                    $eventEndDateTime = $this->getEventEndDateTime($event);
                    
                    // If event has started but not ended, mark as in_progress
                    if ($eventStartDateTime && 
                        $eventEndDateTime && 
                        $now->gte($eventStartDateTime) && 
                        $now->lt($eventEndDateTime)) {
                        $event->update([
                            'game_status' => 'in_progress',
                        ]);
                    }
                }
            });
    }

    /**
     * Get the start datetime for an event
     * 
     * @param Event $event
     * @return Carbon|null
     */
    private function getEventStartDateTime($event)
    {
        try {
            // For all events, start is on the original date with start_time
            return Carbon::parse($event->date . ' ' . $event->start_time);
        } catch (\Exception $e) {
            // Log error but don't break the job
            \Log::warning("Error parsing event start datetime for event {$event->id}: " . $e->getMessage());
            return null;
        }
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
