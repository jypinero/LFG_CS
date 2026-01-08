<?php 

// app/Console/Commands/NotifyPlayersToRate.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use App\Models\Notification;
use Carbon\Carbon;

class NotifyPlayersToRate extends Command
{
    protected $signature = 'notify:players_to_rate';

    protected $description = 'Notify players to rate participants of finished events';

    public function handle()
    {
        $now = Carbon::now();

        $events = Event::where('is_rated', false)
            ->whereRaw("CONCAT(date, ' ', end_time) < ?", [$now])
            ->with('participants.user')
            ->get();

        foreach ($events as $event) {
            foreach ($event->participants as $participant) {
                Notification::create([
                    'type' => 'rate_players',
                    'data' => [
                        'event_id' => $event->id,
                        'message' => "Please rate players from your recent game: {$event->name}",
                    ],
                    'created_by' => $event->created_by,
                    'user_id' => $participant->user_id,
                ]);
            }

            $event->update(['is_rated' => true]);
        }

        $this->info('Notifications sent successfully.');
    }
}
