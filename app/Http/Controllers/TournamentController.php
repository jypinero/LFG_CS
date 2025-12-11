<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\TournamentDocument;
use App\Models\TournamentAnalytics;
use App\Models\Event;
use App\Models\TournamentParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\EventParticipant;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\EventTeam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Booking;
use Illuminate\Validation\Rule;
use App\Models\EventResult;
use App\Models\EventPenalty;
use Illuminate\Support\Facades\Storage;

class TournamentController extends Controller
{
    public function __construct()
    {
        // Ensure authenticated for create/update/delete
        $this->middleware('auth')->only(['create', 'update', 'destroy', 'createGame', 'updateGame', 'deleteGame']);
    }

    private function generateCheckinCode()
    {
        do {
            $code = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        } while (Event::where('checkin_code', $code)->exists());
        
        return $code;
    }

    public function index()
    {
        $tournaments = Tournament::with([
            'events' => function($query) {
                $query->where('is_approved', true);
            },
            'participants',
            'organizers.user',
            'documents',
            'analytics',
            'announcements'
        ])
        ->whereHas('events', function($query) {
            $query->where('is_approved', true);
        })
        ->get();

        return response()->json([
            'status' => 'success',
            'data' => $tournaments,
            'count' => $tournaments->count()
        ]);
    }
    /**
     * Create a tournament
     */
    public function create(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in(['single_sport', 'multisport'])],
            'tournament_type' => ['required', Rule::in(['team vs team', 'free for all'])],  // ADD THIS
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'registration_deadline' => 'nullable|date',
            'status' => ['nullable', Rule::in(['draft','open_registration','registration_closed','ongoing','completed','cancelled'])],
            'requires_documents' => 'sometimes|boolean',
            'required_documents' => 'sometimes|array',
            'settings' => 'sometimes|array',
            'max_teams' => 'nullable|integer|min:1',
            'min_teams' => 'nullable|integer|min:1',
            'registration_fee' => 'nullable|numeric|min:0',
            'rules' => 'nullable|string',
            'prizes' => 'nullable|string',
        ]);

        // Optional: if request includes team ownership validation
        if ($request->filled('team_id') && class_exists(\App\Models\Team::class)) {
            $teamId = $request->input('team_id');
            $isOwner = DB::table('teams')->where('id', $teamId)->where('owner_id', $user->id)->exists();
            if (! $isOwner) {
                return response()->json(['status' => 'error', 'message' => 'You are not the owner of the supplied team'], 403);
            }
        }

        DB::beginTransaction();
        try {
            $tournament = Tournament::create(array_merge($data, [
                'created_by' => $user->id,
                'status' => $data['status'] ?? 'draft',
            ]));

            // Add creator as owner organizer
            TournamentOrganizer::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'permissions' => null,
            ]);

            // Initialize analytics record
            TournamentAnalytics::create([
                'tournament_id' => $tournament->id,
                'total_participants' => 0,
                'total_teams' => 0,
                'total_games' => 0,
                'completed_games' => 0,
                'no_shows' => 0,
                'average_rating' => null,
                'total_ratings' => 0,
            ]);

            DB::commit();

            return response()->json(['status' => 'success', 'tournament' => $tournament], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to create tournament', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a tournament (with relations)
     */
    public function show($id)
    {
        $tournament = Tournament::with([
            'events',
            'participants',
            'organizers.user',
            'documents',
            'analytics',
            'announcements'
        ])->find($id);

        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        return response()->json(['status' => 'success', 'tournament' => $tournament]);
    }

    /**
     * Update a tournament (allowed in draft or while registration open and before deadline)
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        $tournament = Tournament::find($id);

        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Only creator or organizer with owner/organizer role can update
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // Allow updates only if draft OR registration not started/closed
        if (! ($tournament->status === 'draft' ||
            ($tournament->status === 'open_registration' && ($tournament->registration_deadline === null || now()->lt($tournament->registration_deadline))))
        ) {
            return response()->json(['status' => 'error', 'message' => 'Tournament cannot be updated at this stage'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => [ 'sometimes', Rule::in(['single_sport', 'multisport']) ],
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
            'registration_deadline' => 'nullable|date',
            'status' => [ 'sometimes', Rule::in(['draft','open_registration','registration_closed','ongoing','completed','cancelled']) ],
            'requires_documents' => 'sometimes|boolean',
            'required_documents' => 'sometimes|array',
            'settings' => 'sometimes|array',
            'max_teams' => 'nullable|integer|min:1',
            'min_teams' => 'nullable|integer|min:1',
            'registration_fee' => 'nullable|numeric|min:0',
            'rules' => 'nullable|string',
            'prizes' => 'nullable|string',
        ]);

        $tournament->fill($data);
        $tournament->save();

        return response()->json(['status' => 'success', 'tournament' => $tournament]);
    }

    /**
     * Soft delete a tournament
     */
    public function destroy($id)
    {
        $user = auth()->user();
        $tournament = Tournament::find($id);

        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOwnerOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();

        if (! $isCreator && ! $isOwnerOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $tournament->delete();

        return response()->json(['status' => 'success', 'message' => 'Tournament deleted (soft)']);
    }

    /**
     * Create a tournament game/event (support single / multisport tournaments)
     */
    public function createGame(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $user = auth()->user();
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner','organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // basic validation (time fields used for double-booking check)
        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'event_type' => 'sometimes|string|in:tournament,match,team vs team,free for all,multisport',
            'sport' => 'sometimes|string|max:100',
            'venue_id' => 'required|exists:venues,id',
            'facility_id' => 'required|exists:facilities,id',
            'slots' => 'required|integer|min:1',
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'team_ids' => 'sometimes|array',
            'team_ids.*' => 'sometimes|exists:teams,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Enforce tournament_type semantics:
        // - team vs team: events are matches between teams; team_ids allowed/expected
        // - free for all: events are individual; team_ids must NOT be provided
        if ($tournament->tournament_type === 'free for all' && $request->filled('team_ids')) {
            return response()->json(['status' => 'error', 'message' => 'This tournament only accepts individual events (no team_ids)'], 422);
        }

        if ($tournament->tournament_type === 'team vs team' && $request->filled('team_ids')) {
            // ensure at least 2 teams if supplied
            $teamIds = $request->input('team_ids', []);
            if (count($teamIds) < 2) {
                return response()->json(['status' => 'error', 'message' => 'Team vs team events require at least 2 team_ids when provided'], 422);
            }
        }

        // Ensure event has a sport value. Prefer request value; fall back to tournament.settings.sport(s).
        $sport = $request->input('sport', null);
        $tournamentSports = data_get($tournament, 'settings.sports') ?? data_get($tournament, 'settings.sport');

        // Normalize tournament allowed sports to array if present
        $allowedSports = null;
        if (! empty($tournamentSports)) {
            $allowedSports = is_array($tournamentSports) ? $tournamentSports : [$tournamentSports];
        }

        // If request supplies sport, accept but enforce allowed list when tournament defines allowed sports
        if ($sport) {
            if ($allowedSports && ! in_array($sport, $allowedSports)) {
                return response()->json(['status' => 'error', 'message' => 'Event sport must match tournament sport(s)'], 422);
            }
        } else {
            // no sport in request -> try to derive from tournament settings
            if ($allowedSports) {
                $sport = $allowedSports[0];
            } else {
                // no sport anywhere: error regardless of single/multi
                return response()->json(['status' => 'error', 'message' => 'Event sport is required'], 422);
            }
        }

        // Check tournament type: single_sport vs multisport
        if ($tournament->type === 'single_sport') {
            // For single_sport, get the first sport used in existing games
            $existingEvent = Event::where('tournament_id', $tournament->id)
                ->where('is_tournament_game', true)
                ->first();

            if ($existingEvent && $existingEvent->sport !== $sport) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Single-sport tournament can only have events with ' . $existingEvent->sport
                ], 422);
            }
        }
        // For multisport, different sports are allowed - no additional check needed

        // check venue open/availability
        $venue = \App\Models\Venue::find($request->venue_id);
        if ($venue && data_get($venue, 'is_closed')) {
            return response()->json(['status'=>'error','message'=>'This venue is closed and not accepting new events.'], 403);
        }

        // prevent double booking same venue+facility + date + overlapping times
        $conflict = Event::where('venue_id', $request->venue_id)
            ->where('facility_id', $request->facility_id)
            ->where('date', $request->date)
            ->where(function($q) use ($request) {
                $q->where('start_time', '<', $request->end_time)
                  ->where('end_time', '>', $request->start_time);
            })
            ->exists();

        if ($conflict) {
            return response()->json(['status'=>'error','message'=>'Venue/facility already booked for the selected date/time'], 409);
        }

        DB::beginTransaction();
        try {
            $maxNumber = Event::where('tournament_id', $tournament->id)->max('game_number');
            $gameNumber = ($maxNumber ?? 0) + 1;

            $event = Event::create(array_merge($request->only([
                'name','description','venue_id','facility_id','slots','date','start_time','end_time'
            ]), [
                'sport' => $sport,
                'created_by' => $user->id,
                'checkin_code' => $this->generateCheckinCode(),
                'is_approved' => false,
                'approved_at' => null,
                'tournament_id' => $tournament->id,
                'is_tournament_game' => true,
                'game_number' => $gameNumber,
                'event_type' => $request->input('event_type','tournament'),
            ]));

            // optional: create a booking record if model exists
            if (class_exists(\App\Models\Booking::class)) {
                \App\Models\Booking::create([
                    'venue_id' => $event->venue_id,
                    'user_id' => $user->id,
                    'event_id' => $event->id,
                    'sport' => $event->sport,
                    'date' => $event->date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'purpose' => 'Tournament game: '.$event->name,
                    'status' => 'pending',
                ]);
            }

            DB::commit();
            return response()->json(['status'=>'success','event'=>$event->fresh()], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status'=>'error','message'=>'Failed to create game','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Return all games for a tournament (optionally filter by sport)
     */
    public function getGames(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $query = Event::where('tournament_id', $tournament->id)
            ->where('is_tournament_game', true);

        if ($request->filled('sport')) {
            $query->where('sport', $request->input('sport'));
        }

        $games = $query->orderBy('game_number')->get();

        return response()->json(['status'=>'success','games'=>$games]);
    }

    /**
     * Update a tournament game
     */
    public function updateGame(Request $request, $tournamentId, $gameId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $user = auth()->user();
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner','organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status'=>'error','message'=>'Forbidden'], 403);
        }

        $event = Event::where('id', $gameId)->where('tournament_id', $tournament->id)->first();
        if (! $event) {
            return response()->json(['status'=>'error','message'=>'Game not found in tournament'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'venue_id' => 'sometimes|exists:venues,id',
            'facility_id' => 'sometimes|exists:facilities,id',
            'slots' => 'sometimes|integer|min:1',
            'date' => 'sometimes|date',
            'start_time' => 'sometimes|date_format:H:i:s',
            'end_time' => 'sometimes|date_format:H:i:s|after:start_time',
            'sport' => 'sometimes|string|max:100',
            'status' => 'sometimes|string',
            'score_home' => 'nullable|integer|min:0',
            'score_away' => 'nullable|integer|min:0',
        ]);

        // optional double-booking check when venue/facility/date/time changed
        if (($request->filled('venue_id') || $request->filled('facility_id') || $request->filled('date') || $request->filled('start_time') || $request->filled('end_time'))) {
            $venueId = $request->input('venue_id', $event->venue_id);
            $facilityId = $request->input('facility_id', $event->facility_id);
            $date = $request->input('date', $event->date);
            $start = $request->input('start_time', $event->start_time);
            $end = $request->input('end_time', $event->end_time);

            $conflict = Event::where('id', '!=', $event->id)
                ->where('venue_id', $venueId)
                ->where('facility_id', $facilityId)
                ->where('date', $date)
                ->where(function($q) use ($start, $end) {
                    $q->where('start_time', '<', $end)->where('end_time', '>', $start);
                })
                ->exists();

            if ($conflict) {
                return response()->json(['status'=>'error','message'=>'Venue/facility already booked for the selected date/time'], 409);
            }
        }

        $event->fill($data);
        $event->save();

        return response()->json(['status'=>'success','event'=>$event->fresh()]);
    }

    /**
     * Delete a tournament game (event).
     * If there are bookings, normal delete will be blocked.
     * Use ?force=1 to remove bookings/participants and then delete the event.
     */
    public function deleteGame(Request $request, $tournamentId, $eventId)
    {
        $event = Event::find($eventId);

        if (! $event || ($event->tournament_id && $event->tournament_id != $tournamentId)) {
            return response()->json(['status' => 'error', 'message' => 'Event not found'], 404);
        }

        $bookingsCount = $event->bookings()->count();
        $force = $request->boolean('force');

        if ($bookingsCount > 0 && ! $force) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event has existing bookings. Remove bookings first or call with ?force=1 to force-delete.',
                'bookings_count' => $bookingsCount
            ], 422);
        }

        DB::transaction(function () use ($event, $force) {
            if ($force) {
                $event->bookings()->delete();
                
                if (method_exists($event, 'participants')) {
                    $event->participants()->delete();
                }
            }

            // Only detach if teams is a BelongsToMany relationship
            if (method_exists($event, 'teams')) {
                $relation = $event->teams();
                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                    $event->teams()->detach();
                }
            }

            $event->delete();
        });

        return response()->json(['status' => 'success', 'message' => 'Event deleted']);
    }

    /**
     * Single registration endpoint (handles individual OR team registration)
     *
     * Rules:
     *  - team registrations allowed ONLY for tournament_type === 'team vs team'
     *  - individual registrations allowed ONLY for tournament_type === 'free for all'
     *    or when settings.allow_individual = true
     *  - team registrations require caller to be team owner or manager
     */
    public function register(Request $request, $tournamentId, $eventId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        $event = Event::where('id', $eventId)->where('tournament_id', $tournamentId)->first();
        
        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        if ($tournament->status !== 'open_registration') {
            return response()->json(['status' => 'error', 'message' => 'Registration is not open'], 422);
        }
        if ($tournament->registration_deadline && now()->isAfter($tournament->registration_deadline)) {
            return response()->json(['status' => 'error', 'message' => 'Registration deadline has passed'], 422);
        }

        $data = $request->validate([
            'team_id' => 'sometimes|nullable|exists:teams,id',
        ]);

        $teamId = $data['team_id'] ?? null;
        $tpStatus = $tournament->requires_documents ? 'pending' : 'approved';
        // event participant status mirrors tournament participant (approved => confirmed)
        $epStatus = $tpStatus === 'approved' ? 'confirmed' : 'pending';

        DB::beginTransaction();
        try {
            // TEAM REGISTRATION PATH - ONLY 'team vs team'
            if ($teamId) {
                if ($tournament->tournament_type !== 'team vs team') {
                    return response()->json(['status' => 'error', 'message' => 'This tournament does not accept team registrations'], 422);
                }

                $team = Team::find($teamId);
                if (! $team) {
                    return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
                }

                $isOwner = $team->owner_id === $user->id;
                $isManager = TeamMember::where('team_id', $teamId)
                    ->where('user_id', $user->id)
                    ->whereIn('role', ['owner', 'manager'])
                    ->exists();
                if (! $isOwner && ! $isManager) {
                    return response()->json(['status' => 'error', 'message' => 'Not authorized to register this team'], 403);
                }

                $exists = TournamentParticipant::where('tournament_id', $tournament->id)
                    ->where('team_id', $teamId)
                    ->where('participant_type', 'team')
                    ->exists();
                if ($exists) {
                    return response()->json(['status' => 'error', 'message' => 'Team already registered'], 422);
                }

                if ($tournament->max_teams) {
                    $count = TournamentParticipant::where('tournament_id', $tournament->id)
                        ->where('participant_type', 'team')
                        ->whereIn('status', ['approved', 'pending'])
                        ->count();
                    if ($count >= $tournament->max_teams) {
                        return response()->json(['status' => 'error', 'message' => 'Tournament is full'], 422);
                    }
                }

                if ($tournament->min_teams) {
                    $teamMembersCount = TeamMember::where('team_id', $teamId)->where('roster_status', 'active')->count();
                    if ($teamMembersCount < $tournament->min_teams) {
                        return response()->json(['status' => 'error', 'message' => "Team must have at least {$tournament->min_teams} members"], 422);
                    }
                }

                $participant = TournamentParticipant::create([
                    'tournament_id' => $tournament->id,
                    'team_id' => $teamId,
                    'user_id' => $user->id,
                    'type' => 'team',
                    'participant_type' => 'team',
                    'status' => $tpStatus,
                    'registered_at' => now(),
                ]);

                // create a tournament-level EventParticipant record (no event_id) for quick lookups / removals
                EventParticipant::create([
                    'event_id' => $event->id ?? null,
                    'user_id' => null,
                    'team_id' => $teamId,
                    'status' => $epStatus,
                    'tournament_id' => $tournament->id,
                ]);

                // increment analytics only if auto-approved
                if ($tpStatus === 'approved') {
                    $tournament->analytics?->increment('total_teams');
                    $tournament->analytics?->increment('total_participants', TeamMember::where('team_id', $teamId)->where('status', 'active')->count());
                }

                DB::commit();
                return response()->json([
                    'status' => 'success',
                    'participant' => $participant,
                    'message' => $tpStatus === 'pending' ? 'Team registration pending' : 'Team registered'
                ], 201);
            }

            // INDIVIDUAL REGISTRATION PATH - ONLY 'free for all' OR settings.allow_individual
            $allowIndividuals = data_get($tournament, 'settings.allow_individual', false);
            if ($tournament->tournament_type !== 'free for all' && ! $allowIndividuals) {
                return response()->json(['status' => 'error', 'message' => 'This tournament does not accept individual registrations'], 422);
            }

            $exists = TournamentParticipant::where('tournament_id', $tournament->id)
                ->where('user_id', $user->id)
                ->where('participant_type', 'individual')
                ->exists();
            if ($exists) {
                return response()->json(['status' => 'error', 'message' => 'User already registered', 'tournament' => $tournament->id, 'user' => $user->id], 422);
            }

            if ($tournament->max_teams) {
                $count = TournamentParticipant::where('tournament_id', $tournament->id)
                    ->where('participant_type', 'individual')
                    ->whereIn('status', ['approved', 'pending'])
                    ->count();
                if ($count >= $tournament->max_teams) {
                    return response()->json(['status' => 'error', 'message' => 'Tournament is full'], 422);
                }
            }

            $participant = TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'type' => 'individual',
                'participant_type' => 'individual',
                'status' => $tpStatus,
                'registered_at' => now(),
            ]);

            // create a tournament-level EventParticipant record for this user
            EventParticipant::create([
                'event_id' => $event->id ?? null,
                'user_id' => $user->id,
                'team_id' => null,
                'status' => $epStatus,
                'tournament_id' => $tournament->id,
            ]);

            if ($tpStatus === 'approved') {
                $tournament->analytics?->increment('total_participants');
            }

            DB::commit();
            return response()->json([
                'status' => 'success',
                'participant' => $participant,
                'message' => $tpStatus === 'pending' ? 'Registration pending' : 'Registered'
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Registration failed', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * List participants (filter by status/type)
     *
     * Returns both TournamentParticipant rows and the tournament-level EventParticipant rows
     * so callers can see tournament participants and any event-specific enrollment entries.
     */
    public function getParticipants(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $tpQuery = TournamentParticipant::with(['user','team'])
            ->where('tournament_id',$tournament->id);

        if ($request->filled('status')) {
            $tpQuery->where('status',$request->input('status'));
        }
        if ($request->filled('type')) {
            $tpQuery->where('participant_type',$request->input('type'));
        }

        $tournamentParticipants = $tpQuery->orderBy('registered_at')->get();

        // gather tournament-level event participants (fast lookup / active-game references)
        $epQuery = EventParticipant::with(['user','team'])
            ->where('tournament_id', $tournament->id);
        if ($request->filled('status')) {
            $epQuery->where('status', $request->input('status'));
        }
        $eventParticipants = $epQuery->orderBy('id')->get();

        return response()->json([
            'status'=>'success',
            'tournament_participants' => $tournamentParticipants,
            'event_participants' => $eventParticipants,
            'count' => $tournamentParticipants->count()
        ]);
    }

    // Insert Phase 6 team-management methods below

    /**
     * Manually assign teams to groups/pools for an event.
     * Body: { "assignments": [ { "group": "A", "team_ids": [1,2] }, ... ] }
     */
    public function assignTeams(Request $request, $tournamentId, $eventId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        // Only applicable to team-based tournaments
        if ($tournament->tournament_type !== 'team vs team') {
            return response()->json(['status'=>'error','message'=>'Team assignment only available for team vs team tournaments'], 422);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $data = $request->validate([
            'assignments' => 'required|array|min:1',
            'assignments.*.group' => 'required|string|max:100',
            'assignments.*.team_ids' => 'required|array|min:1',
            'assignments.*.team_ids.*' => 'required|integer|exists:teams,id',
        ]);

        DB::beginTransaction();
        try {
            foreach ($data['assignments'] as $assignment) {
                $group = $assignment['group'];
                foreach ($assignment['team_ids'] as $teamId) {
                    EventTeam::updateOrCreate(
                        ['event_id' => $eventId, 'team_id' => $teamId],
                        ['group_name' => $group, 'assigned_by' => $user->id, 'assigned_at' => now()]
                    );
                }
            }

            DB::commit();
            return response()->json(['status'=>'success','message'=>'Teams assigned']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status'=>'error','message'=>'Failed to assign teams','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Auto-balance teams evenly across N groups for an event.
     * Body: { "groups": 4, "group_prefix": "Group " }
     */
    public function autoBalanceTeams(Request $request, $tournamentId, $eventId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        // Only applicable to team-based tournaments
        if ($tournament->tournament_type !== 'team vs team') {
            return response()->json(['status'=>'error','message'=>'Auto-balance only available for team vs team tournaments'], 422);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $data = $request->validate([
            'groups' => 'required|integer|min:1',
            'group_prefix' => 'sometimes|string|max:50',
        ]);

        $groups = (int)$data['groups'];
        $prefix = $data['group_prefix'] ?? '';

        $teamIds = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('participant_type', 'team')
            ->whereIn('status', ['approved','pending'])
            ->pluck('team_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($teamIds)) {
            return response()->json(['status'=>'error','message'=>'No teams to balance'], 422);
        }

        shuffle($teamIds);

        DB::beginTransaction();
        try {
            EventTeam::where('event_id', $eventId)->delete();

            foreach ($teamIds as $index => $teamId) {
                $groupIndex = $index % $groups;
                $groupName = $prefix !== '' ? ($prefix . ($groupIndex + 1)) : 'Group ' . ($groupIndex + 1);
                EventTeam::create([
                    'event_id' => $eventId,
                    'team_id' => $teamId,
                    'group_name' => $groupName,
                    'assigned_by' => $user->id,
                    'assigned_at' => now(),
                ]);
            }

            DB::commit();
            return response()->json(['status'=>'success','message'=>'Teams balanced','groups'=>$groups]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status'=>'error','message'=>'Failed to balance teams','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Replace a player on a team (organizer action).
     * Body: { "old_user_id": 10, "new_user_id": 20 }
     */
    public function replacePlayer(Request $request, $tournamentId, $teamId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        // Only for team tournaments
        if ($tournament->tournament_type !== 'team vs team') {
            return response()->json(['status'=>'error','message'=>'Replace player only available for team vs team tournaments'], 422);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $data = $request->validate([
            'old_user_id' => 'required|integer|exists:users,id',
            'new_user_id' => 'required|integer|exists:users,id',
            'new_role' => 'sometimes|string|max:50',
        ]);

        DB::beginTransaction();
        try {
            TeamMember::where('team_id', $teamId)
                ->where('user_id', $data['old_user_id'])
                ->update(['roster_status' => 'removed', 'removed_at' => now()]);

            $existing = TeamMember::where('team_id', $teamId)->where('user_id', $data['new_user_id'])->first();
            if ($existing) {
                $existing->update(['roster_status' => 'active', 'role' => $data['new_role'] ?? ($existing->role ?? 'member')]);
            } else {
                TeamMember::create([
                    'team_id' => $teamId,
                    'user_id' => $data['new_user_id'],
                    'role' => $data['new_role'] ?? 'member',
                    'roster_status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            EventParticipant::where('tournament_id', $tournament->id)
                ->where('team_id', $teamId)
                ->where('user_id', $data['old_user_id'])
                ->delete();

            EventParticipant::firstOrCreate(
                [
                    'tournament_id' => $tournament->id,
                    'event_id' => null,
                    'team_id' => $teamId,
                    'user_id' => $data['new_user_id'],
                ],
                ['status' => 'confirmed']
            );

            DB::commit();
            return response()->json(['status'=>'success','message'=>'Player replaced']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status'=>'error','message'=>'Failed to replace player','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Mark a tournament participant as no-show (and handle optional forfeit).
     * Body: { "forfeit": true, "reason": "No contact" }
     */
    public function markNoShow(Request $request, $tournamentId, $participantId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $data = $request->validate([
            'forfeit' => 'sometimes|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $participant = TournamentParticipant::where('id',$participantId)->where('tournament_id',$tournament->id)->first();
        if (! $participant) return response()->json(['status'=>'error','message'=>'Participant not found'], 404);

        DB::beginTransaction();
        try {
            $participant->update([
                'status' => 'no_show',
                'no_show_reason' => $data['reason'] ?? null,
                'no_show_at' => now(),
            ]);

            $tournament->analytics?->increment('no_shows');

            $epQuery = EventParticipant::where('tournament_id', $tournament->id);
            if ($participant->participant_type === 'individual') {
                $epQuery->where('user_id', $participant->user_id);
            } else {
                $epQuery->where('team_id', $participant->team_id);
            }

            $epStatus = $data['forfeit'] ? 'forfeit' : 'no_show';
            $epQuery->update(['status' => $epStatus]);

            $notifyUserId = $participant->participant_type === 'individual'
                ? $participant->user_id
                : Team::find($participant->team_id)?->owner_id;

            if ($notifyUserId) {
                $notification = Notification::create([
                    'type' => 'participant_no_show',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'participant_id' => $participant->id,
                        'reason' => $data['reason'] ?? null,
                        'forfeit' => (bool)($data['forfeit'] ?? false),
                    ],
                    'created_by' => $user->id,
                ]);
                UserNotification::create(['notification_id' => $notification->id, 'user_id' => $notifyUserId, 'is_read' => false]);
            }

            DB::commit();
            return response()->json(['status'=>'success','message'=>'Participant marked as no-show']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status'=>'error','message'=>'Failed to mark no-show','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Upload tournament document
     */
    public function uploadDocument(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $request->validate([
            'document' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'participant_id' => 'required|exists:tournament_participants,id',
            'document_type' => 'required|string|max:100',
        ]);

        $participantId = $request->input('participant_id');
        $participant = TournamentParticipant::where('id', $participantId)->where('tournament_id', $tournament->id)->first();
        if (! $participant) {
            return response()->json(['status'=>'error','message'=>'Participant not found'], 404);
        }

        // Authorization: organizer or the participant
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        $isParticipant = $participant->user_id === $user->id;

        if (! $isOrganizer && ! $isParticipant) {
            return response()->json(['status'=>'error','message'=>'Forbidden'], 403);
        }

        $file = $request->file('document');
        $path = $file->store("tournaments/{$tournamentId}/documents");

        $doc = TournamentDocument::create([
            'tournament_id' => $tournament->id,
            'participant_id' => $participantId,
            'document_type' => $request->input('document_type'),
            'document_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'verification_status' => 'pending',
        ]);

        return response()->json(['status'=>'success','document'=>$doc], 201);
    }

    /**
     * Get all documents for tournament
     */
    public function getDocuments(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $docs = TournamentDocument::where('tournament_id', $tournament->id)
            ->with('participant')
            ->orderBy('created_at','desc')
            ->get();

        return response()->json(['status'=>'success','documents'=>$docs]);
    }

    /**
     * Get documents for specific participant
     */
    public function getParticipantDocuments(Request $request, $tournamentId, $participantId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $participant = TournamentParticipant::where('id', $participantId)->where('tournament_id', $tournament->id)->first();
        if (! $participant) {
            return response()->json(['status'=>'error','message'=>'Participant not found'], 404);
        }

        // Authorization: organizer or the participant
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        $isParticipant = $participant->user_id === $user->id;

        if (! $isOrganizer && ! $isParticipant) {
            return response()->json(['status'=>'error','message'=>'Forbidden'], 403);
        }

        $docs = TournamentDocument::where('tournament_id', $tournament->id)
            ->where('participant_id', $participantId)
            ->orderBy('created_at','desc')
            ->get();

        return response()->json(['status'=>'success','documents'=>$docs]);
    }

    /**
     * Verify document and auto-approve participant if all docs verified
     */
    public function verifyDocument(Request $request, $tournamentId, $documentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        // Only organizers/owners can verify
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        if (! $isOrganizer) {
            return response()->json(['status'=>'error','message'=>'Forbidden'], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:pending,verified,rejected',
            'reason' => 'nullable|string|max:500',
        ]);

        $doc = TournamentDocument::where('id',$documentId)->where('tournament_id',$tournament->id)->first();
        if (! $doc) {
            return response()->json(['status'=>'error','message'=>'Document not found'], 404);
        }

        $doc->update([
            'verification_status' => $data['status'],
            'rejection_reason' => $data['reason'] ?? null,
            'verified_by' => $user->id,
            'verified_at' => $data['status'] === 'verified' ? now() : null,
        ]);

        // Auto-approve participant if all docs verified
        if ($data['status'] === 'verified') {
            $participant = TournamentParticipant::find($doc->participant_id);
            if ($participant) {
                $totalDocs = TournamentDocument::where('tournament_id', $tournament->id)
                    ->where('participant_id', $doc->participant_id)
                    ->count();
                $verifiedDocs = TournamentDocument::where('tournament_id', $tournament->id)
                    ->where('participant_id', $doc->participant_id)
                    ->where('verification_status', 'verified')
                    ->count();

                // Auto-approve if all docs verified
                if ($totalDocs > 0 && $totalDocs === $verifiedDocs && $participant->status === 'pending') {
                    $participant->update(['status'=>'approved','approved_at'=>now()]);

                    // Update event participants
                    if ($participant->participant_type === 'individual') {
                        EventParticipant::where('tournament_id', $tournament->id)
                            ->where('user_id', $participant->user_id)
                            ->update(['status'=>'confirmed']);
                    } else {
                        EventParticipant::where('tournament_id', $tournament->id)
                            ->where('team_id', $participant->team_id)
                            ->update(['status'=>'confirmed']);
                    }

                    // Send notification
                    $notifyUserId = $participant->user_id;
                    if ($notifyUserId) {
                        $notification = Notification::create([
                            'type' => 'document_verified',
                            'data' => [
                                'tournament_id' => $tournament->id,
                                'tournament_name' => $tournament->name,
                                'participant_id' => $participant->id,
                                'message' => 'All your documents have been verified. Registration approved!',
                            ],
                            'created_by' => $user->id,
                        ]);
                        UserNotification::create([
                            'notification_id' => $notification->id,
                            'user_id' => $notifyUserId,
                            'is_read' => false,
                        ]);
                    }

                    // Update analytics
                    if ($participant->participant_type === 'team') {
                        $tournament->analytics?->increment('total_teams');
                    } else {
                        $tournament->analytics?->increment('total_participants');
                    }
                }
            }
        }

        return response()->json(['status'=>'success','document'=>$doc]);
    }

    /**
     * Delete document
     */
    public function deleteDocument(Request $request, $tournamentId, $documentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $doc = TournamentDocument::where('id',$documentId)->where('tournament_id',$tournament->id)->first();
        if (! $doc) {
            return response()->json(['status'=>'error','message'=>'Document not found'], 404);
        }

        // Authorization: organizer only (docs cannot be deleted by participants once uploaded)
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        if (! $isOrganizer) {
            return response()->json(['status'=>'error','message'=>'Forbidden'], 403);
        }

        Storage::delete($doc->file_path);
        $doc->delete();

        return response()->json(['status'=>'success','message'=>'Document deleted']);
    }

    /**
     * Approve participant (organizer/owner)
     *
     * Also updates tournament-level EventParticipant to 'confirmed' when present.
     */
    public function approveParticipant(Request $request, $tournamentId, $participantId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status'=>'error','message'=>'Forbidden'], 403);
        }

        $participant = TournamentParticipant::where('id',$participantId)->where('tournament_id',$tournament->id)->first();
        if (! $participant) {
            return response()->json(['status'=>'error','message'=>'Participant not found'], 404);
        }
        if ($participant->status === 'approved') {
            return response()->json(['status'=>'error','message'=>'Already approved'], 422);
        }

        DB::beginTransaction();
        try {
            $participant->update(['status'=>'approved','approved_at'=>now()]);

            // update matching tournament-level EventParticipant(s)
            $epQuery = EventParticipant::where('tournament_id', $tournament->id);
            if ($participant->participant_type === 'individual') {
                $epQuery->where('user_id', $participant->user_id);
            } else {
                $epQuery->where('team_id', $participant->team_id);
            }
            $epQuery->update(['status' => 'confirmed']);

            // Send notification
            $notifyUserId = $participant->participant_type === 'individual' 
                ? $participant->user_id
                : Team::find($participant->team_id)?->owner_id;

            if ($notifyUserId) {
                $notification = \App\Models\Notification::create([
                    'type' => 'participant_approved',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'participant_id' => $participant->id,
                        'participant_type' => $participant->participant_type,
                        'message' => "Your {$participant->participant_type} registration for {$tournament->name} has been approved.",
                    ],
                    'created_by' => $user->id,
                ]);

                \App\Models\UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $notifyUserId,
                    'is_read' => false,
                ]);
            }

            // update analytics
            if ($participant->participant_type === 'team') {
                $tournament->analytics?->increment('total_teams');
            } else {
                $tournament->analytics?->increment('total_participants');
            }

            DB::commit();
            return response()->json(['status'=>'success','participant'=>$participant,'message'=>'Participant approved']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status'=>'error','message'=>'Failed to approve participant','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Reject participant
     *
     * Keeps tournament_participants record with rejected status.
     */
    public function rejectParticipant(Request $request, $tournamentId, $participantId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status'=>'error','message'=>'Forbidden'], 403);
        }

        $data = $request->validate(['reason'=>'nullable|string|max:500']);

        $participant = TournamentParticipant::where('id',$participantId)->where('tournament_id',$tournament->id)->first();
        if (! $participant) {
            return response()->json(['status'=>'error','message'=>'Participant not found'], 404);
        }

        DB::beginTransaction();
        try {
            $participant->update([
                'status' => 'rejected',
                'rejection_reason' => $data['reason'] ?? null,
                'rejected_at' => now(),
            ]);

            // Send notification
            $notifyUserId = $participant->participant_type === 'individual' 
                ? $participant->user_id
                : Team::find($participant->team_id)?->owner_id;

            if ($notifyUserId) {
                $notification = \App\Models\Notification::create([
                    'type' => 'participant_rejected',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'participant_id' => $participant->id,
                        'participant_type' => $participant->participant_type,
                        'reason' => $data['reason'] ?? null,
                        'message' => "Your {$participant->participant_type} registration for {$tournament->name} has been rejected.",
                    ],
                    'created_by' => $user->id,
                ]);

                \App\Models\UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $notifyUserId,
                    'is_read' => false,
                ]);
            }

            DB::commit();
            return response()->json(['status'=>'success','participant'=>$participant,'message'=>'Participant rejected']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status'=>'error','message'=>'Failed to reject participant','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Ban participant and remove from active games
     */
    public function banParticipant(Request $request, $tournamentId, $participantId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)
            ->where('user_id',$user->id)
            ->whereIn('role',['owner','organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $data = $request->validate(['reason'=>'nullable|string|max:500']);

        $participant = TournamentParticipant::where('id',$participantId)->where('tournament_id',$tournament->id)->first();
        if (! $participant) {
            return response()->json(['status'=>'error','message'=>'Participant not found'], 404);
        }

        DB::beginTransaction();
        try {
            // remove tournament-level event participants
            if ($participant->participant_type === 'individual' && $participant->user_id) {
                EventParticipant::where('tournament_id', $tournament->id)
                    ->where('user_id', $participant->user_id)
                    ->delete();
            }

            if ($participant->participant_type === 'team' && $participant->team_id) {
                $memberIds = TeamMember::where('team_id',$participant->team_id)->pluck('user_id')->toArray();
                // remove team-level entries and per-user entries
                EventParticipant::where('tournament_id', $tournament->id)
                    ->where(function($q) use ($participant, $memberIds) {
                        $q->where('team_id', $participant->team_id)
                          ->orWhereIn('user_id', $memberIds);
                    })->delete();
            }

            $participant->update([
                'status' => 'banned',
                'ban_reason' => $data['reason'] ?? null,
                'banned_at' => now(),
            ]);

            // Send notification
            $notifyUserId = $participant->participant_type === 'individual' 
                ? $participant->user_id
                : Team::find($participant->team_id)?->owner_id;

            if ($notifyUserId) {
                $notification = \App\Models\Notification::create([
                    'type' => 'participant_banned',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'participant_id' => $participant->id,
                        'participant_type' => $participant->participant_type,
                        'reason' => $data['reason'] ?? null,
                        'message' => "You have been banned from {$tournament->name}.",
                    ],
                    'created_by' => $user->id,
                ]);

                \App\Models\UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $notifyUserId,
                    'is_read' => false,
                ]);
            }

            // update analytics
            if ($participant->participant_type === 'team') {
                $tournament->analytics?->decrement('total_teams');
            } else {
                $tournament->analytics?->decrement('total_participants');
            }

            DB::commit();
            return response()->json(['status'=>'success','participant'=>$participant,'message'=>'Participant banned']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status'=>'error','message'=>'Failed to ban participant','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Return matches grouped by game_number / round for a tournament
     */
    public function getMatches(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $matches = Event::with(['teams','participants'])
            ->where('tournament_id', $tournament->id)
            ->where('is_tournament_game', true)
            ->orderBy('game_number')
            ->get();

        // Group by game_number (round) if present; fallback to single group
        $grouped = $matches->groupBy(function($m) {
            return $m->game_number ?? 'round_1';
        })->map(function($group) {
            return $group->values();
        });

        return response()->json(['status'=>'success','groups'=>$grouped]);
    }

    /**
     * Return full match details
     */
    public function getMatchDetails(Request $request, $tournamentId, $matchId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $match = Event::with(['teams','participants'])->where('tournament_id', $tournament->id)->where('id', $matchId)->first();
        if (! $match) return response()->json(['status'=>'error','message'=>'Match not found'], 404);

        $penalties = EventPenalty::where('event_id', $match->id)->orderBy('created_at')->get();
        $results = EventResult::where('event_id', $match->id)->orderBy('created_at')->get();

        return response()->json(['status'=>'success','match'=>$match,'penalties'=>$penalties,'results'=>$results]);
    }

    /**
     * Start a match
     */
    public function startMatch(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $match = Event::where('tournament_id',$tournament->id)->where('id',$matchId)->first();
        if (! $match) return response()->json(['status'=>'error','message'=>'Match not found'], 404);

        $match->status = 'in_progress';
        $match->started_at = now();
        $match->save();

        return response()->json(['status'=>'success','match'=>$match]);
    }

    /**
     * End a match
     */
    public function endMatch(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $match = Event::where('tournament_id',$tournament->id)->where('id',$matchId)->first();
        if (! $match) return response()->json(['status'=>'error','message'=>'Match not found'], 404);

        $data = $request->validate([
            'winner_team_id' => 'sometimes|nullable|integer|exists:teams,id',
            'score_home' => 'sometimes|nullable|integer',
            'score_away' => 'sometimes|nullable|integer',
        ]);

        if (array_key_exists('score_home', $data)) $match->score_home = $data['score_home'];
        if (array_key_exists('score_away', $data)) $match->score_away = $data['score_away'];
        if (isset($data['winner_team_id'])) $match->winner_team_id = $data['winner_team_id'];

        $match->status = 'completed';
        $match->completed_at = now();
        $match->save();

        return response()->json(['status'=>'success','match'=>$match]);
    }

    /**
     * Update score for a match (team vs team OR free for all)
     */
    public function updateScore(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $match = Event::where('tournament_id',$tournament->id)->where('id',$matchId)->first();
        if (! $match) return response()->json(['status'=>'error','message'=>'Match not found'], 404);

        if ($tournament->tournament_type === 'team vs team') {
            $data = $request->validate([
                'score_home' => 'required|integer',
                'score_away' => 'required|integer',
                'winner_team_id' => 'sometimes|nullable|integer|exists:teams,id',
            ]);

            $match->score_home = $data['score_home'];
            $match->score_away = $data['score_away'];

            // determine winner if not provided
            if (isset($data['winner_team_id'])) {
                $match->winner_team_id = $data['winner_team_id'];
            } else {
                if ($data['score_home'] > $data['score_away']) {
                    // attempt to set winner from assigned EventTeam ordering (first => home)
                    $teams = EventTeam::where('event_id', $match->id)->pluck('team_id')->toArray();
                    $match->winner_team_id = $teams[0] ?? null;
                } elseif ($data['score_away'] > $data['score_home']) {
                    $teams = EventTeam::where('event_id', $match->id)->pluck('team_id')->toArray();
                    $match->winner_team_id = $teams[1] ?? null;
                } else {
                    $match->winner_team_id = null; // draw
                }
            }

        } else {
            // free for all: accept an array of scores
            $data = $request->validate([
                'scores' => 'required|array|min:1',
                'scores.*.user_id' => 'required|integer|exists:users,id',
                'scores.*.score' => 'required|integer',
            ]);
            // store JSON summary into notes/meta (non-destructive)
            $match->meta = array_merge((array)$match->meta, ['last_scores' => $data['scores']]);
        }

        $match->save();

        return response()->json(['status'=>'success','match'=>$match]);
    }

    /**
     * Issue a penalty for a match
     */
    public function issuePenalty(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $match = Event::where('tournament_id',$tournament->id)->where('id',$matchId)->first();
        if (! $match) return response()->json(['status'=>'error','message'=>'Match not found'], 404);

        $data = $request->validate([
            'target_user_id' => 'sometimes|nullable|integer|exists:users,id',
            'target_team_id' => 'sometimes|nullable|integer|exists:teams,id',
            'penalty_data' => 'sometimes|array',
            'note' => 'nullable|string|max:1000',
        ]);

        $penalty = EventPenalty::create([
            'event_id' => $match->id,
            'issued_by' => $user->id,
            'target_user_id' => $data['target_user_id'] ?? null,
            'target_team_id' => $data['target_team_id'] ?? null,
            'penalty_data' => $data['penalty_data'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        // append to match notes if available
        if (!empty($data['note'])) {
            $match->notes = trim(($match->notes ?? '') . "\n[PENALTY] " . $data['note']);
            $match->save();
        }

        return response()->json(['status'=>'success','penalty'=>$penalty]);
    }

    /**
     * Mark a match forfeit. Accepts forfeiting_team_id OR forfeiting_user_id.
     */
    public function markForfeit(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $match = Event::where('tournament_id',$tournament->id)->where('id',$matchId)->first();
        if (! $match) return response()->json(['status'=>'error','message'=>'Match not found'], 404);

        $data = $request->validate([
            'forfeiting_team_id' => 'sometimes|nullable|integer|exists:teams,id',
            'forfeiting_user_id' => 'sometimes|nullable|integer|exists:users,id',
            'winner_team_id' => 'sometimes|nullable|integer|exists:teams,id',
            'note' => 'nullable|string|max:500',
        ]);

        $match->status = 'forfeited';
        $match->forfeited_at = now();
        if (isset($data['winner_team_id'])) $match->winner_team_id = $data['winner_team_id'];
        if (!empty($data['note'])) {
            $match->notes = trim(($match->notes ?? '') . "\n[FORFEIT] " . $data['note']);
        }
        $match->save();

        return response()->json(['status'=>'success','match'=>$match]);
    }

    /**
     * Upload result images/files for a match
     * Accepts multipart/form-data with field name "files[]" (multiple allowed)
     */
    public function uploadResult(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $match = Event::where('tournament_id',$tournament->id)->where('id',$matchId)->first();
        if (! $match) return response()->json(['status'=>'error','message'=>'Match not found'], 404);

        $request->validate([
            'files.*' => 'required|file|max:10240|mimes:jpg,jpeg,png,pdf',
            'meta' => 'sometimes|array',
        ]);

        $uploaded = [];
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store("tournaments/{$tournament->id}/events/{$match->id}/results");
                $res = EventResult::create([
                    'event_id' => $match->id,
                    'uploaded_by' => $user->id,
                    'file_path' => $path,
                    'meta' => $request->input('meta') ?? null,
                ]);
                $uploaded[] = $res;
            }
        }

        return response()->json(['status'=>'success','uploaded'=>$uploaded]);
    }
}
