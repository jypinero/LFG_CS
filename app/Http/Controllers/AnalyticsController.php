<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\Standing;
use App\Models\Leaderboard;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    protected $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get tournament analytics
     * GET /api/tournaments/{tournamentId}/analytics
     */
    public function getAnalytics($tournamentId)
    {
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $analytics = $this->analyticsService->calculateAnalytics($tournament);

        return response()->json([
            'status' => 'success',
            'analytics' => $analytics,
        ]);
    }

    /**
     * Get standings for tournament
     * GET /api/tournaments/{tournamentId}/standings
     */
    public function getStandings($tournamentId)
    {
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $this->analyticsService->calculateStandings($tournament);

        $standings = Standing::where('tournament_id', $tournament->id)
            ->orderBy('rank')
            ->get()
            ->map(function($standing) {
                if ($standing->team_id) {
                    $team = $standing->team;
                    return [
                        'rank' => $standing->rank,
                        'name' => $team?->name ?? 'Unknown',
                        'wins' => $standing->wins,
                        'losses' => $standing->losses,
                        'draws' => $standing->draws,
                        'points' => $standing->points,
                        'win_rate' => $standing->win_rate,
                        'matches_played' => $standing->wins + $standing->losses + $standing->draws,
                    ];
                } else {
                    $user = $standing->user;
                    return [
                        'rank' => $standing->rank,
                        'name' => $user ? $user->first_name . ' ' . $user->last_name : 'Unknown',
                        'wins' => $standing->wins,
                        'losses' => $standing->losses,
                        'draws' => $standing->draws,
                        'points' => $standing->points,
                        'win_rate' => $standing->win_rate,
                        'matches_played' => $standing->wins + $standing->losses + $standing->draws,
                    ];
                }
            });

        return response()->json([
            'status' => 'success',
            'standings' => $standings,
            'count' => $standings->count(),
        ]);
    }

    /**
     * Get leaderboard with match history
     * GET /api/tournaments/{tournamentId}/leaderboard
     */
    public function getLeaderboard($tournamentId)
    {
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $this->analyticsService->buildLeaderboard($tournament);

        $leaderboard = Leaderboard::where('tournament_id', $tournament->id)
            ->orderBy('rank')
            ->get()
            ->map(function($lb) {
                if ($lb->team_id) {
                    $team = $lb->team;
                    return [
                        'rank' => $lb->rank,
                        'name' => $team?->name ?? 'Unknown',
                        'wins' => $lb->wins,
                        'losses' => $lb->losses,
                        'draws' => $lb->draws,
                        'points' => $lb->points,
                        'win_rate' => $lb->win_rate,
                        'matches_played' => $lb->matches_played,
                        'match_history' => $lb->match_history,
                        'stats' => $lb->stats,
                    ];
                } else {
                    $user = $lb->user;
                    return [
                        'rank' => $lb->rank,
                        'name' => $user ? $user->first_name . ' ' . $user->last_name : 'Unknown',
                        'wins' => $lb->wins,
                        'losses' => $lb->losses,
                        'draws' => $lb->draws,
                        'points' => $lb->points,
                        'win_rate' => $lb->win_rate,
                        'matches_played' => $lb->matches_played,
                        'match_history' => $lb->match_history,
                        'stats' => $lb->stats,
                    ];
                }
            });

        return response()->json([
            'status' => 'success',
            'leaderboard' => $leaderboard,
            'count' => $leaderboard->count(),
        ]);
    }
}
