<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Tournament;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use App\Models\EventGame;
use App\Models\UserNotification;
use App\Models\Notification;

class FinalTournamentController extends Controller
{
    // Create Tournament (uses all Tournament fillable fields)
    public function storeTournament(Request $request)
    {
        // Parse JSON strings from FormData before validation
        // FormData sends JSON as strings, so we need to decode them
        if ($request->has('settings') && is_string($request->input('settings'))) {
            $decoded = json_decode($request->input('settings'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $request->merge(['settings' => $decoded]);
            } else {
                $request->merge(['settings' => []]);
            }
        }
        
        if ($request->has('required_documents') && is_string($request->input('required_documents'))) {
            $decoded = json_decode($request->input('required_documents'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $request->merge(['required_documents' => $decoded]);
            } else {
                $request->merge(['required_documents' => []]);
            }
        }
        
        // Convert '1'/'0' strings to boolean for requires_documents (FormData sends strings)
        if ($request->has('requires_documents')) {
            $value = $request->input('requires_documents');
            if (is_string($value)) {
                $request->merge(['requires_documents' => $value === '1' || $value === 'true']);
            }
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:10240',
            'description' => 'nullable|string',
            'location' => 'nullable|string',
            'type' => 'nullable|string',
            'tournament_type' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'registration_deadline' => 'nullable|date',
            'status' => 'nullable|string',
            'requires_documents' => 'nullable|boolean',
            'required_documents' => 'nullable|array',
            'settings' => 'nullable|array',
            'max_teams' => 'nullable|integer',
            'min_teams' => 'nullable|integer',
            'registration_fee' => 'nullable|numeric',
            'rules' => 'nullable|string',
            'rulebook_file' => 'nullable|file|mimes:pdf|max:20480',
            'prizes' => 'nullable|string',
        ]);

        // Handle uploaded photo file (store in public disk under 'tournaments')
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('tournaments', 'public');
            $data['photo'] = $path;
        }

        // Handle uploaded rulebook PDF file
        if ($request->hasFile('rulebook_file')) {
            $path = $request->file('rulebook_file')->store('tournaments/rulebooks', 'public');
            $data['rulebook_file'] = $path;
        }

        $data['created_by'] = auth()->id();

        $tournament = Tournament::create($data);

        $tournamentArray = $tournament->fresh()->toArray();
        $tournamentArray['photo_url'] = $tournament->photo ? Storage::url($tournament->photo) : null;
        $tournamentArray['rulebook_url'] = $tournament->rulebook_file ? Storage::url($tournament->rulebook_file) : null;

        return response()->json(['status' => 'success', 'tournament' => $tournamentArray], 201);
    }

    // Update Tournament (uses same validation as storeTournament, except name is 'sometimes')
    public function updateTournament(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        $tournament = Tournament::find($id);
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Only creator can update
        $isCreator = $tournament->created_by === $user->id;
        if (!$isCreator) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // Parse JSON strings from FormData before validation
        // FormData sends JSON as strings, so we need to decode them
        if ($request->has('settings') && is_string($request->input('settings'))) {
            $decoded = json_decode($request->input('settings'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $request->merge(['settings' => $decoded]);
            } else {
                $request->merge(['settings' => []]);
            }
        }
        
        if ($request->has('required_documents') && is_string($request->input('required_documents'))) {
            $decoded = json_decode($request->input('required_documents'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $request->merge(['required_documents' => $decoded]);
            } else {
                $request->merge(['required_documents' => []]);
            }
        }
        
        // Convert '1'/'0' strings to boolean for requires_documents (FormData sends strings)
        if ($request->has('requires_documents')) {
            $value = $request->input('requires_documents');
            if (is_string($value)) {
                $request->merge(['requires_documents' => $value === '1' || $value === 'true']);
            }
        }

        // Use same validation as storeTournament, but name is 'sometimes' instead of 'required'
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:10240',
            'description' => 'nullable|string',
            'location' => 'nullable|string',
            'type' => 'nullable|string',
            'tournament_type' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'registration_deadline' => 'nullable|date',
            'status' => 'nullable|string',
            'requires_documents' => 'nullable|boolean',
            'required_documents' => 'nullable|array',
            'settings' => 'nullable|array',
            'max_teams' => 'nullable|integer',
            'min_teams' => 'nullable|integer',
            'registration_fee' => 'nullable|numeric',
            'rules' => 'nullable|string',
            'rulebook_file' => 'nullable|file|mimes:pdf|max:20480',
            'prizes' => 'nullable|string',
        ]);

        // Handle uploaded photo file (same as storeTournament)
        if ($request->hasFile('photo')) {
            // Delete old photo if exists
            if (!empty($tournament->photo)) {
                try {
                    Storage::disk('public')->delete($tournament->photo);
                } catch (\Throwable $ex) {}
            }
            $path = $request->file('photo')->store('tournaments', 'public');
            $data['photo'] = $path;
        }

        // Handle uploaded rulebook PDF file
        if ($request->hasFile('rulebook_file')) {
            // Delete old rulebook if exists
            if (!empty($tournament->rulebook_file)) {
                try {
                    Storage::disk('public')->delete($tournament->rulebook_file);
                } catch (\Throwable $ex) {}
            }
            $path = $request->file('rulebook_file')->store('tournaments/rulebooks', 'public');
            $data['rulebook_file'] = $path;
        }

        $tournament->update($data);

        $tournamentArray = $tournament->fresh()->toArray();
        $tournamentArray['photo_url'] = $tournament->photo ? Storage::url($tournament->photo) : null;
        $tournamentArray['rulebook_url'] = $tournament->rulebook_file ? Storage::url($tournament->rulebook_file) : null;

        return response()->json(['status' => 'success', 'tournament' => $tournamentArray], 200);
    }

