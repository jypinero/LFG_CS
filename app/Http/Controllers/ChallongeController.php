<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ChallongeOauthService;
use App\Models\EventGame;
use App\Models\Event; // your local event model
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
}