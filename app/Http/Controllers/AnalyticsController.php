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
    public function getStandings(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $this->analyticsService->calculateStandings($tournament);

        $query = Standing::where('tournament_id', $tournament->id);

        // Sorting
        $sortBy = $request->input('sort_by', 'rank');
        $sortOrder = $request->input('sort_order', 'asc');

        $allowedSortFields = ['rank', 'wins', 'losses', 'points', 'win_rate', 'matches_played'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('rank', 'asc');
        }

        $standings = $query->get()
            ->map(function($standing) use ($tournament) {
                if ($standing->team_id) {
                    $team = $standing->team;
                    
                    // Get last 5 matches for form
                    $matchups = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
                        ->where(function($q) use ($standing) {
                            $q->where('team_a_id', $standing->team_id)
                              ->orWhere('team_b_id', $standing->team_id);
                        })
                        ->where('status', 'completed')
                        ->orderBy('completed_at', 'desc')
                        ->limit(5)
                        ->get();

                    $form = [];
                    $last5Matches = [];
                    
                    foreach ($matchups as $matchup) {
                        $isTeamA = $matchup->team_a_id === $standing->team_id;
                        $opponentId = $isTeamA ? $matchup->team_b_id : $matchup->team_a_id;
                        $opponent = \App\Models\Team::find($opponentId);
                        
                        $result = 'D';
                        if ($matchup->winner_team_id === $standing->team_id) {
                            $result = 'W';
                        } elseif ($matchup->winner_team_id === $opponentId) {
                            $result = 'L';
                        }
                        $form[] = $result;
                        
                        $teamScore = $isTeamA ? $matchup->team_a_score : $matchup->team_b_score;
                        $opponentScore = $isTeamA ? $matchup->team_b_score : $matchup->team_a_score;
                        
                        $last5Matches[] = [
                            'match_id' => $matchup->event_id,
                            'opponent' => $opponent->name ?? null,
                            'result' => strtolower($result),
                            'score' => ($teamScore ?? 0) . '-' . ($opponentScore ?? 0),
                        ];
                    }
                    
                    // Calculate totals
                    $allMatchups = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
                        ->where(function($q) use ($standing) {
                            $q->where('team_a_id', $standing->team_id)
                              ->orWhere('team_b_id', $standing->team_id);
                        })
                        ->where('status', 'completed')
                        ->get();
                    
                    $totalPointsScored = 0;
                    $totalPointsAgainst = 0;
                    foreach ($allMatchups as $matchup) {
                        $isTeamA = $matchup->team_a_id === $standing->team_id;
                        $totalPointsScored += $isTeamA ? ($matchup->team_a_score ?? 0) : ($matchup->team_b_score ?? 0);
                        $totalPointsAgainst += $isTeamA ? ($matchup->team_b_score ?? 0) : ($matchup->team_a_score ?? 0);
                    }

                    return [
                        'rank' => $standing->rank,
                        'team_id' => $standing->team_id,
                        'team_name' => $team?->name ?? 'Unknown',
                        'team_logo' => $team?->logo ?? null,
                        'wins' => $standing->wins,
                        'losses' => $standing->losses,
                        'draws' => $standing->draws,
                        'points' => $standing->points,
                        'win_rate' => $standing->win_rate,
                        'matches_played' => $standing->wins + $standing->losses + $standing->draws,
                        'total_points_scored' => $totalPointsScored,
                        'total_points_against' => $totalPointsAgainst,
                        'point_differential' => $totalPointsScored - $totalPointsAgainst,
                        'form' => array_reverse($form), // Most recent first
                        'last_5_matches' => array_reverse($last5Matches),
                    ];
                } else {
                    $user = $standing->user;
                    return [
                        'rank' => $standing->rank,
                        'user_id' => $standing->user_id,
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
            'last_updated' => now()->toIso8601String(),
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
