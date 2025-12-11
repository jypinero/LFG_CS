<?php


namespace App\Services;

use App\Models\Event;
use App\Models\TeamMatchup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TournamentBracketGenerator
{
    /**
     * Generate bracket for an event.
     * $type: 'single_elimination' | 'double_elimination' | 'round_robin'
     * $options: ['shuffle' => true]
     */
    public function generate(Event $event, string $type, array $options = []): \Illuminate\Support\Collection
    {
        $tournament = $event->tournament ?? $event->tournament()->first();
        $tournamentId = $tournament->id ?? $event->tournament_id;
        $tournamentType = $tournament->tournament_type ?? 'team vs team';

        // collect participants depending on tournament type
        $participants = [];

        if ($tournamentType === 'team vs team') {
            // try event->teams relation
            if (method_exists($event, 'teams')) {
                try { $participants = $event->teams()->pluck('id')->filter()->unique()->values()->all(); } catch (\Throwable $ex) {}
            }
            if (empty($participants) && class_exists(\App\Models\EventTeam::class)) {
                $participants = \App\Models\EventTeam::where('event_id', $event->id)->pluck('team_id')->filter()->unique()->values()->all();
            }
            if (empty($participants) && $tournament && class_exists(\App\Models\TournamentParticipant::class)) {
                $participants = \App\Models\TournamentParticipant::where('tournament_id', $tournament->id)
                    ->where('participant_type', 'team')
                    ->whereIn('status', ['approved','confirmed','pending'])
                    ->pluck('team_id')->filter()->unique()->values()->all();
            }

            // validate
            $candidate = array_values(array_filter($participants, fn($v) => $v !== null));
            if (count($candidate) < 2) {
                throw ValidationException::withMessages(['participants' => ['Need at least 2 teams to build a bracket']]);
            }
            $existing = Team::whereIn('id', $candidate)->pluck('id')->map(fn($v) => (int)$v)->toArray();
            $missing = array_values(array_diff($candidate, $existing));
            if (! empty($missing)) {
                throw ValidationException::withMessages(['participants' => ['Missing team IDs: ' . implode(',', $missing)]]);
            }
        } else {
            // free for all - users
            if (method_exists($event, 'participants')) {
                try { $participants = $event->participants()->pluck('user_id')->filter()->unique()->values()->all(); } catch (\Throwable $ex) {}
            }
            if (empty($participants) && class_exists(\App\Models\EventParticipant::class)) {
                $participants = \App\Models\EventParticipant::where('event_id', $event->id)->pluck('user_id')->filter()->unique()->values()->all();
            }
            if (empty($participants) && $tournament && class_exists(\App\Models\TournamentParticipant::class)) {
                $participants = \App\Models\TournamentParticipant::where('tournament_id', $tournament->id)
                    ->where('participant_type', 'individual')
                    ->whereIn('status', ['approved','confirmed','pending'])
                    ->pluck('user_id')->filter()->unique()->values()->all();
            }

            $candidate = array_values(array_filter($participants, fn($v) => $v !== null));
            if (count($candidate) < 2) {
                throw ValidationException::withMessages(['participants' => ['Need at least 2 participants to build a bracket']]);
            }
            $existing = User::whereIn('id', $candidate)->pluck('id')->map(fn($v) => (int)$v)->toArray();
            $missing = array_values(array_diff($candidate, $existing));
            if (! empty($missing)) {
                throw ValidationException::withMessages(['participants' => ['Missing user IDs: ' . implode(',', $missing)]]);
            }
        }

        if ($options['shuffle'] ?? true) {
            shuffle($participants);
        }

        if ($type === 'single_elimination') {
            return $this->generateSingleElimination($tournamentId, $event->id, $participants, $tournamentType);
        }

        if ($type === 'round_robin') {
            return $this->generateRoundRobin($tournamentId, $event->id, $participants, $tournamentType);
        }

        if ($type === 'double_elimination') {
            return $this->generateDoubleElimination($tournamentId, $event->id, $participants, $tournamentType);
        }

        throw new \InvalidArgumentException('Unknown bracket type: ' . $type);
    }

    protected function generateSingleElimination($tournamentId, $eventId, array $participants, $tournamentType = 'team vs team')
    {
        // pad to power of two
        $n = 1;
        while ($n < count($participants)) $n *= 2;
        while (count($participants) < $n) $participants[] = null;

        // store matches per round to link next_match_id
        $roundMatches = [];

        DB::transaction(function() use ($tournamentId, $eventId, $participants, $tournamentType, &$roundMatches) {
            TeamMatchup::where('tournament_id', $tournamentId)->where('event_id', $eventId)->delete();

            $round = 1;
            $matchNumber = 1;
            $roundMatches[$round] = [];
            for ($i = 0; $i < count($participants); $i += 2) {
                $p1 = $participants[$i] ?? null;
                $p2 = $participants[$i+1] ?? null;
                $status = ($p1 === null || $p2 === null) ? 'bye' : 'pending';

                $data = [
                    'tournament_id' => $tournamentId,
                    'event_id' => $eventId,
                    'round_number' => $round,
                    'match_number' => $matchNumber++,
                    'match_stage' => 'winners',
                    'team_a_id' => $tournamentType === 'team vs team' ? $p1 : null,
                    'team_b_id' => $tournamentType === 'team vs team' ? $p2 : null,
                    'status' => $status,
                ];
                if ($tournamentType !== 'team vs team') {
                    $data['meta'] = ['user_a_id' => $p1, 'user_b_id' => $p2];
                }
                $m = TeamMatchup::create($data);
                $roundMatches[$round][] = $m->id;
            }

            // create placeholder later rounds
            $matchesThisRound = intdiv(count($participants), 2);
            $round++;
            while ($matchesThisRound >= 1) {
                $roundMatches[$round] = [];
                $matchNumber = 1;
                for ($m = 0; $m < $matchesThisRound; $m++) {
                    $nm = TeamMatchup::create([
                        'tournament_id' => $tournamentId,
                        'event_id' => $eventId,
                        'round_number' => $round,
                        'match_number' => $matchNumber++,
                        'match_stage' => 'winners',
                        'team_a_id' => null,
                        'team_b_id' => null,
                        'status' => 'pending',
                    ]);
                    $roundMatches[$round][] = $nm->id;
                }
                $matchesThisRound = intdiv($matchesThisRound, 2);
                $round++;
            }

            // link next_match_id: each match in round r -> ceil(pos/2) in round r+1
            $roundNumbers = array_keys($roundMatches);
            sort($roundNumbers);
            foreach ($roundNumbers as $r) {
                $nextR = $r + 1;
                if (! isset($roundMatches[$nextR])) continue;
                foreach ($roundMatches[$r] as $pos => $matchId) {
                    $targetPos = intdiv($pos, 2); // 0-index
                    $targetId = $roundMatches[$nextR][$targetPos] ?? null;
                    if ($targetId) {
                        TeamMatchup::where('id', $matchId)->update(['next_match_id' => $targetId]);
                    }
                }
            }
        });

        return TeamMatchup::where('tournament_id', $tournamentId)
            ->where('event_id', $eventId)
            ->orderBy('match_stage')->orderBy('round_number')->orderBy('match_number')
            ->get();
    }

    protected function generateRoundRobin($tournamentId, $eventId, array $participants, $tournamentType = 'team vs team')
    {
        $n = count($participants);
        if ($n < 2) throw new \RuntimeException('Need at least 2 participants for round robin');

        if ($n % 2 === 1) { $participants[] = null; $n++; }
        $rounds = $n - 1;
        $arr = $participants;

        DB::transaction(function() use ($tournamentId, $eventId, $arr, $rounds, $n, $tournamentType) {
            TeamMatchup::where('tournament_id', $tournamentId)->where('event_id', $eventId)->delete();

            for ($r = 1; $r <= $rounds; $r++) {
                $matchNumber = 1;
                for ($i = 0; $i < $n/2; $i++) {
                    $tA = $arr[$i];
                    $tB = $arr[$n - 1 - $i];
                    $status = ($tA === null || $tB === null) ? 'bye' : 'pending';
                    $data = [
                        'tournament_id' => $tournamentId,
                        'event_id' => $eventId,
                        'round_number' => $r,
                        'match_number' => $matchNumber++,
                        'match_stage' => 'round_robin',
                        'team_a_id' => $tournamentType === 'team vs team' ? $tA : null,
                        'team_b_id' => $tournamentType === 'team vs team' ? $tB : null,
                        'status' => $status,
                    ];
                    if ($tournamentType !== 'team vs team') $data['meta'] = ['user_a_id' => $tA, 'user_b_id' => $tB];
                    TeamMatchup::create($data);
                }
                // rotate except first
                $first = array_shift($arr);
                $last = array_pop($arr);
                array_unshift($arr, $last);
                array_unshift($arr, $first);
                array_shift($arr);
            }
        });

        return TeamMatchup::where('tournament_id', $tournamentId)
            ->where('event_id', $eventId)
            ->orderBy('round_number')->orderBy('match_number')
            ->get();
    }

    protected function generateDoubleElimination($tournamentId, $eventId, array $participants, $tournamentType = 'team vs team')
    {
        // Build winners bracket like single elimination, capture structure
        $n = 1;
        while ($n < count($participants)) $n *= 2;
        while (count($participants) < $n) $participants[] = null;

        $winners = []; // winners rounds -> array of match ids
        $losers = [];  // losers rounds -> array of match ids

        DB::transaction(function() use ($tournamentId, $eventId, $participants, $tournamentType, &$winners, &$losers) {
            TeamMatchup::where('tournament_id', $tournamentId)->where('event_id', $eventId)->delete();

            // create winners round 1
            $round = 1;
            $winners[$round] = [];
            $matchNumber = 1;
            for ($i = 0; $i < count($participants); $i += 2) {
                $p1 = $participants[$i] ?? null;
                $p2 = $participants[$i+1] ?? null;
                $status = ($p1 === null || $p2 === null) ? 'bye' : 'pending';
                $data = [
                    'tournament_id' => $tournamentId,
                    'event_id' => $eventId,
                    'round_number' => $round,
                    'match_number' => $matchNumber++,
                    'match_stage' => 'winners',
                    'team_a_id' => $tournamentType === 'team vs team' ? $p1 : null,
                    'team_b_id' => $tournamentType === 'team vs team' ? $p2 : null,
                    'status' => $status,
                ];
                if ($tournamentType !== 'team vs team') $data['meta'] = ['user_a_id' => $p1, 'user_b_id' => $p2];
                $m = TeamMatchup::create($data);
                $winners[$round][] = $m->id;
            }

            // build remaining winners rounds placeholders
            $matchesThisRound = intdiv(count($participants), 2);
            $r = $round + 1;
            while ($matchesThisRound > 0) {
                $winners[$r] = [];
                $matchNumber = 1;
                for ($i = 0; $i < $matchesThisRound; $i++) {
                    $m = TeamMatchup::create([
                        'tournament_id' => $tournamentId,
                        'event_id' => $eventId,
                        'round_number' => $r,
                        'match_number' => $matchNumber++,
                        'match_stage' => 'winners',
                        'team_a_id' => null,
                        'team_b_id' => null,
                        'status' => 'pending',
                    ]);
                    $winners[$r][] = $m->id;
                }
                $matchesThisRound = intdiv($matchesThisRound, 2);
                $r++;
            }

            // link winners next_match_id (same pattern as single)
            $wRounds = array_keys($winners);
            sort($wRounds);
            foreach ($wRounds as $wr) {
                $nr = $wr + 1;
                if (! isset($winners[$nr])) continue;
                foreach ($winners[$wr] as $pos => $matchId) {
                    $targetId = $winners[$nr][intdiv($pos,2)] ?? null;
                    if ($targetId) TeamMatchup::where('id', $matchId)->update(['next_match_id' => $targetId]);
                }
            }

            // Build a simple losers bracket:
            // Losers rounds = number of winners rounds - 1
            $totalWRounds = count($winners);
            $loserRounds = max(1, $totalWRounds - 1);
            // approximate number of matches in first losers round = number of winners round1 / 2
            for ($lr = 1; $lr <= $loserRounds; $lr++) {
                $losers[$lr] = [];
                $matches = max(1, intdiv(count($winners[1]), 2) >> ($lr-1));
                // ensure at least 1
                for ($m = 1; $m <= $matches; $m++) {
                    $lm = TeamMatchup::create([
                        'tournament_id' => $tournamentId,
                        'event_id' => $eventId,
                        'round_number' => $lr,
                        'match_number' => $m,
                        'match_stage' => 'losers',
                        'team_a_id' => null,
                        'team_b_id' => null,
                        'status' => 'pending',
                    ]);
                    $losers[$lr][] = $lm->id;
                }
            }

            // Link losers next_match_id within losers bracket
            $lRounds = array_keys($losers);
            sort($lRounds);
            foreach ($lRounds as $lr) {
                $nr = $lr + 1;
                if (! isset($losers[$nr])) continue;
                foreach ($losers[$lr] as $pos => $matchId) {
                    $targetId = $losers[$nr][intdiv($pos,2)] ?? null;
                    if ($targetId) TeamMatchup::where('id', $matchId)->update(['next_match_id' => $targetId]);
                }
            }

            // Map winners-match losers into appropriate losers match:
            // Simple mapping: losers from winners round r go to losers round r (or nearest)
            foreach ($winners as $wr => $matches) {
                if (isset($losers[$wr])) {
                    foreach ($matches as $pos => $matchId) {
                        $target = $losers[$wr][intdiv($pos,2)] ?? $losers[$wr][0] ?? null;
                        if ($target) TeamMatchup::where('id', $matchId)->update(['loser_next_match_id' => $target]);
                    }
                } else {
                    // fallback: map to first losers round
                    foreach ($matches as $matchId) {
                        $fallback = $losers[1][0] ?? null;
                        if ($fallback) TeamMatchup::where('id', $matchId)->update(['loser_next_match_id' => $fallback]);
                    }
                }
            }

            // Grand final: connect last winners match and last losers match into a grand final row
            $lastWinnerRound = end($wRounds);
            $lastWinnerMatches = $winners[$lastWinnerRound] ?? [];
            $finalWinnerId = $lastWinnerMatches[0] ?? null;
            $lastLoserRound = end(array_keys($losers));
            $lastLoserMatches = $losers[$lastLoserRound] ?? [];
            $finalLoserId = $lastLoserMatches[0] ?? null;

            $grandFinal = TeamMatchup::create([
                'tournament_id' => $tournamentId,
                'event_id' => $eventId,
                'round_number' => max($lastWinnerRound, $lastLoserRound) + 1,
                'match_number' => 1,
                'match_stage' => 'grand_final',
                'team_a_id' => null,
                'team_b_id' => null,
                'status' => 'pending',
            ]);

            // winners final -> grand final
            if ($finalWinnerId) TeamMatchup::where('id', $finalWinnerId)->update(['next_match_id' => $grandFinal->id, 'loser_next_match_id' => $losers[1][0] ?? null]);
            // losers final -> grand final (winner of losers feed)
            if ($finalLoserId) TeamMatchup::where('id', $finalLoserId)->update(['next_match_id' => $grandFinal->id]);
        });

        return TeamMatchup::where('tournament_id', $tournamentId)
            ->where('event_id', $eventId)
            ->orderByRaw("FIELD(match_stage,'winners','losers','grand_final')")->orderBy('round_number')->orderBy('match_number')
            ->get();
    }
}