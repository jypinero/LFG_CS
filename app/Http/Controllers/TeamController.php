<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\Team;
use App\Models\TeamMember;

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
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
