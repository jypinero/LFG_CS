<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ChallongeOauthService;
use App\Services\ChallongeService;
use App\Models\EventGame;
use App\Models\Event;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use Illuminate\Support\Facades\Log;

class ChallongeController extends Controller
{
    public function handleWebhook(Request $request, ChallongeService $challonge)
    {
        $payload = $request->all();
        Log::info('Challonge webhook received', ['payload' => $payload]);

        if (! $challonge->webhookValid($payload)) {
            Log::warning('Challonge webhook ignored - invalid payload', ['payload' => $payload]);
            return response()->json(['message'=>'ignored'], 204);
        }

        $match = $payload['match'] ?? null;
        if (! $match) {
            Log::warning('Challonge webhook missing match', ['payload' => $payload]);
            return response()->json(['message'=>'no match'], 400);
        }

        $challongeMatchId = $match['id'] ?? null;
        try {
            if ($challongeMatchId) {
                $local = EventGame::where('challonge_match_id', (string) $challongeMatchId)->first();
                if ($local) {
                    $scores = $match['scores_csv'] ?? null;
                    $scoreA = $scoreB = null;
                    if ($scores) {
                        $parts = explode('-', $scores);
                        $scoreA = $parts[0] ?? null;
                        $scoreB = $parts[1] ?? null;
                    }
                    $local->update([
                        'score_a' => $scoreA,
                        'score_b' => $scoreB,
                        'status' => ($match['state'] ?? '') === 'complete' ? 'completed' : $local->status,
                        'winner_team_id' => $match['winner_id'] ?? $local->winner_team_id,
                    ]);
                    Log::info('Challonge webhook updated local match', ['local_id' => $local->id, 'challonge_id' => $challongeMatchId]);
                } else {
                    Log::warning('Challonge webhook: no local match found for challonge id', ['challonge_id' => $challongeMatchId]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Challonge webhook processing error', ['err' => $e->getMessage(), 'payload' => $payload]);
            return response()->json(['message' => 'error'], 500);
        }

        return response()->json(['message'=>'ok']);
    }

    // list tournaments for the authenticated app user (uses saved access token)
    public function listTournaments(Request $request, ChallongeOauthService $oauthService)
    {
        $user = $request->user();
        $res = $oauthService->requestWithUser($user, 'get', 'https://api.challonge.com/v1/tournaments.json');

        if ($res->failed()) {
            Log::error('Challonge list tournaments failed', ['status' => $res->status(), 'body' => $res->body()]);
            return response()->json(['error' => 'failed'], $res->status());
        }

        return response()->json($res->json());
    }

    // create a basic tournament on challonge for the user
    public function createTournament(Request $request, ChallongeOauthService $oauthService)
    {
        $request->validate([
            'tournament[name]' => 'required|string',
            'tournament[url]' => 'nullable|string',
        ]);
        $user = $request->user();

        $payload = ['tournament' => $request->input('tournament')];
        $res = $oauthService->requestWithUser($user, 'post', 'https://api.challonge.com/v1/tournaments.json', [
            'form_params' => $payload,
        ]);

        if ($res->failed()) {
            Log::error('Challonge create tournament failed', ['status' => $res->status(), 'body' => $res->body()]);
            return response()->json(['error' => 'failed'], $res->status());
        }

        return response()->json($res->json(), 201);
    }

    public function startTournament(Request $request, $id, ChallongeOauthService $oauth)
    {
        $user = $request->user();
        $res = $oauth->startTournament($user, $id);
        if ($res->failed()) {
            Log::error('startTournament failed', ['status'=>$res->status(),'body'=>$res->body()]);
            return response()->json(['error'=>'failed to start'], $res->status());
        }
        return response()->json(['status'=>'started']);
    }

    public function pushEventGames(Request $request, $id, ChallongeOauthService $oauth)
    {
        // $id is local event id
        $event = Event::with(['participants','games'])->findOrFail($id);
        if (empty($event->challonge_tournament_id)) {
            return response()->json(['error'=>'event not pushed to challonge'], 400);
        }
        $ok = $oauth->pushEventGames($request->user(), $event);
        return response()->json(['status' => $ok ? 'ok' : 'failed']);
    }

    public function syncScoreToChallonge(Request $request, $match_id, ChallongeOauthService $oauth)
    {
        $request->validate(['tournament_id'=>'required','scores_csv'=>'required']);
        $user = $request->user();
        $tournamentId = $request->input('tournament_id');
        $scores = $request->input('scores_csv');

        $res = $oauth->updateMatchScore($user, $tournamentId, $match_id, $scores);
        if ($res->failed()) {
            Log::error('syncScoreToChallonge failed', ['status'=>$res->status(),'body'=>$res->body()]);
            return response()->json(['error'=>'failed'], $res->status());
        }
        return response()->json(['status'=>'ok','body'=>$res->json()]);
    }

    public function fetchBracket(Request $request, $id, ChallongeOauthService $oauth)
    {
        $user = $request->user();
        $data = $oauth->fetchBracket($user, $id);
        return response()->json($data);
    }

    /**
     * Check if tournament is synced with Challonge
     * GET /api/tournaments/{tournamentId}/challonge/status
     */
    public function getTournamentStatus(Request $request, $tournamentId, ChallongeService $challonge)
    {
        $user = $request->user();
        $tournament = Tournament::findOrFail($tournamentId);

        $isSynced = !empty($tournament->external_tournament_url);
        $challongeUrl = $tournament->external_tournament_url;

        $status = [
            'is_synced' => $isSynced,
            'challonge_url' => $challongeUrl,
            'challonge_embed_url' => $isSynced ? $challonge->getTournamentEmbedUrl($challongeUrl) : null,
        ];

        // If synced, try to fetch current status from Challonge
        if ($isSynced) {
            $challongeTournament = $challonge->getTournament($challongeUrl, $user);
            if ($challongeTournament) {
                $status['challonge_state'] = $challongeTournament['state'] ?? null;
                $status['challonge_progress_meter'] = $challongeTournament['progress_meter'] ?? null;
            }
        }

        return response()->json($status);
    }

    /**
     * Get Challonge tournament data for local tournament
     * GET /api/tournaments/{tournamentId}/challonge/tournament
     */
    public function getChallongeTournament(Request $request, $tournamentId, ChallongeService $challonge)
    {
        $user = $request->user();
        $tournament = Tournament::findOrFail($tournamentId);

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge',
                'is_synced' => false
            ], 404);
        }

        $challongeData = $challonge->getTournament($tournament->external_tournament_url, $user);

        if (!$challongeData) {
            return response()->json([
                'message' => 'Failed to fetch Challonge tournament data'
            ], 500);
        }

        return response()->json([
            'local_tournament_id' => $tournament->id,
            'local_tournament_name' => $tournament->name,
            'challonge_url' => $tournament->external_tournament_url,
            'challonge_data' => $challongeData,
        ]);
    }