    // Create Sub-Event using existing Event table (populate all Event fillable fields)
    public function storeSubEvent(Request $request, $tournamentId)
    {
        $tournament = Tournament::findOrFail($tournamentId);

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sport' => 'required|exists:sports,name',
            'event_type' => 'required|in:free for all,team vs team,tournament,multisport',
            'venue_id' => 'required|exists:venues,id',
            'facility_id' => 'required|exists:facilities,id',
            'slots' => 'nullable|integer|min:1',
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'end_date' => 'nullable|date',
            'end_date_start_time' => 'nullable|date_format:H:i:s',
            'end_date_end_time' => 'nullable|date_format:H:i:s|after:end_date_start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Block creation if venue is closed
        $venue = \App\Models\Venue::find($request->venue_id);
        if ($venue && $venue->is_closed) {
            return response()->json([
                'status' => 'error',
                'message' => 'This venue is closed and not accepting new events.'
            ], 403);
        }

        // Prevent double booking: same venue + facility, same date, overlapping times
        $conflict = Event::where('venue_id', $request->venue_id)
            ->where('facility_id', $request->facility_id)
            ->where('date', $request->date)
            ->where(function($q) use ($request) {
                $q->where('start_time', '<', $request->end_time)
                  ->where('end_time', '>', $request->start_time);
            })
            ->whereNull('cancelled_at')
            ->exists();

        if ($conflict) {
            return response()->json([
                'status' => 'error',
                'message' => 'Venue and facility are already booked for the selected date and time.'
            ], 409);
        }

        $user = auth()->user();

        // Determine slots value: use request value, fallback to tournament max_teams, or default to 10
        $slots = $request->slots ?? $tournament->max_teams ?? 10;

        // prepare event payload — store canonical event_type = 'tournament' and persist sub-type if column exists
        $payload = [
            'name' => $request->name,
            'description' => $request->description,
            'sport' => $request->sport,
            'venue_id' => $request->venue_id,
            'facility_id' => $request->facility_id,
            'slots' => $slots,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'end_date' => $request->end_date ?? null,
            'end_date_start_time' => $request->end_date_start_time ?? null,
            'end_date_end_time' => $request->end_date_end_time ?? null,
            'created_by' => $user->id,
            'checkin_code' => method_exists($this, 'generateCheckinCode') ? $this->generateCheckinCode() : null,
            'is_approved' => false,
            'approved_at' => null,
            'tournament_id' => $tournament->id,
            'is_tournament_game' => true,
        ];

        // persist sub-event type into a dedicated column if available, otherwise leave event_type as 'tournament'
        $subType = strtolower(str_replace(['_', '-'], ' ', $request->event_type));
        if (\Schema::hasColumn('events', 'sub_event_type')) {
            $payload['event_type'] = 'tournament';
            $payload['sub_event_type'] = str_replace(' ', '_', $subType);
        } else {
            // fallback: store original event_type (legacy)
            $payload['event_type'] = $request->event_type;
        }

        $event = Event::create($payload);

        // Create booking for venue approval
        \App\Models\Booking::create([
            'venue_id' => $event->venue_id,
            'user_id' => $user->id,
            'event_id' => $event->id,
            'sport' => $event->sport,
            'date' => $event->date,
            'start_time' => $event->start_time,
            'end_time' => $event->end_time,
            'purpose' => $request->purpose ?? 'Event: ' . $event->name,
            'status' => 'pending',
        ]);

        // Handle team vs team events
        if ($request->event_type === 'team vs team') {
            $teamIds = $request->team_ids;
            $enrolledParticipants = [];

            // foreach ($teamIds as $teamId) {
            //     // create EventTeam if model exists
            //     if (class_exists(\App\Models\EventTeam::class)) {
            //         \App\Models\EventTeam::create([
            //             'event_id' => $event->id,
            //             'team_id' => $teamId,
            //         ]);
            //     }

            //     $teamMembers = TeamMember::where('team_id', $teamId)->get();
            //     foreach ($teamMembers as $member) {
            //         $participant = EventParticipant::create([
            //             'event_id' => $event->id,
            //             'user_id' => $member->user_id,
            //             'team_id' => $teamId,
            //             'status' => 'confirmed',
            //             'tournament_id' => $event->tournament_id,
            //         ]);
            //         $enrolledParticipants[] = $participant;
            //     }
            // }

            // create group chat if method exists
            if (method_exists($this, 'createEventGroupChat')) {
                $this->createEventGroupChat($event);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Team vs team event created successfully',
                'event' => $event->fresh(),
                'teams' => $teamIds,
                'enrolled_participants' => $enrolledParticipants,
                'approval_status' => 'pending',
            ], 201);
        }

       
        if (method_exists($this, 'createEventGroupChat')) {
            $this->createEventGroupChat($event);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Event created successfully',
            'event' => $event->fresh(),
            'approval_status' => 'pending',
        ], 201);
    }

    // Register (individual or team) to a tournament sub-event (uses Event & EventParticipant)
    public function register(Request $request, $eventId)
    {
        $user = auth()->user();
        if (! $user) return response()->json(['status' => 'error','message'=>'Unauthenticated'], 401);

        $event = Event::findOrFail($eventId);
        $tournament = $event->tournament ?? \App\Models\Tournament::find($event->tournament_id);

        // If tournament requires documents, validate and store uploaded files
        $storedDocuments = null;
        if ($tournament && $tournament->requires_documents) {
            $docRules = [
                'documents' => 'required|array|min:1',
                'documents.*' => 'file|mimes:pdf,jpg,png,jpeg|max:5120',
            ];

            // For team registration we still accept a single 'documents' array (team-level docs)
            // Caller (Postman) must send multipart/form-data with documents[]'
            $validator = \Validator::make($request->all(), $docRules);
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Registration requires documents',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->hasFile('documents')) {
                $stored = [];
                foreach ($request->file('documents') as $f) {
                    $stored[] = $f->store('tournament_documents', 'public');
                }
                $storedDocuments = $stored;
            }
        }

        // Free-for-all (individual)
        if ($event->event_type === 'free_for_all' || stripos($event->event_type, 'free') !== false) {
            // CHECK: user overlapping schedule
            $userConflict = EventParticipant::where('user_id', $user->id)
                ->whereHas('event', function($q) use ($event) {
                    $q->whereNull('cancelled_at')
                      ->where('date', $event->date)
                      ->where('start_time', '<', $event->end_time)
                      ->where('end_time', '>', $event->start_time);
                })->exists();

            if ($userConflict) {
                return response()->json(['status' => 'error','message'=>'You have another event that overlaps this schedule'], 409);
            }

            $count = EventParticipant::where('event_id', $event->id)->whereNotNull('user_id')->count();
            if ($event->slots && $count >= $event->slots) {
                return response()->json(['status' => 'error','message'=>'Event full'], 409);
            }

            if (EventParticipant::where('event_id', $event->id)->where('user_id', $user->id)->exists()) {
                return response()->json(['status' => 'error','message'=>'Already registered'], 409);
            }

            $payload = [
                'event_id' => $event->id,
                'user_id' => $user->id,
                'name' => $user->first_name . ' ' . $user->last_name,
                'tournament_id' => $event->tournament_id,
                'status' => 'pending',
                'registration_datetime' => Carbon::now(),
            ];
            if ($storedDocuments && \Schema::hasColumn('event_participants', 'documents')) {
                $payload['documents'] = json_encode($storedDocuments);
            }

            $p = EventParticipant::create($payload);

            return response()->json(['status' => 'success','participant' => $p->fresh()], 201);
        }

        // Team vs team (team registration)
        $v = $request->validate(['team_id' => 'required|integer|exists:teams,id']);
        $team = Team::findOrFail($v['team_id']);

        $isOwner = $team->owner_id == $user->id || $team->created_by == $user->id;
        $isCaptain = TeamMember::where('team_id', $team->id)->where('user_id', $user->id)->where('role', 'manager')->exists();

        if (! ($isOwner || $isCaptain)) {
            return response()->json(['status' => 'error','message'=>'Only team owner or manager can register'], 403);
        }

        // CHECK: registering user's overlapping schedule
        $userConflict = EventParticipant::where('user_id', $user->id)
            ->whereHas('event', function($q) use ($event) {
                $q->whereNull('cancelled_at')
                  ->where('date', $event->date)
                  ->where('start_time', '<', $event->end_time)
                  ->where('end_time', '>', $event->start_time);
            })->exists();

        if ($userConflict) {
            return response()->json(['status' => 'error','message'=>'You have another event that overlaps this schedule'], 409);
        }

        // CHECK: team overlapping schedule (team already registered in overlapping event)
        $teamConflict = EventParticipant::where('team_id', $team->id)
            ->whereHas('event', function($q) use ($event) {
                $q->whereNull('cancelled_at')
                  ->where('date', $event->date)
                  ->where('start_time', '<', $event->end_time)
                  ->where('end_time', '>', $event->start_time);
            })->exists();

        if ($teamConflict) {
            return response()->json(['status' => 'error','message'=>'This team has another event that overlaps the selected schedule'], 409);
        }

        if (EventParticipant::where('event_id', $event->id)->where('team_id', $team->id)->exists()) {
            return response()->json(['status' => 'error','message'=>'Team already registered'], 409);
        }
        
        // create team registration row
        $teamRegistrationPayload = [
            'event_id' => $event->id,
            'team_id' => $team->id,
            'team_name' => $team->name,
            'registered_by' => $user->id,
            'tournament_id' => $event->tournament_id,
            'status' => 'pending',
            'registration_datetime' => Carbon::now(),
        ];
        if ($storedDocuments && \Schema::hasColumn('event_participants', 'documents')) {
            $teamRegistrationPayload['documents'] = json_encode($storedDocuments);
        }

        $teamRegistration = EventParticipant::create($teamRegistrationPayload);

        // Ensure all team members are registered as participants (or linked) for the event
        $membersRegistered = [];
        $membersAlready = [];
        $teamMembers = TeamMember::where('team_id', $team->id)->get();

        foreach ($teamMembers as $member) {
            $exists = EventParticipant::where('event_id', $event->id)
                ->where('user_id', $member->user_id)
                ->exists();

            if ($exists) {
                $membersAlready[] = $member->user_id;
                EventParticipant::where('event_id', $event->id)
                    ->where('user_id', $member->user_id)
                    ->update(['team_id' => $team->id]);
                continue;
            }

            $pPayload = [
                'event_id' => $event->id,
                'user_id' => $member->user_id,
                'team_id' => $team->id,
                'team_name' => $team->name,
                'status' => 'pending',
                'tournament_id' => $event->tournament_id,
                'registration_datetime' => Carbon::now(),
            ];
            if ($storedDocuments && \Schema::hasColumn('event_participants', 'documents')) {
                $pPayload['documents'] = json_encode($storedDocuments);
            }

            $p = EventParticipant::create($pPayload);

            $membersRegistered[] = $p;
        }

        return response()->json([
            'status' => 'success',
            'participant' => $teamRegistration->fresh(),
            'members_registered_count' => count($membersRegistered),
            'members_registered' => $membersRegistered,
            'members_already_registered' => $membersAlready,
        ], 201);
    }

    // Fetch participants for a sub-event (returns full participant / team / member fields)
    public function participants(Request $request, $eventId)
    {
        $event = Event::with([
            'participants',
            'participants.user',
            'participants.team',
            'participants.team.members.user',
            'tournament'
        ])->findOrFail($eventId);

        // Get sort and search parameters
        $sortBy = $request->input('sort_by', 'registration_datetime');
        $sortOrder = $request->input('sort_order', 'desc');
        $dateFrom = $request->input('search_date_from');
        $dateTo = $request->input('search_date_to');

        // Get tournament type as primary source, fallback to event type
        $tournament = $event->tournament;
        $tournamentType = $tournament->tournament_type ?? null;
        
        // determine effective sub-type
        $subType = strtolower($event->sub_event_type ?? $event->event_type ?? '');
        
        // Use tournament_type if available, otherwise infer from event type
        $isFreeForAll = false;
        if ($tournamentType) {
            $isFreeForAll = (strtolower($tournamentType) === 'free for all');
        } else {
            $isFreeForAll = (strpos($subType, 'free') !== false || strpos($subType, 'free_for_all') !== false);
        }

        if ($isFreeForAll) {
            // Build query for participants with sorting and date filtering
            $participantsQuery = $event->participants()
                ->whereNotNull('user_id')
                ->whereIn('status', ['approved', 'confirmed', 'pending']);

            // Apply date filtering
            if ($dateFrom) {
                $participantsQuery->whereDate('registration_datetime', '>=', $dateFrom);
            }
            if ($dateTo) {
                $participantsQuery->whereDate('registration_datetime', '<=', $dateTo);
            }

            // Apply sorting
            $validSortFields = ['registration_datetime', 'created_at', 'status'];
            $sortBy = in_array($sortBy, $validSortFields) ? $sortBy : 'registration_datetime';
            $sortOrder = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';
            $participantsQuery->orderBy($sortBy, $sortOrder);

            $participants = $participantsQuery->with('user')->get();

            // Filter by approved/confirmed status
            $users = $participants->map(function($p){
                $u = $p->user;
                $result = [
                    'id' => $p->id,
                    'user_id' => $u->id ?? null,
                    'first_name' => $u->first_name ?? null,
                    'last_name'  => $u->last_name ?? null,
                    'position'   => $p->position ?? ($u->position ?? null),
                    'status' => $p->status ?? null,
                    'registration_datetime' => $p->registration_datetime ? $p->registration_datetime->format('Y-m-d H:i:s') : null,
                ];
                
                // Include documents if column exists and documents are present
                if (Schema::hasColumn('event_participants', 'documents') && $p->documents) {
                    $result['documents'] = json_decode($p->documents, true);
                } else {
                    $result['documents'] = null;
                }
                
                return $result;
            })->values();

            return response()->json([
                'type' => 'free_for_all',
                'tournament_type' => $tournamentType ?? 'free for all',
                'participants' => $users,
                'participants_count' => $users->count(),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ]);
        }

        // team vs team: return team-level registrations only (team_id but no user_id)
        // These are the registrations that should be approved, not individual member records
        // Filter by approved/confirmed status
        $teamParticipantsQuery = $event->participants()
            ->whereNotNull('team_id')
            ->whereNull('user_id')
            ->whereIn('status', ['approved', 'confirmed', 'pending']);

        // Apply date filtering
        if ($dateFrom) {
            $teamParticipantsQuery->whereDate('registration_datetime', '>=', $dateFrom);
        }
        if ($dateTo) {
            $teamParticipantsQuery->whereDate('registration_datetime', '<=', $dateTo);
        }

        // Apply sorting
        $validSortFields = ['registration_datetime', 'created_at', 'status'];
        $sortBy = in_array($sortBy, $validSortFields) ? $sortBy : 'registration_datetime';
        $sortOrder = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';
        $teamParticipantsQuery->orderBy($sortBy, $sortOrder);

        $teamParticipants = $teamParticipantsQuery->with(['team.members.user'])->get();

        $teams = $teamParticipants->map(function($teamParticipant) {
            $team = $teamParticipant->team;
            
            // Build members list from Team->members relationship for display
            $members = $team->members->map(function($member) {
                $u = $member->user;
                return [
                    'first_name' => $u->first_name ?? null,
                    'last_name'  => $u->last_name ?? null,
                    'position'   => $member->position ?? ($member->role ?? null) ?? ($u->position ?? null),
                ];
            })->values();

            $teamData = [
                'id' => $teamParticipant->id,
                'team_id' => $team->id ?? null,
                'team_name' => $team->name ?? null,
                'status' => $teamParticipant->status ?? null,
                'registration_datetime' => $teamParticipant->registration_datetime ? $teamParticipant->registration_datetime->format('Y-m-d H:i:s') : null,
                'members' => $members,
            ];
            
            // Include documents from team-level registration record
            if (Schema::hasColumn('event_participants', 'documents') && $teamParticipant->documents) {
                $teamData['documents'] = json_decode($teamParticipant->documents, true);
            } else {
                $teamData['documents'] = null;
            }
            
            return $teamData;
        })->values();

        return response()->json([
            'type' => 'team_vs_team',
            'tournament_type' => $tournamentType ?? 'team vs team',
            'teams' => $teams,
            'participants_count' => $teams->count(),
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);
    }

    // List tournaments (use all Tournament fields)
    public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 5), 100);
        $page = (int) $request->input('page', 1);

        $query = Tournament::withCount('events')->orderByDesc('created_at');
        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $tournaments = $paginated->getCollection()->map(fn($t) => $t->toArray())->values();

        return response()->json([
            'status' => 'success',
            'tournaments' => $tournaments,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    // Show tournament and its sub-events (return full event fields)
    public function showTournament($id, Request $request)
    {
        $tournament = Tournament::findOrFail($id);

        $perPage = min((int) $request->input('per_page', 5), 100);
        $page = (int) $request->input('page', 1);

        $eventsQuery = Event::where('tournament_id', $tournament->id)
            ->orderBy('date')
            ->orderBy('start_time');

        $paginatedEvents = $eventsQuery->paginate($perPage, ['*'], 'page', $page);

        $subEvents = $paginatedEvents->getCollection()->map(fn($e) => $e->toArray())->values();

        return response()->json([
            'status' => 'success',
            'tournament' => $tournament->toArray(),
            'sub_events' => $subEvents,
            'pagination' => [
                'current_page' => $paginatedEvents->currentPage(),
                'last_page' => $paginatedEvents->lastPage(),
                'per_page' => $paginatedEvents->perPage(),
                'total' => $paginatedEvents->total(),
            ],
        ]);
    }

    public function updateParticipantStatus(Request $request, $participantId)
    {
        $user = auth()->user();
        if (! $user) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);

        $data = $request->validate([
            'status' => 'required|in:approved,declined',
            'reason' => 'nullable|string|max:1000',
        ]);

        $participant = EventParticipant::with('event.tournament')->findOrFail($participantId);
        $event = $participant->event;
        $tournament = $event->tournament ?? null;

        // authorization: event creator or tournament creator
        $isAllowed = ($event && $event->created_by == $user->id) || ($tournament && $tournament->created_by == $user->id);
        if (! $isAllowed) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        DB::transaction(function() use ($participant, $data, $user, $event) {
            $now = Carbon::now();
            $newStatus = $data['status'];

            // helper to set common processed/approved/declined fields on a model
            $applyMeta = function($m) use ($user, $now, $newStatus, $data) {
                if (Schema::hasColumn('event_participants', 'processed_by')) $m->processed_by = $user->id;
                if (Schema::hasColumn('event_participants', 'processed_at')) $m->processed_at = $now;

                $m->status = $newStatus;

                if ($newStatus === 'approved') {
                    if (Schema::hasColumn('event_participants', 'approved_by')) $m->approved_by = $user->id;
                    if (Schema::hasColumn('event_participants', 'approved_at')) $m->approved_at = $now;
                } else {
                    if (Schema::hasColumn('event_participants', 'declined_by')) $m->declined_by = $user->id;
                    if (Schema::hasColumn('event_participants', 'declined_at')) $m->declined_at = $now;
                    if (! empty($data['reason']) && Schema::hasColumn('event_participants', 'decline_reason')) {
                        $m->decline_reason = $data['reason'];
                    }
                    if (! empty($data['reason']) && Schema::hasColumn('event_participants', 'notes') && ! Schema::hasColumn('event_participants', 'decline_reason')) {
                        $m->notes = $data['reason'];
                    }
                }
            };

            // If this is a team-registration row (team_id present and user_id null) and tournament is team vs team,
            // apply status to all team members for this event
            $isTeamParent = $participant->team_id && empty($participant->user_id);
            if ($isTeamParent) {
                $teamId = $participant->team_id;

                // update parent registration row
                $applyMeta($participant);
                $participant->save();

                // update all participant rows that belong to the same team for the event
                $teamParticipants = EventParticipant::where('event_id', $participant->event_id)
                    ->where('team_id', $teamId)
                    ->get();

                $teamParticipants->each(function($tp) use ($applyMeta) {
                    $applyMeta($tp);
                    $tp->save();
                });

                // prepare notification for team members (collect user_ids)
                $userIds = $teamParticipants->pluck('user_id')->filter()->unique()->values()->toArray();
                if (! empty($userIds)) {
                    $notif = Notification::create([
                        'type' => 'event_participant_status',
                        'data' => [
                            'event_id' => $participant->event_id,
                            'team_id' => $teamId,
                            'status' => $newStatus,
                            'message' => $newStatus === 'approved' ? 'Your team has been accepted to the event' : 'Your team registration was declined',
                        ],
                        'created_by' => auth()->id(),
                        'created_at' => $now,
                    ]);

                    foreach ($userIds as $uid) {
                        UserNotification::create([
                            'notification_id' => $notif->id,
                            'user_id' => $uid,
                            'is_read' => false,
                            'created_at' => $now,
                        ]);
                    }
                }

            } else {
                // single participant (user-specific)
                $applyMeta($participant);
                $participant->save();

                // notify the single user
                if ($participant->user_id) {
                    $notif = Notification::create([
                        'type' => 'event_participant_status',
                        'data' => [
                            'event_id' => $participant->event_id,
                            'participant_id' => $participant->id,
                            'status' => $newStatus,
                            'message' => $newStatus === 'approved' ? 'Your registration was approved' : 'Your registration was declined',
                        ],
                        'created_by' => auth()->id(),
                        'created_at' => $now,
                    ]);

                    UserNotification::create([
                        'notification_id' => $notif->id,
                        'user_id' => $participant->user_id,
                        'is_read' => false,
                        'created_at' => $now,
                    ]);
                }
            }
        });

        return response()->json(['status' => 'success', 'participant' => $participant->fresh()], 200);
    }

    public function cancelSubEvent(Request $request, $tournamentId, $eventId)
    {
        $user = auth()->user();
        if (! $user) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);

        $data = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        // Explicitly get eventId from route to ensure correct parameter binding
        $eventId = $request->route('eventId') ?? $eventId;
        
        // Direct database query to get raw value, bypassing any model transformations
        $eventFromDb = DB::table('events')->where('id', $eventId)->first();
        
        if (!$eventFromDb) {
            return response()->json(['status' => 'error', 'message' => 'Event not found'], 404);
        }
        
        // Get event model for other operations
        $event = Event::with('participants')->findOrFail($eventId);
        
        // authorization: only the event creator can cancel the event
        // Use direct DB value first, then fallback to model methods
        $eventCreatorId = $eventFromDb->created_by ?? $event->getOriginal('created_by') ?? $event->getAttribute('created_by');
        $userId = $user->id;
        
        // Also check raw attributes array as fallback
        if (is_null($eventCreatorId) && isset($event->attributes['created_by'])) {
            $eventCreatorId = $event->attributes['created_by'];
        }
        
        // Log for debugging with both direct DB query and model values
        Log::info('Cancel Sub Event Authorization Check', [
            'route_tournament_id' => $tournamentId,
            'route_event_id' => $eventId,
            'request_route_eventId' => $request->route('eventId'),
            'event_id_from_db' => $eventFromDb->id,
            'event_created_by_from_db' => $eventFromDb->created_by,
            'event_created_by_original' => $event->getOriginal('created_by'),
            'event_created_by_accessor' => $event->created_by,
            'event_created_by_used' => $eventCreatorId,
            'event_created_by_type' => gettype($eventCreatorId),
            'user_id' => $userId,
            'user_id_type' => gettype($userId),
            'strict_match' => $eventCreatorId === $userId,
            'loose_match' => $eventCreatorId == $userId,
            'int_match' => (int)$eventCreatorId === (int)$userId,
            'string_match' => (string)$eventCreatorId === (string)$userId,
        ]);
        
        // Try multiple comparison methods to ensure we catch the match
        $isAuthorized = false;
        
        if ($eventCreatorId && $userId) {
            // Try strict comparison first
            if ($eventCreatorId === $userId) {
                $isAuthorized = true;
            }
            // Try loose comparison
            elseif ($eventCreatorId == $userId) {
                $isAuthorized = true;
            }
            // Try integer comparison
            elseif ((int)$eventCreatorId === (int)$userId) {
                $isAuthorized = true;
            }
            // Try string comparison
            elseif ((string)$eventCreatorId === (string)$userId) {
                $isAuthorized = true;
            }
        }
        
        if (!$isAuthorized) {
            return response()->json([
                'status' => 'error', 
                'message' => 'Forbidden',
                'debug' => [
                    'route_event_id' => $eventId,
                    'event_id_from_db' => $eventFromDb->id,
                    'event_created_by_from_db' => $eventFromDb->created_by,
                    'event_created_by_original' => $event->getOriginal('created_by'),
                    'event_created_by_accessor' => $event->created_by,
                    'event_created_by_used' => $eventCreatorId,
                    'current_user_id' => $userId,
                    'reason' => $eventCreatorId ? 'User ID does not match event creator' : 'Event has no creator'
                ]
            ], 403);
        }

        DB::transaction(function() use ($event, $data, $user) {
            $now = Carbon::now();

            // mark event cancelled
            $event->cancelled_at = $now;
            if (Schema::hasColumn('events', 'cancelled_by')) $event->cancelled_by = $user->id;
            if (! empty($data['reason']) && Schema::hasColumn('events', 'cancel_reason')) {
                $event->cancel_reason = $data['reason'];
            }
            if (Schema::hasColumn('events', 'game_status')) $event->game_status = 'cancelled';
            $event->save();

            // update booking(s)
            $bookingUpdate = ['status' => 'cancelled', 'updated_at' => $now];
            if (Schema::hasColumn('bookings', 'cancelled_by')) $bookingUpdate['cancelled_by'] = $user->id;
            if (Schema::hasColumn('bookings', 'cancelled_at')) $bookingUpdate['cancelled_at'] = $now;
            \App\Models\Booking::where('event_id', $event->id)->update($bookingUpdate);

            // update participants (team parent + members)
            $participants = EventParticipant::where('event_id', $event->id)->get();
            $userIds = [];

            foreach ($participants as $p) {
                $p->status = 'cancelled';
                if (Schema::hasColumn('event_participants', 'processed_by')) $p->processed_by = $user->id;
                if (Schema::hasColumn('event_participants', 'processed_at')) $p->processed_at = $now;
                if (Schema::hasColumn('event_participants', 'cancelled_by')) $p->cancelled_by = $user->id;
                if (Schema::hasColumn('event_participants', 'cancelled_at')) $p->cancelled_at = $now;
                if (! empty($data['reason']) && Schema::hasColumn('event_participants', 'notes')) {
                    $p->notes = ($p->notes ?? '') . ' Cancellation reason: ' . $data['reason'];
                }
                $p->save();

                if (! empty($p->user_id)) $userIds[] = $p->user_id;
            }

            $userIds = array_values(array_unique(array_filter($userIds)));

            // create notification for affected users
            if (! empty($userIds)) {
                $notif = Notification::create([
                    'type' => 'event_cancelled',
                    'data' => [
                        'event_id' => $event->id,
                        'message' => 'A sub-event has been cancelled',
                        'reason' => $data['reason'] ?? null,
                    ],
                    'created_by' => $user->id,
                    'created_at' => $now,
                ]);

                foreach ($userIds as $uid) {
                    UserNotification::create([
                        'notification_id' => $notif->id,
                        'user_id' => $uid,
                        'is_read' => false,
                        'created_at' => $now,
                    ]);
                }
            }
        });

        return response()->json(['status' => 'success', 'message' => 'Sub-event cancelled'], 200);
    }

    // Store Event Game with bracket data (for tournament sub-event)
    public function storeEventGame(Request $request, $eventId)
    {
        $user = auth()->user();
        if (! $user) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);

        $event = Event::findOrFail($eventId);
        $tournament = $event->tournament;

        // Extract bracket data from the exported structure
        // Frontend sends: { tournament: {...}, bracket: {...} } OR { type, rounds, ... }
        $allData = $request->all();
        
        // Log raw request for debugging
        $rawContent = $request->getContent();
        $contentType = $request->header('Content-Type');
        
        // If request is empty or only has route parameters, try to get JSON from raw body
        if ((empty($allData) || count($allData) <= 1) && $rawContent) {
            $jsonData = json_decode($rawContent, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                $allData = $jsonData;
            }
        }
        
        // Also try json() method which handles JSON requests better
        if (empty($allData) || (!isset($allData['bracket']) && !isset($allData['type']))) {
            try {
                $jsonData = $request->json()->all();
                if (!empty($jsonData)) {
                    $allData = array_merge($allData, $jsonData);
                }
            } catch (\Exception $e) {
                // Not a JSON request, continue with $allData
            }
        }
        
        // If still empty, try to decode raw content as JSON
        if (empty($allData) && $rawContent) {
            $decoded = json_decode($rawContent, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $allData = $decoded;
            }
        }
        
        // Log received data for debugging
        \Log::info('Bracket data received', [
            'event_id' => $eventId,
            'request_keys' => array_keys($allData),
            'has_bracket_key' => isset($allData['bracket']),
            'has_type_key' => isset($allData['type']),
            'has_rounds_key' => isset($allData['rounds']),
            'content_type' => $contentType,
            'raw_content_length' => strlen($rawContent),
            'raw_content_preview' => substr($rawContent, 0, 200), // First 200 chars
            'all_data_preview' => array_slice($allData, 0, 5, true), // First 5 keys
        ]);
        
        // Extract the bracket object from the exported structure
        // Supports multiple formats (in priority order):
        // 1. Wrapped: { tournament: {...}, bracket: { type, rounds, ... } } - Full export format
        // 2. Direct bracket: { type, rounds, ... } - Simple import format
        // 3. Alternative keys: bracket_data, bracketData, etc.
        $bracketData = null;
        
        // FIRST: Check if bracket key exists (wrapped format - full export)
        // This handles: { tournament: {...}, bracket: {...} } OR { bracket: {...}, type: "...", isFinished: ... }
        if (isset($allData['bracket'])) {
            if (is_array($allData['bracket'])) {
                // Standard exported format: { tournament: {...}, bracket: {...} }
                // OR mixed format: { bracket: {...}, type: "...", isFinished: ... }
                // Extract just the bracket object, ignore root-level type/isFinished
                $bracketData = $allData['bracket'];
                
                // Log extraction for debugging
                \Log::info('Extracted bracket from bracket key', [
                    'bracket_keys' => array_keys($bracketData),
                    'has_type' => isset($bracketData['type']),
                    'has_rounds' => isset($bracketData['rounds']),
                ]);
            } elseif (is_string($allData['bracket'])) {
                // If bracket is a JSON string, decode it
                $decoded = json_decode($allData['bracket'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $bracketData = $decoded;
                }
            }
        }
        
        // SECOND: Check if data is directly the bracket structure (simple import format)
        // This handles: { type: "...", rounds: [...], ... }
        // Only check this if we didn't find a bracket key (to avoid conflicts)
        // IMPORTANT: Must check for 'rounds' key to ensure it's actually bracket data
        if (!$bracketData && isset($allData['type']) && isset($allData['rounds']) && is_array($allData['rounds'])) {
            // Direct bracket format - this is the format for simple imports
            $bracketData = $allData;
            
            \Log::info('Using direct bracket format', [
                'bracket_keys' => array_keys($bracketData),
            ]);
        }
        
        // THIRD: Check alternative key names
        $alternativeKeys = ['bracket_data', 'bracketData', 'data', 'payload', 'body'];
        foreach ($alternativeKeys as $key) {
            if (!$bracketData && isset($allData[$key]) && is_array($allData[$key])) {
                // Check if this alternative key contains bracket structure
                if (isset($allData[$key]['type']) && isset($allData[$key]['rounds'])) {
                    $bracketData = $allData[$key];
                    break;
                }
                // Or if it contains a bracket key
                if (isset($allData[$key]['bracket']) && is_array($allData[$key]['bracket'])) {
                    $bracketData = $allData[$key]['bracket'];
                    break;
                }
            }
        }
        
        // FOURTH: Try to find bracket in nested structure
        if (!$bracketData && isset($allData['bracket']['bracket']) && is_array($allData['bracket']['bracket'])) {
            $bracketData = $allData['bracket']['bracket'];
        }
        
        // FIFTH: Try to find bracket_data nested
        if (!$bracketData && isset($allData['bracket_data']['bracket']) && is_array($allData['bracket_data']['bracket'])) {
            $bracketData = $allData['bracket_data']['bracket'];
        }
        
        // SIXTH: Check if any field contains a JSON string that needs decoding
        if (!$bracketData) {
            foreach ($allData as $key => $value) {
                if (is_string($value) && strlen($value) > 10) {
                    // Try to decode as JSON
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        // Check if decoded data is bracket structure
                        if (isset($decoded['type']) && isset($decoded['rounds'])) {
                            $bracketData = $decoded;
                            break;
                        }
                        // Or if it contains bracket key
                        if (isset($decoded['bracket']) && is_array($decoded['bracket'])) {
                            $bracketData = $decoded['bracket'];
                            break;
                        }
                    }
                }
            }
        }
        
        // Final check: If bracketData has a nested 'bracket' key with rounds, extract it
        // This handles cases where the extraction didn't work correctly
        if ($bracketData && is_array($bracketData) && isset($bracketData['bracket']) && is_array($bracketData['bracket'])) {
            if (isset($bracketData['bracket']['rounds'])) {
                // The actual bracket data is nested inside
                \Log::info('Extracting nested bracket structure', [
                    'event_id' => $eventId,
                    'outer_keys' => array_keys($bracketData),
                    'inner_keys' => array_keys($bracketData['bracket']),
                ]);
                $bracketData = $bracketData['bracket'];
            }
        }
        
        // Validate bracket structure
        if (!$bracketData || !is_array($bracketData)) {
            \Log::warning('Invalid bracket data structure received - not an array', [
                'event_id' => $eventId,
                'received_data_keys' => array_keys($allData),
                'bracket_data_type' => $bracketData ? gettype($bracketData) : 'null',
                'raw_request' => $request->all(),
                'raw_content' => $request->getContent(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid bracket data structure. Expected: { tournament: {...}, bracket: { type, rounds, ... } } OR { type, rounds, ... }',
                'hint' => 'Make sure you are sending the bracket object with "type" and "rounds" fields',
                'debug' => [
                    'received_keys' => array_keys($allData),
                    'has_bracket' => isset($allData['bracket']),
                    'has_type' => isset($allData['type']),
                    'has_rounds' => isset($allData['rounds']),
                    'content_type' => $request->header('Content-Type'),
                    'bracket_data_type' => $bracketData ? gettype($bracketData) : 'null',
                    'sample_received_data' => array_slice($allData, 0, 3, true), // First 3 keys for debugging
                ]
            ], 400);
        }
        
        // Check if bracketData has a nested 'bracket' key (shouldn't happen, but handle it)
        if (isset($bracketData['bracket']) && is_array($bracketData['bracket']) && isset($bracketData['bracket']['rounds'])) {
            // If bracketData has a nested bracket with rounds, use that instead
            \Log::warning('Found nested bracket structure, extracting inner bracket', [
                'event_id' => $eventId,
                'outer_keys' => array_keys($bracketData),
            ]);
            $bracketData = $bracketData['bracket'];
        }
        
        // Check for required fields
        if (!isset($bracketData['type']) || !isset($bracketData['rounds'])) {
            \Log::warning('Invalid bracket data structure received - missing required fields', [
                'event_id' => $eventId,
                'bracket_data_keys' => array_keys($bracketData),
                'has_type' => isset($bracketData['type']),
                'has_rounds' => isset($bracketData['rounds']),
                'bracket_data_sample' => array_slice($bracketData, 0, 5, true), // First 5 keys
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid bracket data structure. Expected: { tournament: {...}, bracket: { type, rounds, ... } } OR { type, rounds, ... }',
                'hint' => 'Bracket data must contain "type" and "rounds" fields. Received keys: ' . implode(', ', array_keys($bracketData)),
                'debug' => [
                    'bracket_keys' => array_keys($bracketData),
                    'has_type' => isset($bracketData['type']),
                    'has_rounds' => isset($bracketData['rounds']),
                    'bracket_data_sample' => array_slice($bracketData, 0, 5, true),
                ]
            ], 400);
        }
        
        // Extract isFinished and winner from bracket data
        $isFinished = $bracketData['isFinished'] ?? false;
        $winner = $bracketData['winner'] ?? null;

        // Determine tournament type
        $isTeamBased = $tournament && strtolower(str_replace([' ', '-'], '', $tournament->tournament_type ?? '')) === 'teamvsteam';

        // Build game payload
        $gamePayload = [
            'tournament_id' => $event->tournament_id,
            'game_date' => $event->date,
            'start_time' => $event->start_time,
            'end_time' => $event->end_time,
            'status' => $isFinished ? 'completed' : 'scheduled',
            'bracket_data' => $bracketData,
        ];

        // Set winner based on tournament type
        if ($isFinished && $winner) {
            if ($isTeamBased && isset($winner['teamId'])) {
                // Validate that the teamId exists in teams table
                $teamId = $winner['teamId'];
                
                // If teamId doesn't exist in teams, check if it's a participant ID
                if (!Team::where('id', $teamId)->exists()) {
                    // Try to find the team_id from EventParticipant
                    $participant = EventParticipant::where('id', $teamId)
                        ->where('event_id', $event->id)
                        ->whereNotNull('team_id')
                        ->first();
                    
                    if ($participant && $participant->team_id) {
                        $teamId = $participant->team_id;
                    } else {
                        // Invalid teamId - don't set winner_team_id
                        \Log::warning('Invalid teamId in bracket winner', [
                            'event_id' => $event->id,
                            'teamId' => $winner['teamId'],
                            'winner' => $winner
                        ]);
                        $teamId = null;
                    }
                }
                
                // Only set winner_team_id if we have a valid team ID
                if ($teamId && Team::where('id', $teamId)->exists()) {
                    $gamePayload['winner_team_id'] = $teamId;
                }
            } else {
                $gamePayload['winner_name'] = $winner['name'] ?? null;
            }
        }

        // Use updateOrCreate to allow multiple saves (one bracket per sub-event)
        $game = EventGame::updateOrCreate(
            [
                'event_id' => $event->id,
                'round_number' => 0,
                'match_number' => 0,
            ],
            $gamePayload
        );

        return response()->json([
            'status' => 'success',
            'message' => $isFinished ? 'Tournament completed and saved' : 'Bracket progress saved',
            'game' => $game,
            'is_finished' => $isFinished,
            'winner' => $winner['name'] ?? null,
        ], $game->wasRecentlyCreated ? 201 : 200);
    }

    // Get stored bracket data for a sub-event
    public function getBracket($eventId)
    {
        $event = Event::with('tournament')->findOrFail($eventId);
        $tournament = $event->tournament;

        if (!$tournament) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tournament not found for this event',
            ], 404);
        }

        // Get tournament type
        $tournamentType = $tournament->tournament_type ?? 'team vs team';

        // Fetch participants based on tournament type
        $participants = [];
        
        if ($tournamentType === 'team vs team') {
            // Fetch team participants (team-level registrations)
            // Check for both 'approved' and 'confirmed' status
            $teamParticipants = EventParticipant::where('event_id', $event->id)
                ->whereNotNull('team_id')
                ->whereNull('user_id') // Team-level registration
                ->whereIn('status', ['approved', 'confirmed'])
                ->with('team')
                ->get();

            $participants = $teamParticipants->map(function($tp) {
                $team = $tp->team;
                return [
                    'id' => $tp->id,
                    'team_id' => $team->id ?? null,
                    'name' => $team->name ?? $tp->team_name ?? 'Unknown Team',
                    'display_name' => $team->display_name ?? $team->name ?? null,
                    'status' => $tp->status,
                ];
            })->values()->toArray();
        } else {
            // Free for all - fetch individual participants
            // Check for both 'approved' and 'confirmed' status
            $userParticipants = EventParticipant::where('event_id', $event->id)
                ->whereNotNull('user_id')
                ->whereIn('status', ['approved', 'confirmed'])
                ->with('user')
                ->get();

            $participants = $userParticipants->map(function($up) {
                $user = $up->user;
                $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                return [
                    'id' => $up->id,
                    'user_id' => $user->id ?? null,
                    'name' => $fullName ?: ($user->username ?? 'Unknown User'),
                    'first_name' => $user->first_name ?? null,
                    'last_name' => $user->last_name ?? null,
                    'username' => $user->username ?? null,
                    'status' => $up->status,
                ];
            })->values()->toArray();
        }

        $game = EventGame::where('event_id', $event->id)
            ->where('round_number', 0)
            ->where('match_number', 0)
            ->first();

        // Build response with event and tournament details
        $response = [
            'status' => 'success',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'date' => $event->date,
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
                'sport' => $event->sport,
                'status' => $event->status,
                'is_approved' => $event->is_approved ?? false,
            ],
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'tournament_type' => $tournamentType,
                'type' => $tournament->type,
                'status' => $tournament->status,
            ],
            'event_id' => $event->id,
            'tournament_id' => $event->tournament_id,
            'tournament_type' => $tournamentType,
            'participants' => $participants,
            'participants_count' => count($participants),
        ];

        if (! $game || ! $game->bracket_data) {
            $response['bracket_data'] = null;
            $response['message'] = 'No bracket data saved yet';
            return response()->json($response);
        }

        // Normalize bracket_data structure
        // Handle various storage formats:
        // 1. Direct bracket: { type, rounds, ... }
        // 2. Nested: { bracket: { type, rounds, ... } }
        // 3. Double nested: { bracket: { bracket: { type, rounds, ... } } }
        $bracketData = $game->bracket_data;
        
        // Extract bracket from nested structures
        if (isset($bracketData['bracket']) && is_array($bracketData['bracket'])) {
            // Check if it's double nested
            if (isset($bracketData['bracket']['bracket']) && is_array($bracketData['bracket']['bracket'])) {
                $bracketData = $bracketData['bracket']['bracket'];
            } else {
                $bracketData = $bracketData['bracket'];
            }
        }
        
        // Validate the bracket structure has required fields
        if (!is_array($bracketData) || !isset($bracketData['type']) || !isset($bracketData['rounds'])) {
            \Log::warning('Invalid bracket_data structure in database', [
                'event_id' => $eventId,
                'stored_bracket_data' => $game->bracket_data,
                'normalized_attempt' => $bracketData
            ]);
            
            $response['bracket_data'] = null;
            $response['message'] = 'Bracket data is not available or invalid';
            return response()->json($response, 400);
        }

        // Add bracket data (now normalized to expected structure)
        $response['bracket_data'] = $bracketData;
        $response['is_finished'] = $game->status === 'completed';
        $response['winner_team_id'] = $game->winner_team_id;
        $response['winner_name'] = $game->winner_name;

        return response()->json($response);
    }

    // Get public bracket data for a sub-event (no authentication required)
    public function getPublicBracket($eventId)
    {
        $event = Event::with('tournament')->findOrFail($eventId);
        $tournament = $event->tournament;

        if (!$tournament) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tournament not found for this event',
            ], 404);
        }

        // Only show public tournaments (not drafts)
        if ($tournament->status === 'draft') {
            return response()->json([
                'status' => 'error',
                'message' => 'Tournament not found',
            ], 404);
        }

        // Get tournament type
        $tournamentType = $tournament->tournament_type ?? 'team vs team';

        // Fetch participants based on tournament type (public info only)
        $participants = [];
        
        if ($tournamentType === 'team vs team') {
            // Fetch team participants (team-level registrations)
            $teamParticipants = EventParticipant::where('event_id', $event->id)
                ->whereNotNull('team_id')
                ->whereNull('user_id')
                ->whereIn('status', ['approved', 'confirmed'])
                ->with('team')
                ->get();

            $participants = $teamParticipants->map(function($tp) {
                $team = $tp->team;
                return [
                    'id' => $tp->id,
                    'team_id' => $team->id ?? null,
                    'name' => $team->name ?? $tp->team_name ?? 'Unknown Team',
                    'display_name' => $team->display_name ?? $team->name ?? null,
                    // Don't include status for public view
                ];
            })->values()->toArray();
        } else {
            // Free for all - fetch individual participants (public info only)
            $userParticipants = EventParticipant::where('event_id', $event->id)
                ->whereNotNull('user_id')
                ->whereIn('status', ['approved', 'confirmed'])
                ->with('user')
                ->get();

            $participants = $userParticipants->map(function($up) {
                $user = $up->user;
                $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                return [
                    'id' => $up->id,
                    'user_id' => $user->id ?? null,
                    'name' => $fullName ?: ($user->username ?? 'Unknown User'),
                    'first_name' => $user->first_name ?? null,
                    'last_name' => $user->last_name ?? null,
                    'username' => $user->username ?? null,
                    // Don't include status for public view
                ];
            })->values()->toArray();
        }

        $game = EventGame::where('event_id', $event->id)
            ->where('round_number', 0)
            ->where('match_number', 0)
            ->first();

        // Build response with event and tournament details (public info only)
        $response = [
            'status' => 'success',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'date' => $event->date,
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
                'sport' => $event->sport,
                'status' => $event->status,
                // Don't include is_approved for public view
            ],
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'tournament_type' => $tournamentType,
                'type' => $tournament->type,
                'status' => $tournament->status,
            ],
            'event_id' => $event->id,
            'tournament_id' => $event->tournament_id,
            'tournament_type' => $tournamentType,
            'participants' => $participants,
            'participants_count' => count($participants),
        ];

        if (! $game || ! $game->bracket_data) {
            $response['bracket_data'] = null;
            $response['message'] = 'No bracket data saved yet';
            return response()->json($response);
        }

        // Normalize bracket_data structure
        // Handle various storage formats:
        // 1. Direct bracket: { type, rounds, ... }
        // 2. Nested: { bracket: { type, rounds, ... } }
        // 3. Double nested: { bracket: { bracket: { type, rounds, ... } } }
        $bracketData = $game->bracket_data;
        
        // Extract bracket from nested structures
        if (isset($bracketData['bracket']) && is_array($bracketData['bracket'])) {
            // Check if it's double nested
            if (isset($bracketData['bracket']['bracket']) && is_array($bracketData['bracket']['bracket'])) {
                $bracketData = $bracketData['bracket']['bracket'];
            } else {
                $bracketData = $bracketData['bracket'];
            }
        }
        
        // Validate the bracket structure has required fields
        if (!is_array($bracketData) || !isset($bracketData['type']) || !isset($bracketData['rounds'])) {
            \Log::warning('Invalid bracket_data structure in database', [
                'event_id' => $eventId,
                'stored_bracket_data' => $game->bracket_data,
                'normalized_attempt' => $bracketData
            ]);
            
            $response['bracket_data'] = null;
            $response['message'] = 'Bracket data is not available or invalid';
            return response()->json($response, 400);
        }

        // Add bracket data (now normalized to expected structure)
        $response['bracket_data'] = $bracketData;
        $response['is_finished'] = $game->status === 'completed';
        // Include winner info for public view
        $response['winner_team_id'] = $game->winner_team_id;
        $response['winner_name'] = $game->winner_name;

        return response()->json($response);
    }

    // List tournaments owned by authenticated user with events and their subgames (schedule)
    public function myTournaments(Request $request)
    {
        $user = auth()->user();
        if (! $user) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);

        $perPage = min((int) $request->input('per_page', 10), 100);
        $page = (int) $request->input('page', 1);

        $query = Tournament::where('created_by', $user->id)->orderByDesc('created_at');
        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $tournaments = $paginated->getCollection()->map(function($t) {
            $events = Event::where('tournament_id', $t->id)
                ->orderBy('date')
                ->orderBy('start_time')
                ->get();

            $games = EventGame::whereIn('event_id', $events->pluck('id')->all())
                ->orderBy('game_date')
                ->orderBy('start_time')
                ->get()
                ->groupBy('event_id');

            $eventsArr = $events->map(function($e) use ($games) {
                return array_merge($e->toArray(), [
                    'games' => ($games->has($e->id) ? $games->get($e->id)->values()->toArray() : []),
                ]);
            })->values();

            return array_merge($t->toArray(), ['events' => $eventsArr]);
        })->values();

        return response()->json([
            'status' => 'success',
            'tournaments' => $tournaments,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ], 200);
    }

    // List tournaments the authenticated user joined — include only events they are part of and each event's subgames (schedule)
    public function joinedTournaments(Request $request)
    {
        $user = auth()->user();
        if (! $user) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);

        // collect team ids the user belongs to (if any)
        $teamIds = TeamMember::where('user_id', $user->id)->pluck('team_id')->unique()->filter()->values()->all();

        // collect event ids where user is a participant or their team is registered
        $eventIds = EventParticipant::where(function($q) use ($user, $teamIds) {
                $q->where('user_id', $user->id);
                if (! empty($teamIds)) $q->orWhereIn('team_id', $teamIds);
            })
            ->pluck('event_id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        if (empty($eventIds)) {
            return response()->json(['status' => 'success', 'tournaments' => []], 200);
        }

        // fetch events and related tournaments
        $events = Event::whereIn('id', $eventIds)->get();
        $tournamentIds = $events->pluck('tournament_id')->unique()->filter()->values()->all();

        $tournaments = Tournament::whereIn('id', $tournamentIds)->get()->map(function($t) use ($events) {
            $eventsForTournament = $events->where('tournament_id', $t->id)->map(function($e) {
                return $e;
            })->values();

            $games = EventGame::whereIn('event_id', $eventsForTournament->pluck('id')->all())
                ->orderBy('game_date')
                ->orderBy('start_time')
                ->get()
                ->groupBy('event_id');

            $eventsArr = $eventsForTournament->map(function($e) use ($games) {
                return array_merge($e->toArray(), [
                    'games' => ($games->has($e->id) ? $games->get($e->id)->values()->toArray() : []),
                ]);
            })->values();

            return array_merge($t->toArray(), ['events' => $eventsArr]);
        })->values();

        return response()->json([
            'status' => 'success',
            'tournaments' => $tournaments,
        ], 200);
    }
}
