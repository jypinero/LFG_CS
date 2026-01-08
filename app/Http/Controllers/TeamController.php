<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TeamInvite;
use App\Models\User; // ADDED
use Illuminate\Support\Facades\DB; // ADDED

class TeamController extends Controller
{
    /**
     * Discover teams that are open to new members with optional filters.
     * Query params: sport_id, q, lat, lng, radius_km (default 50), page, per_page
     */
    public function discoverLookingForTeams(Request $request)
    {
        $user = auth()->user();
        $validator = Validator::make($request->all(), [
            'sport_id' => 'nullable|integer|exists:sports,id',
            'q' => 'nullable|string|max:100',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'radius_km' => 'nullable|numeric|min:1|max:500',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $lat = $request->lat;
        $lng = $request->lng;
        $radiusKm = $request->input('radius_km', 50);
        $perPage = $request->input('per_page', 15);

        // Base query with member counts and active counts
        $teams = Team::query()
            ->with('sport')
            ->select('teams.*')
            ->selectSub(function ($q) {
                $q->from('team_members')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('team_members.team_id', 'teams.id')
                    ->where(function ($inner) {
                        $inner->whereNull('team_members.role')
                              ->orWhere('team_members.role', '!=', 'pending');
                    });
            }, 'member_count')
            ->selectSub(function ($q) {
                $q->from('team_members')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('team_members.team_id', 'teams.id')
                    ->where('team_members.is_active', true);
            }, 'active_member_count');

        // Text search
        if ($request->filled('q')) {
            $q = $request->q;
            $teams->where(function ($w) use ($q) {
                $w->where('teams.name', 'like', "%{$q}%")
                  ->orWhere('teams.bio', 'like', "%{$q}%")
                  ->orWhere('teams.address_line', 'like', "%{$q}%");
            });
        }

        // Sport filter
        if ($request->filled('sport_id')) {
            $teams->where('teams.sport_id', $request->sport_id);
        }

        // Exclude teams the user owns or is already a member/pending
        if ($user) {
            $teams->where('teams.created_by', '!=', $user->id)
                ->whereNotExists(function ($q) use ($user) {
                    $q->from('team_members')
                        ->whereColumn('team_members.team_id', 'teams.id')
                        ->where('team_members.user_id', $user->id);
                });
        }

        // "Looking for" heuristic: either no roster limit or active members < limit
        $teams->where(function ($w) {
            $w->whereNull('teams.roster_size_limit')
              ->orWhereRaw('COALESCE((select count(*) from team_members tm where tm.team_id = teams.id and tm.is_active = 1), 0) < teams.roster_size_limit');
        });

        // Distance filter/order if coordinates provided
        if ($lat !== null && $lng !== null) {
            // Haversine in KM
            $haversine = "6371 * acos(cos(radians(?)) * cos(radians(teams.latitude)) * cos(radians(teams.longitude) - radians(?)) + sin(radians(?)) * sin(radians(teams.latitude)))";
            $teams->addSelect(DB::raw("$haversine as distance_km"))->addBinding([$lat, $lng, $lat], 'select');
            $teams->whereNotNull('teams.latitude')->whereNotNull('teams.longitude');
            $teams->having('distance_km', '<=', $radiusKm)->orderBy('distance_km');
        } else {
            $teams->orderByDesc('teams.created_at');
        }

        $paginated = $teams->paginate($perPage);

        $data = $paginated->getCollection()->map(function ($team) {
            return [
                'id' => $team->id,
                'name' => $team->name,
                'created_by' => $team->created_by,
                'team_photo' => $team->team_photo ? asset('storage/' . $team->team_photo) : null,
                'certification' => $team->certification,
                'certified' => $team->certified,
                'team_type' => $team->team_type,
                'sport_id' => $team->sport_id,
                'sport' => $team->sport ? [
                    'id' => $team->sport->id,
                    'name' => $team->sport->name,
                    'category' => $team->sport->category,
                ] : null,
                'bio' => $team->bio,
                'address_line' => $team->address_line,
                'latitude' => $team->latitude,
                'longitude' => $team->longitude,
                'roster_size_limit' => $team->roster_size_limit,
                'member_count' => (int) ($team->member_count ?? 0),
                'active_member_count' => (int) ($team->active_member_count ?? 0),
                'distance_km' => isset($team->distance_km) ? round($team->distance_km, 2) : null,
                'created_at' => $team->created_at,
                'updated_at' => $team->updated_at,
                'creator' => $team->creator ? [
                    'id' => $team->creator->id,
                    'name' => $team->creator->username,
                    'email' => $team->creator->email,
                ] : null,
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * List my pending team join requests (as requester).
     */
    public function myJoinRequests(Request $request)
    {
        $user = auth()->user();
        $requests = TeamMember::where('user_id', $user->id)
            ->where('role', 'pending')
            ->with(['team:id,name,team_photo,sport_id'])
            ->orderByDesc('joined_at')
            ->get()
            ->map(function ($row) {
                return [
                    'request_id' => $row->id,
                    'team_id' => $row->team_id,
                    'team_name' => $row->team->name ?? null,
                    'team_photo' => ($row->team && $row->team->team_photo) ? asset('storage/' . $row->team->team_photo) : null,
                    'sport_id' => $row->team->sport_id ?? null,
                    'status' => 'pending',
                    'requested_at' => $row->joined_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $requests,
            'total' => $requests->count(),
        ]);
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $teams = Team::with(['creator', 'sport'])->get()->map(function ($team) {
            return [
                'id' => $team->id,
                'name' => $team->name,
                'created_by' => $team->created_by,
                'team_photo' => $team->team_photo ? asset('storage/' . $team->team_photo) : null,
                'certification' => $team->certification,
                'certified' => $team->certified,
                'team_type' => $team->team_type,
                'address_line' => $team->address_line,
                'latitude' => $team->latitude,
                'longitude' => $team->longitude,
                'sport_id' => $team->sport_id,
                'sport' => $team->sport ? [
                    'id' => $team->sport->id,
                    'name' => $team->sport->name,
                    'category' => $team->sport->category,
                ] : null,
                'bio' => $team->bio,
                'roster_size_limit' => $team->roster_size_limit,
                'created_at' => $team->created_at,
                'updated_at' => $team->updated_at,
                'creator' => $team->creator ? [
                    'id' => $team->creator->id,
                    'name' => $team->creator->username,
                    'email' => $team->creator->email,
                    // Add other user fields as needed
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'teams' => $teams
            ]
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'team_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
            'certification' => 'nullable|string|max:255',
            'certified' => 'nullable|boolean',
            'team_type' => 'nullable|string|max:100',
            'address_line' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'sport_id' => 'nullable|exists:sports,id',
            'bio' => 'nullable|string|max:500',
            'roster_size_limit' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Prepare data
        $data = [
            'name' => $request->name,
            'created_by' => $user->id,
            'certification' => $request->certification ?? null,
            'certified' => $request->boolean('certified', false),
            'team_type' => $request->team_type ?? null,
            'address_line' => $request->address_line ?? null,
            'latitude' => $request->latitude ?? null,
            'longitude' => $request->longitude ?? null,
            'sport_id' => $request->sport_id ?? null,
            'bio' => $request->bio ?? null,
            'roster_size_limit' => $request->roster_size_limit ?? null,
        ];

        // Handle team photo upload
        if ($request->hasFile('team_photo')) {
            $file = $request->file('team_photo');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('team_photos', $fileName, 'public');
            $data['team_photo'] = $path;
        }

        // Create team
        $team = Team::create($data);

        // Add creator as team member (owner)
        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'joined_at' => now(),
            'is_active' => true,
            'roster_status' => 'active',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Team created',
            'team' => $team,
            'creator_member' => $member,
        ], 201);
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update team (only owner or captain allowed).
     */
    public function update(Request $request, string $id)
    {
        $user = auth()->user();
        $team = Team::find($id);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // check owner or captain
        $isOwner = $user->id === $team->created_by;
        $isCaptain = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();

        if (! $isOwner && ! $isCaptain) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden - only owner or captain can edit team'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'team_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
            'certification' => 'nullable|string|max:255',
            'certified' => 'nullable|boolean',
            'team_type' => 'nullable|string|max:100',
            'address_line' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'sport_id' => 'nullable|exists:sports,id',
            'bio' => 'nullable|string|max:500',
            'roster_size_limit' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $data = array_filter([
            'name' => $request->name,
            'certification' => $request->certification ?? null,
            'certified' => $request->has('certified') ? $request->boolean('certified') : null,
            'team_type' => $request->team_type ?? null,
            'address_line' => $request->address_line ?? null,
            'latitude' => $request->latitude ?? null,
            'longitude' => $request->longitude ?? null,
            'sport_id' => $request->sport_id ?? null,
            'bio' => $request->bio ?? null,
            'roster_size_limit' => $request->roster_size_limit ?? null,
        ], function ($v) { return !is_null($v); });

        if ($request->hasFile('team_photo')) {
            if ($team->team_photo) {
                Storage::disk('public')->delete($team->team_photo);
            }
            $file = $request->file('team_photo');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('team_photos', $fileName, 'public');
            $data['team_photo'] = $path;
        }

        $team->update($data);

        return response()->json(['status'=>'success','message'=>'Team updated','team'=>$team], 200);
    }

    /**
     * Update team photo only (owner or captain allowed).
     */
    public function updatePhoto(Request $request, string $id)
    {
        $user = auth()->user();
        $team = Team::find($id);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // check owner or captain
        $isOwner = $user->id === $team->created_by;
        $isCaptain = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();

        if (! $isOwner && ! $isCaptain) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden - only owner or captain can update team photo'], 403);
        }

        $validator = Validator::make($request->all(), [
            'team_photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        // Delete old photo if exists
        if ($team->team_photo) {
            Storage::disk('public')->delete($team->team_photo);
        }

        // Handle new photo upload
        $file = $request->file('team_photo');
        $fileName = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('team_photos', $fileName, 'public');

        $team->team_photo = $path;
        $team->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Team photo updated',
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'team_photo' => asset('storage/' . $team->team_photo),
            ]
        ], 200);
    }

    /**
     * Add a member to the team by username (owner only).
     */
    public function addMember(Request $request, string $id)
    {
        $user = auth()->user();
        $team = Team::find($id);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // only owner can add members
        if ($user->id !== $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'role' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $newUser = User::where('username', $request->username)->first();
        if (! $newUser) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        // avoid duplicate membership
        if (TeamMember::where('team_id', $team->id)->where('user_id', $newUser->id)->exists()) {
            return response()->json(['status'=>'error','message'=>'User is already a member'], 409);
        }

        // Check roster size limit
        $activeCount = TeamMember::where('team_id', $team->id)
            ->where('is_active', true)
            ->count();
        
        if ($team->roster_size_limit && $activeCount >= $team->roster_size_limit) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot add member: Roster size limit reached ({$activeCount}/{$team->roster_size_limit} active)"
            ], 409);
        }

        // ensure role length and safe create, catch DB errors (e.g. enum/length)
        $role = $request->role ? substr($request->role, 0, 50) : 'member';

        try {
            $member = TeamMember::create([
                'team_id' => $team->id,
                'user_id' => $newUser->id,
                'role' => $role,
                'joined_at' => now(),
                'is_active' => true,
                'roster_status' => 'active',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Database error adding member',
                'error' => $e->getMessage()
            ], 500);
        }

        return response()->json(['status'=>'success','message'=>'Member added','member'=>$member], 201);
    }

    public function transferOwnership(Request $request, string $id)
    {
        $user = auth()->user();
        $team = Team::find($id);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only current owner can transfer
        if ($user->id !== $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'new_owner_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $newOwnerId = (int)$request->new_owner_id;

        DB::transaction(function () use ($team, $user, $newOwnerId) {
            // ensure new owner is a team member
            TeamMember::firstOrCreate(
                ['team_id' => $team->id, 'user_id' => $newOwnerId],
                ['role' => 'owner', 'joined_at' => now()]
            );

            // demote previous owner record (if exists)
            TeamMember::where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->update(['role' => 'member']);

            // promote new owner
            TeamMember::where('team_id', $team->id)
                ->where('user_id', $newOwnerId)
                ->update(['role' => 'owner']);

            // update team created_by
            $team->created_by = $newOwnerId;
            $team->save();
        });

        $team->refresh();

        return response()->json(['status'=>'success','message'=>'Ownership transferred','team'=>$team], 200);
    }

    public function editMemberRole(Request $request, string $teamId, string $memberId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner can edit member roles
        if ($user->id !== $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'role' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $member = TeamMember::where('team_id', $team->id)
            ->where('id', $memberId)
            ->first();

        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Team member not found'], 404);
        }

        // Prevent owner from demoting themselves
        if ($member->user_id == $user->id && $request->role !== 'owner') {
            return response()->json(['status' => 'error', 'message' => 'Owner cannot change their own role'], 403);
        }

        $member->role = substr($request->role, 0, 50);
        $member->save();

        return response()->json(['status'=>'success','message'=>'Member role updated','member'=>$member], 200);
    }

    public function removeMember(Request $request, string $teamId, string $memberId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner, captain, or manager can remove members
        $isOwner = $user->id === $team->created_by;
        $isCaptain = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();
        $isManager = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->exists();

        if (! $isOwner && ! $isCaptain && ! $isManager) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden - only owner, captain, or manager can remove members'], 403);
        }

        $member = TeamMember::where('team_id', $team->id)
            ->where('id', $memberId)
            ->first();

        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Team member not found'], 404);
        }

        // Prevent owner from removing themselves (unless transferring ownership first)
        if ($member->user_id == $user->id && $isOwner) {
            return response()->json(['status' => 'error', 'message' => 'Owner cannot remove themselves. Transfer ownership first.'], 403);
        }

        // Mark member as left instead of deleting
        $member->is_active = false;
        $member->roster_status = 'removed';
        $member->removed_at = now();
        $member->save();

        return response()->json(['status'=>'success','message'=>'Member marked as removed','member'=>$member], 200);
    }

    public function members(string $teamId)
    {
        $team = Team::with('creator')->find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        $members = TeamMember::where('team_id', $team->id)
            ->with('user:id,username,email,profile_photo')
            ->get()
            ->map(function ($member) {
                return [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'username' => $member->user->username ?? null,
                    'email' => $member->user->email ?? null,
                    'profile_photo' => $member->user->profile_photo ? asset('storage/' . $member->user->profile_photo) : null,
                    'is_active' => $member->is_active,
                    'roster_status' => $member->roster_status,
                    'position' => $member->position,
                    'role' => $member->role,
                    'joined_at' => $member->joined_at,
                ];
            });

        $teamInfo = [
            'id' => $team->id,
            'name' => $team->name,
            'created_by' => $team->created_by,
            'team_photo' => $team->team_photo ? asset('storage/' . $team->team_photo) : null,
            'certification' => $team->certification,
            'certified' => $team->certified,
            'team_type' => $team->team_type,
            'address_line' => $team->address_line,
            'latitude' => $team->latitude,
            'longitude' => $team->longitude,
            'created_at' => $team->created_at,
            'updated_at' => $team->updated_at,
            'creator' => $team->creator ? [
                'id' => $team->creator->id,
                'username' => $team->creator->username,
                'email' => $team->creator->email,
            ] : null,
        ];

        return response()->json([
            'status' => 'success',
            'team' => $teamInfo,
            'members' => $members,
        ]);
    }

    /**
     * Allow a user to request to join a team.
     * Validations:
     * - User can't join if already in any team.
     * - User can't join if already in the requested team.
     */
    public function requestJoinTeam(Request $request, string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);

        if (! $team) {
            return response()->json([
                'status' => 'error',
                'message' => 'Team not found'
            ], 404);
        }

        /**
         * Check existing ACTIVE memberships
         */
        $activeTeams = TeamMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->with('team:id,team_type')
            ->get();

        $hasCollegiate = $activeTeams->contains(fn ($m) => $m->team->team_type  === 'collegiate');
        $hasProfessional = $activeTeams->contains(fn ($m) => $m->team->team_type  === 'professional');

        if ($hasCollegiate && $hasProfessional) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot join more teams because you are already part of both collegiate and professional teams.'
            ], 409);
        }

        /**
         * Prevent duplicate membership in same team
         */
        $existing = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are already part of this team or have a pending request.'
            ], 409);
        }

        /**
         * Check roster limit (active members only)
         */
        $activeCount = TeamMember::where('team_id', $team->id)
            ->where('is_active', true)
            ->count();

        if ($team->roster_size_limit && $activeCount >= $team->roster_size_limit) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot request to join: roster is full ({$activeCount}/{$team->roster_size_limit})"
            ], 409);
        }

        /**
         * Create pending membership
         */
        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'pending',
            'is_active' => false,
            'joined_at' => now(),
        ]);

        /**
         * Notify team owner
         */
        $ownerId = $team->created_by;

        $notif = \App\Models\Notification::create([
            'type' => 'team_join_request',
            'data' => [
                'message' => "{$user->username} requested to join your team: {$team->name}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ],
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        \App\Models\UserNotification::create([
            'notification_id' => $notif->id,
            'user_id' => $ownerId,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'pending',
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Join request sent. Waiting for approval.',
            'member' => $member,
        ], 201);
    }

    /**
     * Owner can accept or decline a user's join request.
    */
    public function handleJoinRequest(Request $request, string $teamId, string $memberId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner can accept/decline requests
        if ($user->id !== $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:accept,decline',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $member = TeamMember::where('team_id', $team->id)
            ->where('id', $memberId)
            ->where('role', 'pending')
            ->first();

        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Pending join request not found'], 404);
        }

        $requesterId = $member->user_id;

        // Find the notification for this join request (owner's inbox)
        $notification = \App\Models\Notification::where('type', 'team_join_request')
            ->where('data->team_id', $team->id)
            ->where('data->user_id', $member->user_id)
            ->latest()
            ->first();

        $userNotification = null;
        if ($notification) {
            $userNotification = \App\Models\UserNotification::where('notification_id', $notification->id)
                ->where('user_id', $team->created_by)
                ->first();
        }

        // Log the action event (owner handled)
        if ($userNotification) {
            \App\Models\UserNotificationActionEvent::create([
                'user_notification_id' => $userNotification->id,
                'action_key' => $request->action,
                'metadata' => [
                    'handled_by' => $user->id,
                    'handled_at' => now(),
                    'member_id' => $member->user_id,
                ],
                'created_at' => now(),
            ]);

            // Update notification state for owner view
            $actionState = $request->action === 'accept' ? 'accepted' : 'ignored';
            $userNotification->action_state = $actionState;
            $userNotification->is_read = false;
            $userNotification->save();
        }

        // Prepare response notification for requester
        $responseAction = $request->action === 'accept' ? 'accepted' : 'declined';
        $responseMessage = $request->action === 'accept'
            ? ($team->name . ' owner accepted your request to join the team.')
            : ($team->name . ' owner declined your request to join the team.');

        if ($request->action === 'accept') {
            // Check roster size limit
            $activeCount = TeamMember::where('team_id', $team->id)
                ->where('is_active', true)
                ->count();
            
            if ($team->roster_size_limit && $activeCount >= $team->roster_size_limit) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Cannot accept request: Roster size limit reached ({$activeCount}/{$team->roster_size_limit} active)"
                ], 409);
            }

            $member->role = 'member';
            $member->is_active = true;
            $member->roster_status = 'active';
            $member->save();

            // Notify requester about acceptance
            $respNotif = \App\Models\Notification::create([
                'type' => 'team_join_response',
                'data' => [
                    'message' => $responseMessage,
                    'team_id' => $team->id,
                    'user_id' => $requesterId,
                    'action' => $responseAction,
                ],
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            \App\Models\UserNotification::create([
                'notification_id' => $respNotif->id,
                'user_id' => $requesterId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => $responseAction,
                'created_at' => now(),
            ]);

            return response()->json(['status'=>'success','message'=>'Request accepted','member'=>$member], 200);
        } else {
            // Remove pending request
            $member->delete();

            // Notify requester about decline
            $respNotif = \App\Models\Notification::create([
                'type' => 'team_join_response',
                'data' => [
                    'message' => $responseMessage,
                    'team_id' => $team->id,
                    'user_id' => $requesterId,
                    'action' => $responseAction,
                ],
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            \App\Models\UserNotification::create([
                'notification_id' => $respNotif->id,
                'user_id' => $requesterId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => $responseAction,
                'created_at' => now(),
            ]);

            return response()->json(['status'=>'success','message'=>'Request declined'], 200);
        }
    }

    /**
     * Get pending join requests for a team (owner only).
     */
    public function getPendingRequests(string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        
        if (!$team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner can view pending requests
        if ($user->id !== $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $pendingRequests = TeamMember::where('team_id', $team->id)
            ->where('role', 'pending')
            ->with('user:id,username,email,created_at,profile_photo')
            ->orderBy('joined_at', 'desc')
            ->get()
            ->map(function ($member) {
                return [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'username' => $member->user->username,
                    'email' => $member->user->email,
                    'profile_photo' => $member->user->profile_photo ? asset('storage/' . $member->user->profile_photo) : null,
                    'requested_at' => $member->joined_at,
                    'user_joined_platform' => $member->user->created_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'pending_requests' => $pendingRequests,
                'total_pending' => $pendingRequests->count(),
            ],
        ]);
    }

    /**
     * Get join request history for a team (owner only).
     */
    public function getRequestHistory(string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        
        if (!$team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner can view request history
        if ($user->id !== $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // Get notifications related to team join requests
        $notifications = \App\Models\Notification::where('type', 'team_join_request')
            ->where('data->team_id', $team->id)
            ->with(['userNotifications' => function($query) use ($user) {
                $query->where('user_id', $user->id);
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($notification) use ($user) {
                $userNotif = $notification->userNotifications->first();
                $userId = $notification->data['user_id'] ?? null;
                $requestUser = $userId ? User::find($userId) : null;
                
                return [
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'username' => $requestUser->username ?? null,
                    'email' => $requestUser->email ?? null,
                    'profile_photo' => $requestUser && $requestUser->profile_photo ? asset('storage/' . $requestUser->profile_photo) : null,
                    'message' => $notification->data['message'] ?? null,
                    'action_state' => $userNotif->action_state ?? 'pending',
                    'created_at' => $notification->created_at,
                    'handled_at' => $userNotif->updated_at ?? null,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'request_history' => $notifications,
            ],
        ]);
    }

    /**
     * Handle multiple join requests at once (owner only).
     */
    public function handleBulkRequests(Request $request, string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        
        if (!$team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner can handle requests
        if ($user->id !== $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'requests' => 'required|array',
            'requests.*.member_id' => 'required|integer',
            'requests.*.action' => 'required|in:accept,decline',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($request->requests as $requestData) {
            try {
                $member = TeamMember::where('team_id', $team->id)
                    ->where('id', $requestData['member_id'])
                    ->where('role', 'pending')
                    ->first();

                if (!$member) {
                    $results[] = [
                        'member_id' => $requestData['member_id'],
                        'status' => 'error',
                        'message' => 'Pending request not found'
                    ];
                    $errorCount++;
                    continue;
                }

                // Find and update notification
                $notification = \App\Models\Notification::where('type', 'team_join_request')
                    ->where('data->team_id', $team->id)
                    ->where('data->user_id', $member->user_id)
                    ->latest()
                    ->first();

                if ($notification) {
                    $userNotification = \App\Models\UserNotification::where('notification_id', $notification->id)
                        ->where('user_id', $team->created_by)
                        ->first();

                    if ($userNotification) {
                        \App\Models\UserNotificationActionEvent::create([
                            'user_notification_id' => $userNotification->id,
                            'action_key' => $requestData['action'],
                            'metadata' => [
                                'handled_by' => $user->id,
                                'handled_at' => now(),
                                'member_id' => $member->user_id,
                            ],
                            'created_at' => now(),
                        ]);

                        // Map request action to enum value
                        $actionState = $requestData['action'] === 'accept' ? 'accepted' : 'ignored';
                        $userNotification->action_state = $actionState;
                        $userNotification->save();
                    }
                }

                if ($requestData['action'] === 'accept') {
                    $member->role = 'member';
                    $member->save();
                    $results[] = [
                        'member_id' => $requestData['member_id'],
                        'user_id' => $member->user_id,
                        'status' => 'success',
                        'action' => 'accepted',
                        'message' => 'Request accepted'
                    ];
                } else {
                    $member->delete();
                    $results[] = [
                        'member_id' => $requestData['member_id'],
                        'user_id' => $member->user_id,
                        'status' => 'success',
                        'action' => 'declined',
                        'message' => 'Request declined'
                    ];
                }
                $successCount++;

            } catch (\Exception $e) {
                $results[] = [
                    'member_id' => $requestData['member_id'],
                    'status' => 'error',
                    'message' => 'Failed to process request: ' . $e->getMessage()
                ];
                $errorCount++;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => "Processed {$successCount} requests successfully, {$errorCount} failed",
            'results' => $results,
            'summary' => [
                'total_processed' => count($request->requests),
                'successful' => $successCount,
                'failed' => $errorCount,
            ]
        ]);
    }

    /**
     * Cancel own join request.
     */
    public function cancelJoinRequest(string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        
        if (!$team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        $pendingRequest = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'pending')
            ->first();

        if (!$pendingRequest) {
            return response()->json(['status' => 'error', 'message' => 'No pending request found'], 404);
        }

        // Delete the pending request
        $pendingRequest->delete();

        // Update notification if exists
        $notification = \App\Models\Notification::where('type', 'team_join_request')
            ->where('data->team_id', $team->id)
            ->where('data->user_id', $user->id)
            ->latest()
            ->first();

        if ($notification) {
            $userNotification = \App\Models\UserNotification::where('notification_id', $notification->id)
                ->where('user_id', $team->created_by)
                ->first();

            if ($userNotification) {
                \App\Models\UserNotificationActionEvent::create([
                    'user_notification_id' => $userNotification->id,
                    'action_key' => 'cancelled',
                    'metadata' => [
                        'cancelled_by' => $user->id,
                        'cancelled_at' => now(),
                    ],
                    'created_at' => now(),
                ]);

                $userNotification->action_state = 'cancelled';
                $userNotification->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Join request cancelled successfully'
        ]);
    }

    /**
     * Update roster status/position for a member
     */
    public function updateRoster(Request $request, string $teamId, string $memberId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner or captain can update roster
        $isOwner = $user->id === $team->created_by;
        $isCaptain = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();

        if (! $isOwner && ! $isCaptain) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden - only owner or captain can update roster'], 403);
        }

        $validator = Validator::make($request->all(), [
            'is_active' => 'nullable|boolean',
            'position' => 'nullable|string|max:100',
            'roster_status' => 'nullable|in:active,inactive,injured,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $member = TeamMember::where('team_id', $team->id)
            ->where('id', $memberId)
            ->first();

        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Team member not found'], 404);
        }

        // Check roster size limit if activating member
        if ($request->has('is_active') && $request->boolean('is_active') && !$member->is_active) {
            $activeCount = TeamMember::where('team_id', $team->id)
                ->where('is_active', true)
                ->count();
            
            if ($team->roster_size_limit && $activeCount >= $team->roster_size_limit) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Cannot activate member: Roster size limit reached ({$activeCount}/{$team->roster_size_limit} active)"
                ], 409);
            }
        }

        $member->update(array_filter([
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'position' => $request->position ?? null,
            'roster_status' => $request->roster_status ?? null,
        ], function ($v) { return !is_null($v); }));

        return response()->json(['status'=>'success','message'=>'Roster updated','member'=>$member], 200);
    }

    /**
     * Get active roster for a team
     */
    public function getRoster(string $teamId)
    {
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        $members = TeamMember::where('team_id', $team->id)
            ->with('user:id,username,email')
            ->where('roster_status', '!=', 'removed' && 'roster_status', '!=', 'left')
            ->get();

        $active = $members->where('is_active', true)->map(function ($member) {
            return [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'username' => $member->user->username ?? null,
                'email' => $member->user->email ?? null,
                'role' => $member->role,
                'position' => $member->position,
                'roster_status' => $member->roster_status,
                'joined_at' => $member->joined_at,
            ];
        });

        $inactive = $members->where('is_active', false)->map(function ($member) {
            return [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'username' => $member->user->username ?? null,
                'email' => $member->user->email ?? null,
                'role' => $member->role,
                'position' => $member->position,
                'roster_status' => $member->roster_status,
                'joined_at' => $member->joined_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'roster_size_limit' => $team->roster_size_limit,
            ],
            'roster' => [
                'active' => $active->values(),
                'inactive' => $inactive->values(),
                'total_active' => $active->count(),
                'total_inactive' => $inactive->count(),
                'available_slots' => $team->roster_size_limit ? max(0, $team->roster_size_limit - $active->count()) : null,
            ],
        ]);
    }

    /**
     * Set roster size limit
     */
    public function setRosterLimit(Request $request, string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner or captain can set roster limit
        $isOwner = $user->id === $team->created_by;
        $isCaptain = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();

        if (! $isOwner && ! $isCaptain) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden - only owner or captain can set roster limit'], 403);
        }

        $validator = Validator::make($request->all(), [
            'roster_size_limit' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $team->roster_size_limit = $request->roster_size_limit;
        $team->save();

        $activeCount = TeamMember::where('team_id', $team->id)
            ->where('is_active', true)
            ->count();

        return response()->json([
            'status' => 'success',
            'message' => 'Roster size limit updated',
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'roster_size_limit' => $team->roster_size_limit,
                'current_active_count' => $activeCount,
            ],
        ], 200);
    }

    /**
     * Generate invite link
     */
    public function generateInvite(Request $request, string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner, captain, or manager can generate invites
        $isOwner = $user->id === $team->created_by;
        $isCaptain = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();
        $isManager = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->exists();

        if (! $isOwner && ! $isCaptain && ! $isManager) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'role' => 'nullable|string|max:50',
            'expires_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $token = \App\Models\TeamInvite::generateToken();
        $invite = \App\Models\TeamInvite::create([
            'team_id' => $team->id,
            'token' => $token,
            'role' => $request->role ?? null,
            'created_by' => $user->id,
            'expires_at' => $request->expires_at ? \Carbon\Carbon::parse($request->expires_at) : null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Invite link generated',
            'invite' => [
                'id' => $invite->id,
                'team_id' => $invite->team_id,
                'token' => $invite->token,
                'role' => $invite->role,
                'invite_url' => url("/teams/invite/{$invite->token}"),
                'expires_at' => $invite->expires_at,
                'created_at' => $invite->created_at,
            ],
        ], 201);
    }

    /**
     * Accept invite via token
     */
    public function acceptInvite(string $token)
    {
        $user = auth()->user();
        $invite = \App\Models\TeamInvite::where('token', $token)->first();

        if (! $invite) {
            return response()->json(['status' => 'error', 'message' => 'Invalid invite token'], 404);
        }

        if (! $invite->isValid()) {
            if ($invite->isUsed()) {
                return response()->json(['status' => 'error', 'message' => 'Invite token has already been used'], 409);
            }
            if ($invite->isExpired()) {
                return response()->json(['status' => 'error', 'message' => 'Invite token has expired'], 409);
            }
        }

        // Check if user is already in any team
        $existingMembership = TeamMember::where('user_id', $user->id)->first();
        if ($existingMembership) {
            return response()->json(['status' => 'error', 'message' => 'You are already a member of another team'], 409);
        }

        // Check if user is already in this team
        $alreadyMember = TeamMember::where('team_id', $invite->team_id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyMember) {
            $invite->update(['used_at' => now(), 'used_by' => $user->id]);
            return response()->json(['status' => 'error', 'message' => 'You are already a member of this team'], 409);
        }

        // Create team member
        $member = TeamMember::create([
            'team_id' => $invite->team_id,
            'user_id' => $user->id,
            'role' => $invite->role ?? 'member',
            'joined_at' => now(),
            'is_active' => true,
            'roster_status' => 'active',
        ]);

        // Mark invite as used
        $invite->update([
            'used_at' => now(),
            'used_by' => $user->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Invite accepted successfully',
            'member' => $member,
            'invite' => [
                'id' => $invite->id,
                'used_at' => $invite->used_at,
                'used_by' => $invite->used_by,
            ],
        ], 201);
    }

    /**
     * List active invites for a team
     */
    public function listInvites(string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner, captain, or manager can view invites
        $isOwner = $user->id === $team->created_by;
        $isCaptain = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();
        $isManager = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->exists();

        if (! $isOwner && ! $isCaptain && ! $isManager) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $invites = \App\Models\TeamInvite::where('team_id', $team->id)
            ->with('creator:id,username')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($invite) {
                return [
                    'id' => $invite->id,
                    'token' => $invite->token,
                    'role' => $invite->role,
                    'invite_url' => url("/teams/invite/{$invite->token}"),
                    'expires_at' => $invite->expires_at,
                    'created_by' => [
                        'id' => $invite->creator->id ?? null,
                        'username' => $invite->creator->username ?? null,
                    ],
                    'created_at' => $invite->created_at,
                    'used_at' => $invite->used_at,
                    'is_expired' => $invite->isExpired(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'invites' => $invites,
        ]);
    }

    /**
     * Revoke invite
     */
    public function revokeInvite(string $teamId, string $inviteId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner, captain, or manager can revoke invites
        $isOwner = $user->id === $team->created_by;
        $isCaptain = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();
        $isManager = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->exists();

        if (! $isOwner && ! $isCaptain && ! $isManager) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $invite = \App\Models\TeamInvite::where('team_id', $team->id)
            ->where('id', $inviteId)
            ->first();

        if (! $invite) {
            return response()->json(['status' => 'error', 'message' => 'Invite not found'], 404);
        }

        $invite->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Invite revoked successfully',
        ]);
    }

    /**
     * Upload certification document
     */
    public function uploadCertification(Request $request, string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner or captain can upload certification
        $isOwner = $user->id === $team->created_by;
        $isCaptain = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->exists();

        if (! $isOwner && ! $isCaptain) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'certification_document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        // Delete old document if exists
        if ($team->certification_document) {
            Storage::disk('public')->delete($team->certification_document);
        }

        $file = $request->file('certification_document');
        $fileName = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('certifications', $fileName, 'public');

        $team->update([
            'certification_document' => $path,
            'certification_status' => 'pending',
            'certification_verified_at' => null,
            'certification_verified_by' => null,
            'certification_ai_confidence' => null,
            'certification_ai_notes' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Certification document uploaded',
            'team' => [
                'id' => $team->id,
                'certification_document' => asset('storage/' . $team->certification_document),
                'certification_status' => $team->certification_status,
            ],
        ], 200);
    }

    /**
     * Trigger AI verification
     */
    public function verifyCertificationAI(string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        if (! $team->certification_document) {
            return response()->json(['status' => 'error', 'message' => 'No certification document uploaded'], 404);
        }

        // Basic AI verification simulation
        // In production, integrate with OCR/Image recognition API
        $documentPath = storage_path('app/public/' . $team->certification_document);
        
        if (!file_exists($documentPath)) {
            return response()->json(['status' => 'error', 'message' => 'Document file not found'], 404);
        }

        // Simulate AI verification
        // TODO: Integrate with actual OCR service (Google Vision, AWS Textract, Tesseract)
        $confidence = 0.85; // Simulated confidence score
        $notes = "Document analyzed. Contains Philippine pro league certification patterns. Requires admin review for final verification.";

        $team->update([
            'certification_status' => 'under_review',
            'certification_ai_confidence' => $confidence,
            'certification_ai_notes' => $notes,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'AI verification completed',
            'verification' => [
                'status' => $team->certification_status,
                'confidence' => $team->certification_ai_confidence,
                'notes' => $team->certification_ai_notes,
            ],
        ], 200);
    }

    /**
     * Get certification status
     */
    public function getCertificationStatus(string $teamId)
    {
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'certification' => [
                'certified' => $team->certified,
                'certification' => $team->certification,
                'certification_document' => $team->certification_document ? asset('storage/' . $team->certification_document) : null,
                'certification_status' => $team->certification_status,
                'certification_verified_at' => $team->certification_verified_at,
                'certification_ai_confidence' => $team->certification_ai_confidence,
                'certification_ai_notes' => $team->certification_ai_notes,
                'verified_by' => $team->certificationVerifier ? [
                    'id' => $team->certificationVerifier->id,
                    'username' => $team->certificationVerifier->username,
                ] : null,
            ],
        ]);
    }

    /**
     * Get team events
     */
    public function getTeamEvents(string $teamId)
    {
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        $eventTeams = \App\Models\EventTeam::where('team_id', $teamId)
            ->with(['event.venue', 'event.facility'])
            ->get();

        $events = $eventTeams->map(function ($eventTeam) {
            $event = $eventTeam->event;
            return [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'date' => $event->date,
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
                'sport' => $event->sport,
                'venue' => $event->venue ? [
                    'id' => $event->venue->id,
                    'name' => $event->venue->name,
                ] : null,
                'status' => $event->status ?? 'upcoming',
                ];
        });

        return response()->json([
            'status' => 'success',
            'events' => $events,
        ]);
    }

    /**
     * Leave team
     */
    public function leaveTeam(string $teamId)
    {
        $user = auth()->user();
        $team = Team::find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        $member = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'You are not a member of this team'], 404);
        }

        // Prevent owner from leaving (must transfer ownership first)
        if ($user->id === $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Owner cannot leave team. Transfer ownership first.'], 403);
        }

        // Mark member as left instead of deleting
        $member->is_active = false;
        $member->roster_status = 'left';
        $member->removed_at = now();
        $member->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Left team successfully',
            'member' => $member,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = auth()->user();
        $team = Team::find($id);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Only owner can delete team
        if ($user->id !== $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden - only owner can delete team'], 403);
        }

        // Delete team photo if exists
        if ($team->team_photo) {
            Storage::disk('public')->delete($team->team_photo);
        }

        // Delete certification document if exists
        if ($team->certification_document) {
            Storage::disk('public')->delete($team->certification_document);
        }

        $team->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Team deleted successfully',
        ]);
    }

    /**
     * GET /api/teams/my
     */
    public function myTeams(Request $request)
    {
        $user = auth()->user();

        $teams = Team::with(['sport','members.user','creator'])
            ->where('created_by', $user->id)
            ->orWhereHas('members', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->get();

        return response()->json(['status' => 'success', 'teams' => $teams], 200);
    }
}
