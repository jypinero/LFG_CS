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

    /**
     * Generate comprehensive team report for printing.
     * Returns all team data in a structured format for frontend formatting.
     * Access: owner, captain, manager.
     */
    public function report(Request $request, string $teamId)
    {
        $user = auth()->user();

        $team = Team::with('creator')->find($teamId);
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

        // Get all team members with full details
        $allMembers = TeamMember::where('team_id', $team->id)
            ->with('user:id,username,email,first_name,last_name,profile_photo')
            ->orderBy('joined_at', 'desc')
            ->get()
            ->map(function ($member) {
                // Get individual member rating
                $memberRating = null;
                $memberRatingCount = 0;
                if ($member->user_id) {
                    $ratingAgg = PlayerRating::where('rated_user_id', $member->user_id)
                        ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as cnt')
                        ->first();
                    $memberRating = $ratingAgg && $ratingAgg->avg_rating !== null 
                        ? round((float)$ratingAgg->avg_rating, 2) 
                        : null;
                    $memberRatingCount = (int) ($ratingAgg->cnt ?? 0);
                }

                return [
                    'member_id' => $member->id,
                    'user_id' => $member->user_id,
                    'username' => $member->user->username ?? null,
                    'email' => $member->user->email ?? null,
                    'first_name' => $member->user->first_name ?? null,
                    'last_name' => $member->user->last_name ?? null,
                    'profile_photo' => $member->user && $member->user->profile_photo 
                        ? asset('storage/' . $member->user->profile_photo) 
                        : null,
                    'role' => $member->role,
                    'position' => $member->position,
                    'is_active' => (bool) $member->is_active,
                    'roster_status' => $member->roster_status,
                    'joined_at' => $member->joined_at,
                    'removed_at' => $member->removed_at,
                    'rating' => $memberRating,
                    'rating_count' => $memberRatingCount,
                ];
            });

        // Separate members by status
        $activeMembers = $allMembers->filter(function ($m) {
            return $m['is_active'] && $m['roster_status'] !== 'removed';
        })->values();
        $pendingMembers = $allMembers->filter(function ($m) {
            return $m['role'] === 'pending';
        })->values();
        $removedMembers = $allMembers->filter(function ($m) {
            return $m['roster_status'] === 'removed';
        })->values();

        // Get all events team participated in with venue and facility details
        $events = EventTeam::where('team_id', $team->id)
            ->with([
                'event' => function($query) {
                    $query->select('id', 'name', 'description', 'event_type', 'sport', 'date', 
                                  'start_time', 'end_time', 'venue_id', 'facility_id', 
                                  'cancelled_at', 'is_approved', 'tournament_id', 'slots');
                },
                'event.venue:id,name,address,latitude,longitude',
                'event.facility:id,name,venue_id,type'
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($eventTeam) {
                $event = $eventTeam->event;
                if (!$event) {
                    return null;
                }

                // Get event status (computed)
                $status = 'scheduled';
                if ($event->cancelled_at) {
                    $status = 'cancelled';
                } elseif ($event->date && $event->start_time && $event->end_time) {
                    $now = Carbon::now();
                    $dateOnly = Carbon::parse($event->date)->toDateString();
                    $eventStart = Carbon::parse($dateOnly . ' ' . $event->start_time);
                    $eventEnd = Carbon::parse($dateOnly . ' ' . $event->end_time);
                    
                    if ($now->lt($eventStart)) {
                        $status = 'upcoming';
                    } elseif ($now->between($eventStart, $eventEnd)) {
                        $status = 'ongoing';
                    } else {
                        $status = 'completed';
                    }
                }

                return [
                    'event_id' => $event->id,
                    'name' => $event->name,
                    'description' => $event->description,
                    'event_type' => $event->event_type,
                    'sport' => $event->sport,
                    'date' => $event->date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'status' => $status,
                    'is_approved' => (bool) $event->is_approved,
                    'cancelled_at' => $event->cancelled_at,
                    'slots' => $event->slots,
                    'tournament_id' => $event->tournament_id,
                    'venue' => $event->venue ? [
                        'id' => $event->venue->id,
                        'name' => $event->venue->name,
                        'address' => $event->venue->address,
                        'latitude' => $event->venue->latitude,
                        'longitude' => $event->venue->longitude,
                    ] : null,
                    'facility' => $event->facility ? [
                        'id' => $event->facility->id,
                        'name' => $event->facility->name,
                        'type' => $event->facility->type,
                        'venue_id' => $event->facility->venue_id,
                    ] : null,
                    'venue_id' => $event->venue_id,
                    'facility_id' => $event->facility_id,
                    'group' => $eventTeam->group,
                    'participated_at' => $eventTeam->created_at,
                ];
            })
            ->filter(); // Remove null entries

        // Separate upcoming and past events
        $upcomingEvents = $events->filter(function ($e) {
            return $e['date'] && Carbon::parse($e['date'])->isFuture();
        })->values();
        $pastEvents = $events->filter(function ($e) {
            return $e['date'] && Carbon::parse($e['date'])->isPast();
        })->values();

        // Get all active invites
        $invites = TeamInvite::where('team_id', $team->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($invite) {
                return [
                    'id' => $invite->id,
                    'token' => $invite->token,
                    'expires_at' => $invite->expires_at,
                    'created_at' => $invite->created_at,
                ];
            });

        // Get member user IDs for rating calculations
        $memberUserIds = $allMembers->pluck('user_id')->filter()->unique()->toArray();

        // Overall team rating statistics
        $avgRating = null;
        $ratingCount = 0;
        if (! empty($memberUserIds)) {
            $agg = PlayerRating::whereIn('rated_user_id', $memberUserIds)
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as cnt')
                ->first();
            $avgRating = $agg && $agg->avg_rating !== null 
                ? round((float)$agg->avg_rating, 2) 
                : null;
            $ratingCount = (int) ($agg->cnt ?? 0);
        }

        // Top players by rating
        $topPlayers = [];
        if (! empty($memberUserIds)) {
            $topPlayers = PlayerRating::whereIn('rated_user_id', $memberUserIds)
                ->select('rated_user_id', DB::raw('AVG(rating) as avg_rating'), DB::raw('COUNT(*) as votes'))
                ->groupBy('rated_user_id')
                ->orderByDesc('avg_rating')
                ->orderByDesc('votes')
                ->limit(10)
                ->get()
                ->map(function ($r) {
                    $u = User::find($r->rated_user_id);
                    return [
                        'user_id' => $r->rated_user_id,
                        'username' => $u ? $u->username : null,
                        'first_name' => $u ? $u->first_name : null,
                        'last_name' => $u ? $u->last_name : null,
                        'avg_rating' => round((float)$r->avg_rating, 2),
                        'votes' => (int) $r->votes,
                    ];
                });
        }

        // Member activity statistics
        $now = Carbon::now();
        $joinedLast30 = TeamMember::where('team_id', $team->id)
            ->where('joined_at', '>=', $now->copy()->subDays(30))
            ->count();
        $joinedLast60 = TeamMember::where('team_id', $team->id)
            ->where('joined_at', '>=', $now->copy()->subDays(60))
            ->count();
        $joinedLast90 = TeamMember::where('team_id', $team->id)
            ->where('joined_at', '>=', $now->copy()->subDays(90))
            ->count();
        
        $removedLast30 = TeamMember::where('team_id', $team->id)
            ->where(function ($q) use ($now) {
                $q->where('removed_at', '>=', $now->copy()->subDays(30))
                  ->orWhere(function ($q2) use ($now) {
                      $q2->where('roster_status', 'removed')
                         ->where('updated_at', '>=', $now->copy()->subDays(30));
                  });
            })
            ->count();

        // Team information
        $teamInfo = [
            'id' => $team->id,
            'name' => $team->name,
            'team_type' => $team->team_type,
            'team_photo' => $team->team_photo ? asset('storage/' . $team->team_photo) : null,
            'certification' => $team->certification,
            'certified' => (bool) $team->certified,
            'address_line' => $team->address_line,
            'latitude' => $team->latitude,
            'longitude' => $team->longitude,
            'roster_size_limit' => $team->roster_size_limit,
            'created_at' => $team->created_at,
            'updated_at' => $team->updated_at,
            'creator' => $team->creator ? [
                'id' => $team->creator->id,
                'username' => $team->creator->username,
                'email' => $team->creator->email,
            ] : null,
        ];

        // Summary statistics
        $summary = [
            'total_members' => $allMembers->count(),
            'active_members' => $activeMembers->count(),
            'pending_requests' => $pendingMembers->count(),
            'removed_members' => $removedMembers->count(),
            'roster_limit' => $team->roster_size_limit,
            'available_slots' => $team->roster_size_limit 
                ? max(0, $team->roster_size_limit - $activeMembers->count()) 
                : null,
            'active_invites' => $invites->count(),
            'total_events' => $events->count(),
            'upcoming_events' => $upcomingEvents->count(),
            'past_events' => $pastEvents->count(),
            'avg_member_rating' => $avgRating,
            'total_ratings' => $ratingCount,
            'joined_last_30_days' => $joinedLast30,
            'joined_last_60_days' => $joinedLast60,
            'joined_last_90_days' => $joinedLast90,
            'removed_last_30_days' => $removedLast30,
        ];

        return response()->json([
            'status' => 'success',
            'report_generated_at' => now()->toIso8601String(),
            'team' => $teamInfo,
            'summary' => $summary,
            'members' => [
                'all' => $allMembers,
                'active' => $activeMembers,
                'pending' => $pendingMembers,
                'removed' => $removedMembers,
            ],
            'events' => [
                'all' => $events,
                'upcoming' => $upcomingEvents,
                'past' => $pastEvents,
            ],
            'ratings' => [
                'team_average' => $avgRating,
                'total_ratings' => $ratingCount,
                'top_players' => $topPlayers,
            ],
            'invites' => $invites,
            'statistics' => $summary,
        ], 200);
    }
}
