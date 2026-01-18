<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'prizes' => 'nullable|string',
        ]);

        // Handle uploaded photo file (store in public disk under 'tournaments')
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('tournaments', 'public');
            $data['photo'] = $path;
        }

        $data['created_by'] = auth()->id();

        $tournament = Tournament::create($data);

        $tournamentArray = $tournament->fresh()->toArray();
        $tournamentArray['photo_url'] = $tournament->photo ? Storage::url($tournament->photo) : null;

        return response()->json(['status' => 'success', 'tournament' => $tournamentArray], 201);
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

        // prepare event payload — store canonical event_type = 'tournament' and persist sub-type if column exists
        $payload = [
            'name' => $request->name,
            'description' => $request->description,
            'sport' => $request->sport,
            'venue_id' => $request->venue_id,
            'facility_id' => $request->facility_id,
            'slots' => $tournament->max_teams,
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
    public function participants($eventId)
    {
        $event = Event::with([
            'participants',
            'participants.user',
            'participants.team',
            'participants.team.members.user'
        ])->findOrFail($eventId);

        // determine effective sub-type
        $subType = strtolower($event->sub_event_type ?? $event->event_type ?? '');

        if (strpos($subType, 'free') !== false || strpos($subType, 'free_for_all') !== false) {
            $users = $event->participants->whereNotNull('user_id')->map(function($p){
                $u = $p->user;
                return [
                    'first_name' => $u->first_name ?? null,
                    'last_name'  => $u->last_name ?? null,
                    'position'   => $p->position ?? ($u->position ?? null),
                ];
            })->values();

            return response()->json(['type' => 'free_for_all', 'participants' => $users]);
        }

        // team vs team: return minimal team + members info
        $teams = $event->participants->whereNotNull('team_id')
            ->map(fn($p) => $p->team)
            ->unique('id')
            ->values()
            ->map(function($team) {
                $members = $team->members->map(function($m){
                    $u = $m->user;
                    return [
                        'first_name' => $u->first_name ?? null,
                        'last_name'  => $u->last_name ?? null,
                        'position'   => $m->position ?? ($m->role ?? null) ?? ($u->position ?? null),
                    ];
                })->values();

                return [
                    'team_name' => $team->name ?? null,
                    'members'   => $members,
                ];
            });

        return response()->json(['type' => 'team_vs_team', 'teams' => $teams]);
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

    public function cancelSubEvent(Request $request, $eventId)
    {
        $user = auth()->user();
        if (! $user) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);

        $data = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $event = Event::with('participants')->findOrFail($eventId);
        $tournament = $event->tournament ?? \App\Models\Tournament::find($event->tournament_id);

        // authorization: event creator or tournament creator
        $isAllowed = ($event->created_by == $user->id) || ($tournament && $tournament->created_by == $user->id);
        if (! $isAllowed) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
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

        // Extract bracket info from payload
        $bracketData = $request->all();
        $isFinished = $request->input('bracket.isFinished', false);
        $winner = $request->input('bracket.winner');

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
                $gamePayload['winner_team_id'] = $winner['teamId'];
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

        $game = EventGame::where('event_id', $event->id)
            ->where('round_number', 0)
            ->where('match_number', 0)
            ->first();

        if (! $game || ! $game->bracket_data) {
            return response()->json([
                'status' => 'success',
                'event_id' => $event->id,
                'tournament_id' => $event->tournament_id,
                'bracket_data' => null,
                'message' => 'No bracket data saved yet',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'event_id' => $event->id,
            'tournament_id' => $event->tournament_id,
            'bracket_data' => $game->bracket_data,
            'is_finished' => $game->status === 'completed',
            'winner_team_id' => $game->winner_team_id,
            'winner_name' => $game->winner_name,
        ]);
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
