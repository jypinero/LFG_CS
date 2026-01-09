<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\ChallongeOauthService;
use App\Services\ChallongeService;
use App\Models\EventGame;

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
}