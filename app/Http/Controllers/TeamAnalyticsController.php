<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\EventTeam;
use App\Models\PlayerRating;
use App\Models\TeamInvite;
use App\Models\User;
use Carbon\Carbon;

class TeamAnalyticsController extends Controller
{
    /**
     * Return analytics / overview for a team.
     * Access: owner, captain, manager.
     */
    public function overview(Request $request, string $teamId)
    {
        $user = auth()->user();

        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // permission: owner, captain, manager or team creator
        $isOwner = $user && $user->id === $team->created_by;
        $isCaptain = $user && TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();
        $isManager = $user && TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->exists();

        if (! ($isOwner || $isCaptain || $isManager)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // membership counts
        $totalMembers = TeamMember::where('team_id', $team->id)->count();
        $activeMembers = TeamMember::where('team_id', $team->id)->where('is_active', true)->count();
        $pendingRequests = TeamMember::where('team_id', $team->id)->where('role', 'pending')->count();
        $removedCount = TeamMember::where('team_id', $team->id)->where('roster_status', 'removed')->count();

        // roster capacity
        $rosterLimit = $team->roster_size_limit;
        $availableSlots = $rosterLimit ? max(0, $rosterLimit - $activeMembers) : null;

        // recent members (joined)
        $recentMembers = TeamMember::where('team_id', $team->id)
            ->where('role', '!=', 'pending')
            ->orderByDesc('joined_at')
            ->limit(10)
            ->with('user:id,username,profile_photo')
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->user_id,
                    'username' => $m->user->username ?? null,
                    'role' => $m->role,
                    'joined_at' => $m->joined_at,
                    'is_active' => (bool) $m->is_active,
                ];
            });

        // invites / active invite count
        $activeInvites = TeamInvite::where('team_id', $team->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        // events (team participated) stats
        $eventsCount = EventTeam::where('team_id', $team->id)->distinct('event_id')->count('event_id');
        $upcomingEvents = EventTeam::where('team_id', $team->id)
            ->whereHas('event', function ($q) { $q->where('date', '>=', now()->toDateString()); })
            ->count();

        // player rating aggregates for team members
        $memberUserIds = TeamMember::where('team_id', $team->id)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->toArray();

        $avgRating = null;
        $ratingCount = 0;
        if (! empty($memberUserIds)) {
            $agg = PlayerRating::whereIn('rated_user_id', $memberUserIds)
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as cnt')
                ->first();
            $avgRating = $agg->avg_rating !== null ? round((float)$agg->avg_rating, 2) : null;
            $ratingCount = (int) ($agg->cnt ?? 0);
        }

        // top players by average rating (limit 5)
        $topPlayers = [];
        if (! empty($memberUserIds)) {
            $topPlayers = PlayerRating::whereIn('rated_user_id', $memberUserIds)
                ->select('rated_user_id', DB::raw('AVG(rating) as avg_rating'), DB::raw('COUNT(*) as votes'))
                ->groupBy('rated_user_id')
                ->orderByDesc('avg_rating')
                ->orderByDesc('votes')
                ->limit(5)
                ->get()
                ->map(function ($r) {
                    $u = User::find($r->rated_user_id);
                    return [
                        'user_id' => $r->rated_user_id,
                        'username' => $u ? $u->username : null,
                        'avg_rating' => round((float)$r->avg_rating, 2),
                        'votes' => (int) $r->votes,
                    ];
                });
        }

        // member churn (joined vs removed) last 30 days
        $since = Carbon::now()->subDays(30);
        $joinedLast30 = TeamMember::where('team_id', $team->id)
            ->where('joined_at', '>=', $since)
            ->count();
        $removedLast30 = TeamMember::where('team_id', $team->id)
            ->where(function ($q) use ($since) {
                $q->where('removed_at', '>=', $since)->orWhere('roster_status', 'removed');
            })
            ->count();

        return response()->json([
            'status' => 'success',
            'team_id' => $team->id,
            'team_name' => $team->name,
            'analytics' => [
                'total_members' => $totalMembers,
                'active_members' => $activeMembers,
                'pending_requests' => $pendingRequests,
                'removed_members' => $removedCount,
                'roster_limit' => $rosterLimit,
                'available_slots' => $availableSlots,
                'recent_members' => $recentMembers,
                'active_invites' => $activeInvites,
                'events_count' => $eventsCount,
                'upcoming_events' => $upcomingEvents,
                'avg_member_rating' => $avgRating,
                'rating_count' => $ratingCount,
                'top_players' => $topPlayers,
                'joined_last_30_days' => $joinedLast30,
                'removed_last_30_days' => $removedLast30,
            ],
        ], 200);
    }
}
