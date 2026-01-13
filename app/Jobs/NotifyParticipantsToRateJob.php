<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Notification;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

class NotifyParticipantsToRateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $now = now();

        $query = Event::query()
            ->where('is_approved', true)
            ->whereNull('cancelled_at')
            // events already ended
            ->whereRaw("CONCAT(date,' ',end_time) < ?", [$now->format('Y-m-d H:i:s')]);

        if (Schema::hasColumn('events', 'is_rating_notified')) {
            $query->where('is_rating_notified', false);
        }

        $query->chunk(50, function($events) {
            foreach ($events as $event) {
                DB::transaction(function() use ($event) {
                    $participantIds = EventParticipant::where('event_id', $event->id)
                        ->pluck('user_id')
                        ->unique()
                        ->filter(function($id) use ($event) {
                            return $id && $id != $event->created_by;
                        })->values();

                    if ($participantIds->isEmpty()) {
                        if (Schema::hasColumn('events', 'is_rating_notified')) {
                            $event->update(['is_rating_notified' => true]);
                        }
                        return;
                    }

                    $notification = Notification::create([
                        'type' => 'rate_venue',
                        'data' => [
                            'message' => "Please rate the venue for your recent game: {$event->name}",
                            'event_id' => $event->id,
                            'venue_id' => $event->venue_id,
                        ],
                        'created_by' => $event->created_by,
                    ]);

                    foreach ($participantIds as $userId) {
                        UserNotification::create([
                            'notification_id' => $notification->id,
                            'user_id' => $userId,
                            'pinned' => false,
                            'is_read' => false,
                            'action_state' => 'pending'
                        ]);
                    }

                    if (Schema::hasColumn('events', 'is_rating_notified')) {
                        $event->update(['is_rating_notified' => true]);
                    }
                });
            }
        });
    }
}