<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Team;
use App\Models\TeamMember;

class UserController extends Controller
{
    /**
     * Show current and past teams for a user.
     * If $userId is omitted returns for authenticated user.
     */
    public function showUserTeams(Request $request, ?int $userId = null)
    {
        $user = $userId ? User::find($userId) : auth()->user();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        // Load active memberships with team relation
        $currentMemberships = TeamMember::with('team')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('roster_status', 'active')
            ->get();

        $current = $currentMemberships->map(function ($m) {
            return [
                'team_id' => $m->team_id,
                'team' => $m->team ? [
                    'id' => $m->team->id,
                    'name' => $m->team->name ?? null,
                    'created_by' => $m->team->created_by ?? null,
                ] : null,
                'role' => $m->role,
                'is_active' => (bool) $m->is_active,
                'roster_status' => $m->roster_status,
                'joined_at' => $m->joined_at ?? $m->created_at,
            ];
        });

        // Load past memberships separately
        $pastMemberships = TeamMember::with('team')
            ->where('user_id', $user->id)
            ->where(function($q) {
                $q->where('roster_status', 'left')
                  ->orWhere('roster_status', 'removed')
                  ->orWhere(function($inner) {
                      $inner->where('is_active', false)
                            ->where('roster_status', '!=', 'active');
                  });
            })
            ->get();

        $past = $pastMemberships->map(function ($m) {
            return [
                'team_id' => $m->team_id,
                'team' => $m->team ? [
                    'id' => $m->team->id,
                    'name' => $m->team->name ?? null,
                    'created_by' => $m->team->created_by ?? null,
                ] : null,
                'role' => $m->role,
                'roster_status' => $m->roster_status,
                'joined_at' => $m->joined_at ?? $m->created_at,
                'removed_at' => $m->removed_at ?? $m->updated_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'user_id' => $user->id,
                'current_teams' => $current,
                'past_teams' => $past,
            ]
        ], 200);
    }
}