    /**
     * Get Challonge bracket data
     * GET /api/tournaments/{tournamentId}/challonge/bracket
     */
    public function getChallongeBracket(Request $request, $tournamentId, ChallongeService $challonge)
    {
        $user = $request->user();
        $tournament = Tournament::findOrFail($tournamentId);

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge',
                'is_synced' => false
            ], 404);
        }

        $bracketData = $challonge->getBracket($tournament->external_tournament_url, $user);

        if (!$bracketData) {
            return response()->json([
                'message' => 'Failed to fetch Challonge bracket data'
            ], 500);
        }

        return response()->json([
            'tournament_id' => $tournament->id,
            'tournament_name' => $tournament->name,
            'challonge_url' => $tournament->external_tournament_url,
            'bracket' => $bracketData,
        ]);
    }

    /**
     * Get Challonge matches
     * GET /api/tournaments/{tournamentId}/challonge/matches
     */
    public function getChallongeMatches(Request $request, $tournamentId, ChallongeService $challonge)
    {
        $user = $request->user();
        $tournament = Tournament::findOrFail($tournamentId);

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge',
                'is_synced' => false
            ], 404);
        }

        $matches = $challonge->getMatches($tournament->external_tournament_url, $user);

        if ($matches === null) {
            return response()->json([
                'message' => 'Failed to fetch Challonge matches'
            ], 500);
        }

        return response()->json([
            'tournament_id' => $tournament->id,
            'tournament_name' => $tournament->name,
            'challonge_url' => $tournament->external_tournament_url,
            'matches' => $matches,
        ]);
    }

    /**
     * Get Challonge embed URL/widget code
     * GET /api/tournaments/{tournamentId}/challonge/embed
     */
    public function getChallongeEmbed(Request $request, $tournamentId, ChallongeService $challonge)
    {
        $tournament = Tournament::findOrFail($tournamentId);

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge',
                'is_synced' => false
            ], 404);
        }

        $embedUrl = $challonge->getTournamentEmbedUrl($tournament->external_tournament_url);

        return response()->json([
            'tournament_id' => $tournament->id,
            'challonge_url' => $tournament->external_tournament_url,
            'embed_url' => $embedUrl,
            'embed_code' => '<iframe src="' . $embedUrl . '" width="100%" height="500" frameborder="0" scrolling="auto" allowtransparency="true"></iframe>',
        ]);
    }

    /**
     * Refresh Challonge data (read-only pull)
     * POST /api/tournaments/{tournamentId}/challonge/refresh
     */
    public function refreshChallongeData(Request $request, $tournamentId, ChallongeService $challonge)
    {
        $user = $request->user();
        $tournament = Tournament::findOrFail($tournamentId);

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge',
                'is_synced' => false
            ], 404);
        }

        $bracketData = $challonge->getBracket($tournament->external_tournament_url, $user);

        if (!$bracketData) {
            return response()->json([
                'message' => 'Failed to refresh Challonge data'
            ], 500);
        }

        return response()->json([
            'message' => 'Challonge data refreshed successfully',
            'tournament_id' => $tournament->id,
            'data' => $bracketData,
        ]);
    }

    /**
     * Check user's Challonge connection status
     * GET /api/challonge/connection-status
     */
    public function checkChallongeConnection(Request $request, ChallongeService $challonge)
    {
        $user = $request->user();
        $isConnected = $challonge->checkConnection($user);

        return response()->json([
            'is_connected' => $isConnected,
            'has_oauth_token' => !empty($user->challonge_access_token),
            'has_api_key' => !empty(config('services.challonge.api_key')),
        ]);
    }

    /**
     * Update tournament details on Challonge
     * PUT /api/tournaments/{tournamentId}/challonge/tournament
     */
    public function updateChallongeTournament(Request $request, $tournamentId, ChallongeService $challonge)
    {
        $user = $request->user();
        $tournament = Tournament::findOrFail($tournamentId);

        // Check organizer permission
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer && $tournament->created_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge. Please push tournament first.',
                'is_synced' => false
            ], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string',
            'description' => 'sometimes|string|nullable',
            'tournament_type' => 'sometimes|string',
            'private' => 'sometimes|boolean',
        ]);

        $result = $challonge->updateTournament($tournament->external_tournament_url, $data, $user);

        if (!$result) {
            return response()->json([
                'message' => 'Failed to update Challonge tournament'
            ], 500);
        }

        return response()->json([
            'message' => 'Tournament updated on Challonge successfully',
            'challonge_data' => $result,
        ]);
    }

    /**
     * Sync participants from local to Challonge
     * POST /api/tournaments/{tournamentId}/challonge/sync-participants
     */
    public function syncParticipants(Request $request, $tournamentId, ChallongeService $challonge)
    {
        $user = $request->user();
        $tournament = Tournament::findOrFail($tournamentId);

        // Check organizer permission
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer && $tournament->created_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge. Please push tournament first.',
                'is_synced' => false
            ], 404);
        }

        // Get approved participants
        $participants = $tournament->participants()
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

        if (empty($challongeParticipants)) {
            return response()->json([
                'message' => 'No approved participants to sync'
            ], 422);
        }

        $result = $challonge->addParticipants($tournament->external_tournament_url, $challongeParticipants);

        if (!$result) {
            return response()->json([
                'message' => 'Failed to sync participants to Challonge'
            ], 500);
        }

        return response()->json([
            'message' => 'Participants synced to Challonge successfully',
            'participants_added' => count($challongeParticipants),
            'challonge_data' => $result,
        ]);
    }

    /**
     * Push match score to Challonge (from EventGame)
     * POST /api/tournaments/event-game/{gameId}/challonge/sync-score
     */
    public function syncMatchScore(Request $request, $gameId, ChallongeService $challonge)
    {
        $user = $request->user();
        $game = EventGame::with(['event.tournament'])->findOrFail($gameId);
        $tournament = $game->event->tournament;

        // Check organizer permission
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer && $tournament->created_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge',
                'is_synced' => false
            ], 404);
        }

        if (empty($game->challonge_match_id)) {
            return response()->json([
                'message' => 'Game not synced with Challonge. Please push event games first.',
            ], 404);
        }

        if ($game->score_a === null || $game->score_b === null) {
            return response()->json([
                'message' => 'Game scores not set',
            ], 422);
        }

        $result = $challonge->syncMatchScore(
            $tournament->external_tournament_url,
            $game->challonge_match_id,
            $game->score_a,
            $game->score_b,
            $user
        );

        if (!$result) {
            return response()->json([
                'message' => 'Failed to sync score to Challonge'
            ], 500);
        }

        return response()->json([
            'message' => 'Score synced to Challonge successfully',
            'game_id' => $game->id,
            'challonge_match' => $result,
        ]);
    }

    /**
     * Push all event games to Challonge
     * POST /api/tournaments/events/{eventId}/challonge/push-games
     */
    public function pushEventGamesToChallonge(Request $request, $eventId, ChallongeService $challonge)
    {
        $user = $request->user();
        $event = Event::with('tournament')->findOrFail($eventId);
        $tournament = $event->tournament;

        // Check organizer permission
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer && $tournament->created_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge. Please push tournament first.',
                'is_synced' => false
            ], 404);
        }

        $result = $challonge->pushEventToChallonge($event, $tournament->external_tournament_url);

        if (!$result) {
            return response()->json([
                'message' => 'Failed to push event games to Challonge'
            ], 500);
        }

        $gamesCount = EventGame::where('event_id', $event->id)->count();

        return response()->json([
            'message' => 'Event games pushed to Challonge successfully',
            'event_id' => $event->id,
            'games_pushed' => $gamesCount,
        ]);
    }

    /**
     * Sync bracket structure to Challonge
     * POST /api/tournaments/events/{eventId}/challonge/sync-bracket
     */
    public function syncBracket(Request $request, $eventId, ChallongeService $challonge)
    {
        $user = $request->user();
        $event = Event::with('tournament')->findOrFail($eventId);
        $tournament = $event->tournament;

        // Check organizer permission
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer && $tournament->created_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (empty($tournament->external_tournament_url)) {
            return response()->json([
                'message' => 'Tournament not synced with Challonge. Please push tournament first.',
                'is_synced' => false
            ], 404);
        }

        $result = $challonge->syncBracket($event, $tournament->external_tournament_url, $user);

        if (!$result) {
            return response()->json([
                'message' => 'Failed to sync bracket to Challonge'
            ], 500);
        }

        return response()->json([
            'message' => 'Bracket synced to Challonge successfully',
            'event_id' => $event->id,
            'tournament_id' => $tournament->id,
        ]);
    }
}