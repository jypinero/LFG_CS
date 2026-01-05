<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventGame;

class SeedDoubleEliminationBracket extends Command
{
    protected $signature = 'tournament:seed-bracket {event_id}';

    protected $description = 'Seed the first round of a double elimination tournament bracket';

    public function handle()
    {
        $eventId = $this->argument('event_id');

        $event = Event::with('tournament')->find($eventId);

        if (!$event) {
            $this->error("Event with ID $eventId not found.");
            return 1;
        }

        $this->info("Seeding double elimination bracket for Event: {$event->name} (ID: $eventId)");

        // Fetch approved participants
        $participants = EventParticipant::where('event_id', $eventId)
            ->where('status', 'approved')
            ->get();

        if ($participants->count() < 2) {
            $this->error("Not enough approved participants to seed bracket.");
            return 1;
        }

        // Determine if team-based or individual
        $isTeamBased = strtolower($event->tournament->tournament_type) === 'team vs team';

        // Extract IDs accordingly
        $entries = $participants->pluck($isTeamBased ? 'team_id' : 'user_id')->filter()->unique()->values();

        // Shuffle for fairness
        $entries = $entries->shuffle();

        // Optional: Clear existing EventGames for this event to avoid duplicates
        EventGame::where('event_id', $eventId)->delete();

        $matchNumber = 1;

        for ($i = 0; $i < $entries->count(); $i += 2) {
            $data = [
                'event_id' => $eventId,
                'tournament_id' => $event->tournament_id,
                'round_number' => 1,
                'match_number' => $matchNumber++,
                'match_stage' => 'winners',
                'status' => 'scheduled',
            ];

            if ($isTeamBased) {
                $data['team_a_id'] = $entries[$i];
                $data['team_b_id'] = $entries[$i + 1] ?? null;
            } else {
                $data['user_a_id'] = $entries[$i];
                $data['user_b_id'] = $entries[$i + 1] ?? null;
            }

            EventGame::create($data);
        }

        $this->info('Bracket seeded successfully.');

        return 0;
    }
}
