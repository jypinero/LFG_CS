<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Tournament;
use App\Models\Event;
use App\Models\EventGame;
use App\Models\Team;
use App\Models\EventParticipant;

class ChallongeService
{
    protected $base = 'https://api.challonge.com/v1';

    protected function auth()
    {
        return Http::withBasicAuth(config('services.challonge.username'), config('services.challonge.api_key'))
                   ->acceptJson()
                   ->timeout(15);
    }

    // new helper - use OAuth bearer token if available
    public function authForUser($user = null)
    {
        if ($user && !empty($user->challonge_access_token)) {
            return Http::withToken($user->challonge_access_token)->acceptJson()->timeout(15);
        }
        return $this->auth();
    }

    public function createTournament(Tournament $tournament, array $opts = [])
    {
        $payload = array_merge([
            'name' => $tournament->name,
            'url' => 't'.$tournament->id.'-'.\Str::slug($tournament->name, '-'),
            'private' => false,
            'tournament_type' => 'single elimination'
        ], $opts);

        try {
            $res = $this->auth()->post("{$this->base}/tournaments.json", ['tournament' => $payload]);
            if (! $res->successful()) {
                Log::error('Challonge createTournament failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            return $res->json('tournament') ?? $res->json();
        } catch (\Throwable $e) {
            Log::error('Challonge createTournament exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Add participants and persist mapping back to local models.
     * participants: [
     *   ['name'=>'Team A','local_type'=>'team','local_id'=>123],
     *   ['name'=>'User X','local_type'=>'user','local_id'=>45],
     *   ['name'=>'participant','local_type'=>'event_participant','local_id'=>678],
     * ]
     */
    public function addParticipants(string $challongeTournamentUrl, array $participants)
    {
        // Build payload and include misc json so we can identify participants in response
        $payloadParticipants = [];
        foreach ($participants as $p) {
            $misc = json_encode(['local_type' => $p['local_type'] ?? null, 'local_id' => $p['local_id'] ?? null]);
            $payloadParticipants[] = ['name' => $p['name'], 'misc' => $misc];
        }

        try {
            $res = $this->auth()->post("{$this->base}/tournaments/{$challongeTournamentUrl}/participants/bulk_add.json", [
                'participants' => $payloadParticipants
            ]);

            if (! $res->successful()) {
                Log::error('Challonge bulk_add failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }

            $body = $res->json();
            // Challonge may return participants under different keys depending on endpoint version
            $returned = $body['participants'] ?? $body;

            // Persist mapping using misc payload returned by Challonge
            foreach ($returned as $rp) {
                $participant = $rp['participant'] ?? $rp;
                $misc = $participant['misc'] ?? null;
                $chalId = $participant['id'] ?? null;

                if (! $misc || ! $chalId) continue;

                $meta = json_decode($misc, true);
                if (! is_array($meta)) continue;

                $localType = $meta['local_type'] ?? null;
                $localId = $meta['local_id'] ?? null;

                try {
                    if ($localType === 'team') {
                        Team::where('id', $localId)->update(['challonge_participant_id' => (string) $chalId]);
                    } elseif ($localType === 'event_participant') {
                        EventParticipant::where('id', $localId)->update(['challonge_participant_id' => (string) $chalId]);
                    } elseif ($localType === 'user') {
                        // optionally persist on users table if desired
                        \App\Models\User::where('id', $localId)->update(['challonge_participant_id' => (string) $chalId]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to persist challonge participant mapping', ['local_type' => $localType, 'local_id' => $localId, 'err' => $e->getMessage()]);
                }
            }

            return $returned;
        } catch (\Throwable $e) {
            Log::error('Challonge addParticipants exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    public function createMatch(string $challongeTournamentUrl, array $match)
    {
        try {
            $res = $this->auth()->post("{$this->base}/tournaments/{$challongeTournamentUrl}/matches.json", ['match' => $match]);
            if (! $res->successful()) {
                Log::error('Challonge createMatch failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            return $res->json('match') ?? $res->json();
        } catch (\Throwable $e) {
            Log::error('Challonge createMatch exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    public function updateMatch(string $challongeTournamentUrl, $matchId, array $data)
    {
        try {
            $res = $this->auth()->put("{$this->base}/tournaments/{$challongeTournamentUrl}/matches/{$matchId}.json", ['match' => $data]);
            if (! $res->successful()) {
                Log::error('Challonge updateMatch failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            return $res->json('match') ?? $res->json();
        } catch (\Throwable $e) {
            Log::error('Challonge updateMatch exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    public function webhookValid(array $payload): bool
    {
        return isset($payload['match']) && isset($payload['tournament']);
    }

    /**
     * Push local EventGames to challonge using previously stored challonge participant ids on teams/event_participants.
     */
    public function pushEventToChallonge(Event $event, string $challongeTournamentUrl)
    {
        $games = EventGame::where('event_id', $event->id)->orderBy('round_number')->orderBy('match_number')->get();
        foreach ($games as $g) {
            $match = [
                'player1_id' => $g->team_a_challonge_id ?? $this->resolveChallongeParticipantIdFor($g->team_a_id),
                'player2_id' => $g->team_b_challonge_id ?? $this->resolveChallongeParticipantIdFor($g->team_b_id),
                'round' => $g->round_number,
            ];
            $res = $this->createMatch($challongeTournamentUrl, $match);
            if ($res && isset($res['id'])) {
                $g->update([
                    'challonge_match_id' => (string) $res['id'],
                    'challonge_match_url' => $res['match_url'] ?? null,
                ]);
            }
        }
        return true;
    }

    protected function resolveChallongeParticipantIdFor($localTeamId)
    {
        if (! $localTeamId) return null;
        $team = Team::find($localTeamId);
        return $team?->challonge_participant_id;
    }
}