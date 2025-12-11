<?php


namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentAnalytics;
use App\Models\TeamMatchup;
use App\Models\Standing;
use App\Models\Leaderboard;
use App\Models\TournamentParticipant;
use App\Models\Team;
use App\Models\User;

class AnalyticsService
{
    /**
     * Calculate and return tournament analytics
     */
    public function calculateAnalytics(Tournament $tournament)
    {
        $matchups = TeamMatchup::where('tournament_id', $tournament->id)->get();
        $totalGames = $matchups->count();
        $completedGames = $matchups->where('status', 'completed')->count();
        
        $participants = TournamentParticipant::where('tournament_id', $tournament->id)
            ->whereIn('status', ['approved', 'confirmed'])
            ->count();

        $teams = Team::whereIn('id', function($query) use ($tournament) {
            $query->select('team_id')
                ->from('tournament_participants')
                ->where('tournament_id', $tournament->id)
                ->where('participant_type', 'team')
                ->distinct();
        })->count();

        $noShows = $matchups->filter(function($m) {
            return $m->status === 'no_show';
        })->count();

        $analytics = TournamentAnalytics::updateOrCreate(
            ['tournament_id' => $tournament->id],
            [
                'total_participants' => $participants,
                'total_teams' => $teams,
                'total_games' => $totalGames,
                'completed_games' => $completedGames,
                'no_shows' => $noShows,
            ]
        );

        return $analytics;
    }

    /**
     * Calculate and update standings
     */
    public function calculateStandings(Tournament $tournament)
    {
        Standing::where('tournament_id', $tournament->id)->delete();

        $matchups = TeamMatchup::where('tournament_id', $tournament->id)
            ->where('status', 'completed')
            ->get();

        if ($tournament->tournament_type === 'team vs team') {
            $teams = [];
            foreach ($matchups as $matchup) {
                if (!isset($teams[$matchup->team_a_id])) {
                    $teams[$matchup->team_a_id] = ['wins' => 0, 'losses' => 0, 'draws' => 0, 'points' => 0];
                }
                if (!isset($teams[$matchup->team_b_id])) {
                    $teams[$matchup->team_b_id] = ['wins' => 0, 'losses' => 0, 'draws' => 0, 'points' => 0];
                }

                if ($matchup->winner_team_id === $matchup->team_a_id) {
                    $teams[$matchup->team_a_id]['wins']++;
                    $teams[$matchup->team_a_id]['points'] += 3;
                    $teams[$matchup->team_b_id]['losses']++;
                } elseif ($matchup->winner_team_id === $matchup->team_b_id) {
                    $teams[$matchup->team_b_id]['wins']++;
                    $teams[$matchup->team_b_id]['points'] += 3;
                    $teams[$matchup->team_a_id]['losses']++;
                } else {
                    $teams[$matchup->team_a_id]['draws']++;
                    $teams[$matchup->team_a_id]['points'] += 1;
                    $teams[$matchup->team_b_id]['draws']++;
                    $teams[$matchup->team_b_id]['points'] += 1;
                }
            }

            uasort($teams, function($a, $b) {
                if ($a['points'] === $b['points']) {
                    return $b['wins'] - $a['wins'];
                }
                return $b['points'] - $a['points'];
            });

            $rank = 1;
            foreach ($teams as $teamId => $stats) {
                $totalMatches = $stats['wins'] + $stats['losses'] + $stats['draws'];
                $winRate = $totalMatches > 0 ? ($stats['wins'] / $totalMatches) * 100 : 0;

                Standing::create([
                    'tournament_id' => $tournament->id,
                    'team_id' => $teamId,
                    'wins' => $stats['wins'],
                    'losses' => $stats['losses'],
                    'draws' => $stats['draws'],
                    'points' => $stats['points'],
                    'win_rate' => $winRate,
                    'rank' => $rank++,
                ]);
            }
        } else {
            $users = [];
            foreach ($matchups as $matchup) {
                $userA = $matchup->meta['user_a_id'] ?? null;
                $userB = $matchup->meta['user_b_id'] ?? null;

                if ($userA && !isset($users[$userA])) {
                    $users[$userA] = ['wins' => 0, 'losses' => 0, 'draws' => 0, 'points' => 0];
                }
                if ($userB && !isset($users[$userB])) {
                    $users[$userB] = ['wins' => 0, 'losses' => 0, 'draws' => 0, 'points' => 0];
                }

                if ($matchup->winner_team_id === $userA) {
                    $users[$userA]['wins']++;
                    $users[$userA]['points'] += 3;
                    if ($userB) $users[$userB]['losses']++;
                } elseif ($matchup->winner_team_id === $userB) {
                    $users[$userB]['wins']++;
                    $users[$userB]['points'] += 3;
                    if ($userA) $users[$userA]['losses']++;
                } else {
                    if ($userA) {
                        $users[$userA]['draws']++;
                        $users[$userA]['points'] += 1;
                    }
                    if ($userB) {
                        $users[$userB]['draws']++;
                        $users[$userB]['points'] += 1;
                    }
                }
            }

            uasort($users, function($a, $b) {
                if ($a['points'] === $b['points']) {
                    return $b['wins'] - $a['wins'];
                }
                return $b['points'] - $a['points'];
            });

            $rank = 1;
            foreach ($users as $userId => $stats) {
                $totalMatches = $stats['wins'] + $stats['losses'] + $stats['draws'];
                $winRate = $totalMatches > 0 ? ($stats['wins'] / $totalMatches) * 100 : 0;

                Standing::create([
                    'tournament_id' => $tournament->id,
                    'user_id' => $userId,
                    'wins' => $stats['wins'],
                    'losses' => $stats['losses'],
                    'draws' => $stats['draws'],
                    'points' => $stats['points'],
                    'win_rate' => $winRate,
                    'rank' => $rank++,
                ]);
            }
        }
    }

