<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User; // ADDED
use Illuminate\Support\Facades\DB; // ADDED

class TeamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $teams = Team::all()->map(function ($team) {
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

        // ensure role length and safe create, catch DB errors (e.g. enum/length)
        $role = $request->role ? substr($request->role, 0, 50) : 'member';

        try {
            $member = TeamMember::create([
                'team_id' => $team->id,
                'user_id' => $newUser->id,
                'role' => $role,
                'joined_at' => now(),
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

        // Only owner can remove members
        if ($user->id !== $team->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $member = TeamMember::where('team_id', $team->id)
            ->where('id', $memberId)
            ->first();

        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Team member not found'], 404);
        }

        // Prevent owner from removing themselves
        if ($member->user_id == $user->id) {
            return response()->json(['status' => 'error', 'message' => 'Owner cannot remove themselves'], 403);
        }

        $member->delete();

        return response()->json(['status'=>'success','message'=>'Member removed'], 200);
    }

    public function members(string $teamId)
    {
        $team = Team::with('creator')->find($teamId);
        if (! $team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        $members = TeamMember::where('team_id', $team->id)
            ->with('user:id,username,email')
            ->get()
            ->map(function ($member) {
                return [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'username' => $member->user->username ?? null,
                    'email' => $member->user->email ?? null,
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
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Check if user is already in any team
        $existingMembership = TeamMember::where('user_id', $user->id)->first();
        if ($existingMembership) {
            if ($existingMembership->team_id == $team->id) {
                return response()->json(['status' => 'error', 'message' => 'You are already on this team'], 409);
            }
            return response()->json(['status' => 'error', 'message' => 'You are already a member of another team'], 409);
        }

        // Check if user already requested
        $pending = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('role', 'pending')
            ->first();

        if ($pending) {
            return response()->json(['status' => 'error', 'message' => 'You have already requested to join this team'], 409);
        }

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'pending',
            'joined_at' => now(),
        ]);

        // Send notification to team owner
        $ownerId = $team->created_by;
        $notif = \App\Models\Notification::create([
            'type' => 'team_join_request',
            'data' => [
                'message' => $user->username . ' requested to join your team: ' . $team->name,
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

        // Find the notification for this join request
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

        // Log the action event
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

            // Update notification state
            $userNotification->action_state = $request->action;
            $userNotification->is_read = false; // Optionally mark unread for requester
            $userNotification->save();
        }

        if ($request->action === 'accept') {
            $member->role = 'member';
            $member->save();
            return response()->json(['status'=>'success','message'=>'Request accepted','member'=>$member], 200);
        } else {
            $member->delete();
            return response()->json(['status'=>'success','message'=>'Request declined'], 200);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
