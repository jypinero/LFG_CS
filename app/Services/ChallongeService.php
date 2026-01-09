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

    public function updateMatch(string $challongeTournamentUrl, $matchId, array $data, $user = null)
    {
        try {
            $client = $this->authForUser($user);
            $res = $client->put("{$this->base}/tournaments/{$challongeTournamentUrl}/matches/{$matchId}.json", ['match' => $data]);
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

    /**
     * Get tournament details from Challonge
     */
    public function getTournament(string $challongeUrl, $user = null)
    {
        try {
            $client = $this->authForUser($user);
            $res = $client->get("{$this->base}/tournaments/{$challongeUrl}.json", [
                'include_matches' => 1,
                'include_participants' => 1
            ]);

            if (! $res->successful()) {
                Log::error('Challonge getTournament failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            return $res->json('tournament') ?? $res->json();
        } catch (\Throwable $e) {
            Log::error('Challonge getTournament exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get participants from Challonge tournament
     */
    public function getParticipants(string $challongeUrl, $user = null)
    {
        try {
            $client = $this->authForUser($user);
            $res = $client->get("{$this->base}/tournaments/{$challongeUrl}/participants.json");

            if (! $res->successful()) {
                Log::error('Challonge getParticipants failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            return $res->json('participants') ?? $res->json();
        } catch (\Throwable $e) {
            Log::error('Challonge getParticipants exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get matches from Challonge tournament
     */
    public function getMatches(string $challongeUrl, $user = null)
    {
        try {
            $client = $this->authForUser($user);
            $res = $client->get("{$this->base}/tournaments/{$challongeUrl}/matches.json");

            if (! $res->successful()) {
                Log::error('Challonge getMatches failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            return $res->json('matches') ?? $res->json();
        } catch (\Throwable $e) {
            Log::error('Challonge getMatches exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get bracket structure from Challonge tournament
     */
    public function getBracket(string $challongeUrl, $user = null)
    {
        try {
            $client = $this->authForUser($user);
            $tournament = $this->getTournament($challongeUrl, $user);
            $participants = $this->getParticipants($challongeUrl, $user);
            $matches = $this->getMatches($challongeUrl, $user);

            if ($tournament === null) {
                return null;
            }

            return [
                'tournament' => $tournament,
                'participants' => $participants ?? [],
                'matches' => $matches ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Challonge getBracket exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get Challonge tournament embed URL
     */
    public function getTournamentEmbedUrl(string $challongeUrl)
    {
        return "https://challonge.com/{$challongeUrl}/module";
    }

    /**
     * Check if user has valid Challonge connection
     */
    public function checkConnection($user = null)
    {
        if (! $user) {
            return false;
        }

        // Check if user has OAuth token
        if (!empty($user->challonge_access_token)) {
            // Optionally verify token is valid by making a test request
            try {
                $client = $this->authForUser($user);
                $res = $client->get("{$this->base}/tournaments.json");
                return $res->successful();
            } catch (\Throwable $e) {
                return false;
            }
        }

        // Fallback to API key check
        return !empty(config('services.challonge.api_key'));
    }

    /**
     * Update tournament details on Challonge
     */
    public function updateTournament(string $challongeUrl, array $data, $user = null)
    {
        try {
            $client = $this->authForUser($user);
            $res = $client->put("{$this->base}/tournaments/{$challongeUrl}.json", ['tournament' => $data]);

            if (! $res->successful()) {
                Log::error('Challonge updateTournament failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            return $res->json('tournament') ?? $res->json();
        } catch (\Throwable $e) {
            Log::error('Challonge updateTournament exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Update participant info on Challonge
     */
    public function updateParticipant(string $challongeUrl, $participantId, array $data, $user = null)
    {
        try {
            $client = $this->authForUser($user);
            $res = $client->put("{$this->base}/tournaments/{$challongeUrl}/participants/{$participantId}.json", ['participant' => $data]);

            if (! $res->successful()) {
                Log::error('Challonge updateParticipant failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            return $res->json('participant') ?? $res->json();
        } catch (\Throwable $e) {
            Log::error('Challonge updateParticipant exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Add single participant to Challonge tournament
     */
    public function addParticipant(string $challongeUrl, array $participant, $user = null)
    {
        try {
            $client = $this->authForUser($user);
            $res = $client->post("{$this->base}/tournaments/{$challongeUrl}/participants.json", ['participant' => $participant]);

            if (! $res->successful()) {
                Log::error('Challonge addParticipant failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }
            return $res->json('participant') ?? $res->json();
        } catch (\Throwable $e) {
            Log::error('Challonge addParticipant exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Sync match score to Challonge
     */
    public function syncMatchScore(string $challongeUrl, $matchId, $scoreA, $scoreB, $user = null)
    {
        $scoresCsv = "{$scoreA}-{$scoreB}";
        return $this->updateMatch($challongeUrl, $matchId, ['scores_csv' => $scoresCsv], $user);
    }

    /**
     * Sync event games to Challonge
     */
    public function syncEventGames(Event $event, string $challongeUrl, $user = null)
    {
        return $this->pushEventToChallonge($event, $challongeUrl);
    }

    /**
     * Sync bracket structure to Challonge
     * This pushes all event games and updates bracket structure
     */
    public function syncBracket(Event $event, string $challongeUrl, $user = null)
    {
        // First ensure all participants are synced
        $participants = EventParticipant::where('event_id', $event->id)
            ->where('status', 'approved')
            ->with(['team', 'user'])
            ->get();

        $challongeParticipants = [];
        foreach ($participants as $p) {
            $name = $p->team_id 
                ? ($p->team?->name ?? 'Team ' . $p->team_id)
                : ($p->user?->username ?? 'User ' . $p->user_id);
            
            $challongeParticipants[] = [
                'name' => $name,
                'local_type' => $p->team_id ? 'team' : 'user',
                'local_id' => $p->team_id ?? $p->user_id,
            ];
        }

        if (!empty($challongeParticipants)) {
            $this->addParticipants($challongeUrl, $challongeParticipants);
        }

        // Then push event games
        return $this->pushEventToChallonge($event, $challongeUrl);
    }
}