    /**
     * Build detailed leaderboard with match history
     */
    public function buildLeaderboard(Tournament $tournament)
    {
        Leaderboard::where('tournament_id', $tournament->id)->delete();

        $standings = Standing::where('tournament_id', $tournament->id)
            ->orderBy('rank')
            ->get();

        foreach ($standings as $standing) {
            $matchHistory = [];

            if ($standing->team_id) {
                $matchups = TeamMatchup::where('tournament_id', $tournament->id)
                    ->where(function($q) use ($standing) {
                        $q->where('team_a_id', $standing->team_id)
                          ->orWhere('team_b_id', $standing->team_id);
                    })
                    ->where('status', 'completed')
                    ->orderByDesc('completed_at')
                    ->get();

                foreach ($matchups as $m) {
                    $opponent = $m->team_a_id === $standing->team_id ? $m->team_b_id : $m->team_a_id;
                    $opponentTeam = Team::find($opponent);
                    $result = 'loss';
                    if ($m->winner_team_id === $standing->team_id) {
                        $result = 'win';
                    } elseif ($m->winner_team_id === null) {
                        $result = 'draw';
                    }

                    $matchHistory[] = [
                        'match_id' => $m->id,
                        'opponent' => $opponentTeam?->name ?? 'Unknown',
                        'team_a_score' => $m->team_a_score,
                        'team_b_score' => $m->team_b_score,
                        'result' => $result,
                        'date' => $m->completed_at,
                    ];
                }
            } else {
                $matchups = TeamMatchup::where('tournament_id', $tournament->id)
                    ->whereJsonContains('meta->user_a_id', (string)$standing->user_id)
                    ->orWhereJsonContains('meta->user_b_id', (string)$standing->user_id)
                    ->where('tournament_id', $tournament->id)
                    ->where('status', 'completed')
                    ->orderByDesc('completed_at')
                    ->get();

                foreach ($matchups as $m) {
                    $isUserA = ($m->meta['user_a_id'] ?? null) == $standing->user_id;
                    $opponent = $isUserA ? ($m->meta['user_b_id'] ?? null) : ($m->meta['user_a_id'] ?? null);
                    $opponentUser = User::find($opponent);
                    $result = 'loss';
                    if ($m->winner_team_id == $standing->user_id) {
                        $result = 'win';
                    } elseif ($m->winner_team_id === null) {
                        $result = 'draw';
                    }

                    $matchHistory[] = [
                        'match_id' => $m->id,
                        'opponent' => $opponentUser ? $opponentUser->first_name . ' ' . $opponentUser->last_name : 'Unknown',
                        'result' => $result,
                        'date' => $m->completed_at,
                    ];
                }
            }

            Leaderboard::create([
                'tournament_id' => $tournament->id,
                'user_id' => $standing->user_id,
                'team_id' => $standing->team_id,
                'rank' => $standing->rank,
                'wins' => $standing->wins,
                'losses' => $standing->losses,
                'draws' => $standing->draws,
                'points' => $standing->points,
                'win_rate' => $standing->win_rate,
                'matches_played' => $standing->wins + $standing->losses + $standing->draws,
                'match_history' => $matchHistory,
                'stats' => [
                    'avg_points_per_match' => ($standing->wins + $standing->losses + $standing->draws) > 0 
                        ? $standing->points / ($standing->wins + $standing->losses + $standing->draws) 
                        : 0,
                ],
            ]);
        }
    }
}