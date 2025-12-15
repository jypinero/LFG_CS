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
use App\Models\TournamentPhase; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Booking;
use Illuminate\Validation\Rule;
use App\Models\EventResult;
use App\Models\EventPenalty;
use Illuminate\Support\Facades\Storage;
use App\Services\TournamentBracketGenerator; // added import

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

    public function index(Request $request)
    {
        $query = Tournament::with([
            'events' => function($query) {
                $query->where('is_approved', true);
            },
            'participants',
            'organizers.user',
            'documents',
            'analytics',
            'announcements'
        ]);

        // Filter by name
        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->name . '%');
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by tournament_type
        if ($request->filled('tournament_type')) {
            $query->where('tournament_type', $request->tournament_type);
        }

        // Filter by sport (via events)
        if ($request->filled('sport')) {
            $query->whereHas('events', function($q) use ($request) {
                $q->where('sport', $request->sport)
                  ->where('is_approved', true);
            });
        }

        // Filter by start date range
        if ($request->filled('start_date_from')) {
            $query->where('start_date', '>=', $request->start_date_from);
        }
        if ($request->filled('start_date_to')) {
            $query->where('start_date', '<=', $request->start_date_to);
        }

        // Filter by location (via venue)
        if ($request->filled('venue_id')) {
            $query->whereHas('events', function($q) use ($request) {
                $q->where('venue_id', $request->venue_id)
                  ->where('is_approved', true);
            });
        }

        // Filter by city (via venue)
        if ($request->filled('city')) {
            $query->whereHas('events.venue', function($q) use ($request) {
                $q->where('city', 'like', '%' . $request->city . '%');
            });
        }

        // Status-based visibility logic (better for tournament lifecycle)
        // - 'draft': Only visible to organizers (hide from public)
        // - 'open_registration' & 'registration_closed': Show even without games (registration phase)
        // - 'ongoing': Only show if it has games (in-progress tournaments should have matches)
        // - 'completed' & 'cancelled': Show regardless (historical tournaments)
        
        $userId = auth()->id();
        
        // Hide draft tournaments from non-organizers
        if (!$request->filled('include_draft')) {
            $query->where(function($q) use ($userId) {
                $q->where('status', '!=', 'draft')
                  ->orWhere(function($subQ) use ($userId) {
                      // Show drafts only to creators or organizers
                      $subQ->where('status', 'draft')
                           ->where(function($creatorOrOrganizerQ) use ($userId) {
                               $creatorOrOrganizerQ->where('created_by', $userId)
                                   ->orWhereHas('organizers', function($orgQ) use ($userId) {
                                       $orgQ->where('user_id', $userId);
                                   });
                           });
                  });
            });
        }
        
        // Only enforce "must have games" rule for ongoing tournaments
        // (Let registration-phase tournaments show even without games)
        $query->where(function($q) {
            $q->whereIn('status', ['open_registration', 'registration_closed', 'completed', 'cancelled'])
              ->orWhere(function($ongoingQ) {
                  // Ongoing tournaments must have at least one approved game
                  $ongoingQ->where('status', 'ongoing')
                           ->whereHas('events', function($eventQ) {
                               $eventQ->where('is_approved', true);
                           });
              });
        });

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSorts = ['created_at', 'start_date', 'name', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Pagination
        $perPage = min($request->input('per_page', 15), 100); // Max 100 per page
        $tournaments = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $tournaments->items(),
            'pagination' => [
                'current_page' => $tournaments->currentPage(),
                'last_page' => $tournaments->lastPage(),
                'per_page' => $tournaments->perPage(),
                'total' => $tournaments->total(),
                'from' => $tournaments->firstItem(),
                'to' => $tournaments->lastItem(),
            ],
            'count' => $tournaments->count()
        ]);
    }

    public function myTournaments(Request $request)
    {
        $user = auth()->user();
        
        $query = Tournament::with([
            'events' => function($query) {
                $query->where('is_approved', true);
            },
            'participants',
            'organizers.user',
            'documents',
            'analytics',
            'announcements'
        ])->where(function($q) use ($user) {
            $q->where('created_by', $user->id)
              ->orWhereHas('organizers', function($orgQ) use ($user) {
                  $orgQ->where('user_id', $user->id);
              });
        });
        
        // Add same filters, sorting, pagination as index method
        // ... (copy from index method)
        
        $perPage = min($request->input('per_page', 15), 100);
        $tournaments = $query->paginate($perPage);
        
        return response()->json([
            'status' => 'success',
            'data' => $tournaments->items(),
            'pagination' => [
                'current_page' => $tournaments->currentPage(),
                'last_page' => $tournaments->lastPage(),
                'per_page' => $tournaments->perPage(),
                'total' => $tournaments->total(),
            ],
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
            'location' => 'nullable|string|max:255',
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
            return response()->json(['status' => 'error', 'message' => 'Forbidden', 'tournament' => $tournament->id, 'user' => $user->id], 403);
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
            'location' => 'nullable|string|max:255',
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
                        ->whereIn('status', ['approved', 'pending', 'confirmed'])
                        ->count();
                    if ($count >= $tournament->max_teams) {
                        // Add to waitlist instead of rejecting
                        $waitlistPosition = \App\Models\TournamentWaitlist::where('tournament_id', $tournament->id)
                            ->max('position') + 1;
                        
                        \App\Models\TournamentWaitlist::create([
                            'tournament_id' => $tournament->id,
                            'team_id' => $teamId,
                            'user_id' => $user->id,
                            'participant_type' => 'team',
                            'position' => $waitlistPosition,
                            'joined_at' => now(),
                        ]);

                        return response()->json([
                            'status' => 'waitlisted',
                            'message' => 'Tournament is full. You have been added to the waitlist.',
                            'position' => $waitlistPosition
                        ], 202);
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
                    ->whereIn('status', ['approved', 'pending', 'confirmed'])
                    ->count();
                if ($count >= $tournament->max_teams) {
                    // Add to waitlist instead of rejecting
                    $waitlistPosition = \App\Models\TournamentWaitlist::where('tournament_id', $tournament->id)
                        ->max('position') + 1;
                    
                    \App\Models\TournamentWaitlist::create([
                        'tournament_id' => $tournament->id,
                        'user_id' => $user->id,
                        'team_id' => null,
                        'participant_type' => 'individual',
                        'position' => $waitlistPosition,
                        'joined_at' => now(),
                    ]);

                    return response()->json([
                        'status' => 'waitlisted',
                        'message' => 'Tournament is full. You have been added to the waitlist.',
                        'position' => $waitlistPosition
                    ], 202);
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
            'auto_advance' => 'sometimes|boolean', // Control auto-advancement
        ]);

        if (array_key_exists('score_home', $data)) $match->score_home = $data['score_home'];
        if (array_key_exists('score_away', $data)) $match->score_away = $data['score_away'];
        if (isset($data['winner_team_id'])) $match->winner_team_id = $data['winner_team_id'];

        $match->status = 'completed';
        $match->completed_at = now();
        $match->save();

        // Auto-advance winner to next match if enabled (default: true)
        $autoAdvance = $data['auto_advance'] ?? true;
        $advancedMatch = null;
        if ($autoAdvance && $match->is_tournament_game) {
            try {
                // Find corresponding TeamMatchup
                $teamMatchup = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
                    ->where('event_id', $match->id)
                    ->first();

                if ($teamMatchup && $teamMatchup->status !== 'completed') {
                    // Update matchup status and winner
                    $teamMatchup->status = 'completed';
                    $teamMatchup->completed_at = now();
                    if (isset($data['winner_team_id'])) {
                        $teamMatchup->winner_team_id = $data['winner_team_id'];
                    }
                    if (isset($data['score_home']) && isset($data['score_away'])) {
                        $teamMatchup->team_a_score = $data['score_home'];
                        $teamMatchup->team_b_score = $data['score_away'];
                    }
                    $teamMatchup->save();

                    // Advance winner
                    $generator = new TournamentBracketGenerator();
                    $advancedMatch = $generator->advanceWinner($teamMatchup->id);
                }
            } catch (\Throwable $e) {
                // Log error but don't fail the request
                \Log::warning('Failed to auto-advance bracket', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'status'=>'success',
            'match'=>$match,
            'advanced_to_next_match' => $advancedMatch ? true : false
        ]);
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

        // Find corresponding TeamMatchup for validation
        $teamMatchup = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
            ->where('event_id', $match->id)
            ->first();

        if ($tournament->tournament_type === 'team vs team') {
            $data = $request->validate([
                'score_home' => 'required|integer|min:0',
                'score_away' => 'required|integer|min:0',
                'winner_team_id' => 'sometimes|nullable|integer|exists:teams,id',
                'force_update' => 'sometimes|boolean',
            ]);

            // Validate match result
            if ($teamMatchup) {
                $validationErrors = \App\Rules\MatchResultValidation::validate($teamMatchup, $data, $tournament->tournament_type);
                if (!empty($validationErrors)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Validation failed',
                        'errors' => $validationErrors
                    ], 422);
                }
            }

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

            // Update TeamMatchup scores if exists
            if ($teamMatchup) {
                $teamMatchup->team_a_score = $data['score_home'];
                $teamMatchup->team_b_score = $data['score_away'];
                if ($match->winner_team_id) {
                    $teamMatchup->winner_team_id = $match->winner_team_id;
                }
                $teamMatchup->save();
            }

        } else {
            // free for all: accept an array of scores
            $data = $request->validate([
                'scores' => 'required|array|min:1',
                'scores.*.user_id' => 'required|integer|exists:users,id',
                'scores.*.score' => 'required|integer|min:0',
            ]);

            // Validate match result
            if ($teamMatchup) {
                $validationErrors = \App\Rules\MatchResultValidation::validate($teamMatchup, $data, $tournament->tournament_type);
                if (!empty($validationErrors)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Validation failed',
                        'errors' => $validationErrors
                    ], 422);
                }
            }

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

    /**
     * Generate brackets for an event
     * Body: { "type":"single_elimination", "options": { "shuffle": true } }
     */
    public function generateBrackets(Request $request, $tournamentId, $eventId)
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
            'type' => 'required|string|in:single_elimination,double_elimination,round_robin',
            'options' => 'sometimes|array',
        ]);

        $event = Event::where('tournament_id',$tournament->id)->where('id',$eventId)->first();
        if (! $event) return response()->json(['status'=>'error','message'=>'Event not found'], 404);

        try {
            $generator = new TournamentBracketGenerator();
            $matchups = $generator->generate($event, $data['type'], $data['options'] ?? []);
            
            // Load relations and format response with user names for free-for-all
            $matchups = $matchups->load(['tournament','event'])->map(function($m) use ($tournament) {
                $row = [
                    'id' => $m->id,
                    'tournament_id' => $m->tournament_id,
                    'event_id' => $m->event_id,
                    'round_number' => $m->round_number,
                    'match_number' => $m->match_number,
                    'match_stage' => $m->match_stage,
                    'team_a_id' => $m->team_a_id,
                    'team_b_id' => $m->team_b_id,
                    'winner_team_id' => $m->winner_team_id,
                    'status' => $m->status,
                    'team_a_score' => $m->team_a_score,
                    'team_b_score' => $m->team_b_score,
                    'scheduled_at' => $m->scheduled_at,
                    'started_at' => $m->started_at,
                    'completed_at' => $m->completed_at,
                    'notes' => $m->notes,
                    'penalties' => $m->penalties,
                    'meta' => $m->meta,
                    'created_at' => $m->created_at,
                    'updated_at' => $m->updated_at,
                ];

                // For team tournaments, include team names when available
                if ($tournament->tournament_type === 'team vs team') {
                    if ($m->team_a_id) {
                        $teamA = Team::find($m->team_a_id);
                        $row['team_a_name'] = $teamA ? ($teamA->name ?? ($teamA->display_name ?? null)) : null;
                    } else {
                        $row['team_a_name'] = null;
                    }
                    if ($m->team_b_id) {
                        $teamB = Team::find($m->team_b_id);
                        $row['team_b_name'] = $teamB ? ($teamB->name ?? ($teamB->display_name ?? null)) : null;
                    } else {
                        $row['team_b_name'] = null;
                    }
                }

                // For free-for-all, enrich meta with user names
                if ($tournament->tournament_type === 'free for all' && is_array($m->meta)) {
                    if (isset($m->meta['user_a_id'])) {
                        $userA = User::find($m->meta['user_a_id']);
                        $row['meta']['user_a_name'] = $userA ? $userA->first_name . ' ' . $userA->last_name : 'Unknown';
                    }
                    if (isset($m->meta['user_b_id'])) {
                        $userB = User::find($m->meta['user_b_id']);
                        $row['meta']['user_b_name'] = $userB ? $userB->first_name . ' ' . $userB->last_name : 'Unknown';
                    }
                }

                return $row;
            });

            return response()->json(['status'=>'success','bracket_type'=>$data['type'],'matchups'=>$matchups]);
        } catch (\Throwable $e) {
            \Log::error('generateBrackets failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['status'=>'error','message'=>$e->getMessage()], 422);
        }
    }

    protected function generateSingleElimination($tournamentId, $eventId, array $participants, $tournamentType = 'team vs team')
    {
        if (count($participants) < 2) {
            throw new \RuntimeException('Need at least 2 participants for single elimination');
        }

        $n = 1;
        while ($n < count($participants)) $n *= 2;
        while (count($participants) < $n) $participants[] = null;

        // Validate team/user existence before DB writes to avoid FK failures
        if ($tournamentType === 'team vs team') {
            $candidateTeamIds = array_values(array_filter($participants, fn($v) => $v !== null));
            $validTeamIds = Team::whereIn('id', $candidateTeamIds)->pluck('id')->map(fn($v) => (int)$v)->toArray();
            $missing = array_values(array_diff($candidateTeamIds, $validTeamIds));
            if (! empty($missing)) {
                throw new \RuntimeException('Missing team IDs: ' . implode(',', $missing));
            }
        } else {
            // for free-for-all you can optionally validate users similarly
            // (left out for brevity; add if you want strict validation)
        }
+
        DB::transaction(function() use ($tournamentId, $eventId, $participants, $tournamentType) {
            // clear existing
            TeamMatchup::where('tournament_id', $tournamentId)->where('event_id', $eventId)->delete();

            // If team tournament, verify team ids exist to avoid FK violations
            $validTeamIds = [];
            if ($tournamentType === 'team vs team') {
                $validTeamIds = \App\Models\Team::whereIn('id', array_filter($participants))->pluck('id')->values()->all();
            }

            $round = 1;
            $matchNumber = 1;
            for ($i = 0; $i < count($participants); $i += 2) {
                $p1 = $participants[$i] ?? null;
                $p2 = $participants[$i+1] ?? null;
                $status = ($p1 === null || $p2 === null) ? 'bye' : 'pending';

                if ($tournamentType === 'team vs team') {
                    // null out any ids that don't exist (shouldn't happen after validation)
                    $tA = in_array($p1, $validTeamIds, true) ? $p1 : null;
                    $tB = in_array($p2, $validTeamIds, true) ? $p2 : null;

                    TeamMatchup::create([
                        'tournament_id' => $tournamentId,
                        'event_id' => $eventId,
                        'round_number' => $round,
                        'match_number' => $matchNumber++,
                        'match_stage' => 'winners',
                        'team_a_id' => $tA,
                        'team_b_id' => $tB,
                        'status' => $status,
                        'meta' => [
                            'original_team_a' => $p1,
                            'original_team_b' => $p2,
                        ],
                    ]);
                } else {
                    // free-for-all: store user ids in meta, keep team_x null to satisfy FK
                    TeamMatchup::create([
                        'tournament_id' => $tournamentId,
                        'event_id' => $eventId,
                        'round_number' => $round,
                        'match_number' => $matchNumber++,
                        'match_stage' => 'winners',
                        'team_a_id' => null,
                        'team_b_id' => null,
                        'status' => $status,
                        'meta' => [
                            'user_a_id' => $p1,
                            'user_b_id' => $p2,
                        ],
                    ]);
                }
            }

            // placeholders for later rounds
            $matchesThisRound = intdiv(count($participants), 2);
            $round++;
            while ($matchesThisRound > 1) {
                $matchNumber = 1;
                for ($m = 0; $m < $matchesThisRound; $m++) {
                    TeamMatchup::create([
                        'tournament_id' => $tournamentId,
                        'event_id' => $eventId,
                        'round_number' => $round,
                        'match_number' => $matchNumber++,
                        'match_stage' => 'winners',
                        'team_a_id' => null,
                        'team_b_id' => null,
                        'status' => 'pending',
                    ]);
                }
                $matchesThisRound = intdiv($matchesThisRound, 2);
                $round++;
            }
        });

        return TeamMatchup::where('tournament_id', $tournamentId)
            ->where('event_id', $eventId)
            ->orderBy('match_stage')->orderBy('round_number')->orderBy('match_number')
            ->get();
    }

    /**
     * Add organizer to tournament
     */
    public function addOrganizer(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Only creator or owner organizers can add organizers
        $isCreator = $tournament->created_by === $user->id;
        $isOwnerOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();

        if (!$isCreator && !$isOwnerOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role' => ['required', Rule::in(['owner', 'organizer', 'viewer'])],
            'permissions' => 'sometimes|array',
        ]);

        // Check if user is already an organizer
        $existing = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $data['user_id'])
            ->exists();

        if ($existing) {
            return response()->json(['status' => 'error', 'message' => 'User is already an organizer'], 422);
        }

        // Prevent adding creator as organizer (they're already implicit owner)
        if ($data['user_id'] == $tournament->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Tournament creator is already an organizer'], 422);
        }

        $organizer = TournamentOrganizer::create([
            'tournament_id' => $tournament->id,
            'user_id' => $data['user_id'],
            'role' => $data['role'],
            'permissions' => $data['permissions'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'organizer' => $organizer->load('user'),
            'message' => 'Organizer added successfully'
        ], 201);
    }

    /**
     * Remove organizer from tournament
     */
    public function removeOrganizer(Request $request, $tournamentId, $userId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Only creator or owner organizers can remove organizers
        $isCreator = $tournament->created_by === $user->id;
        $isOwnerOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();

        if (!$isCreator && !$isOwnerOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $organizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $userId)
            ->first();

        if (!$organizer) {
            return response()->json(['status' => 'error', 'message' => 'Organizer not found'], 404);
        }

        // Prevent removing last owner (unless it's the creator)
        if ($organizer->role === 'owner' && $userId != $tournament->created_by) {
            $ownerCount = TournamentOrganizer::where('tournament_id', $tournament->id)
                ->where('role', 'owner')
                ->count();
            
            if ($ownerCount <= 1) {
                return response()->json(['status' => 'error', 'message' => 'Cannot remove last owner'], 422);
            }
        }

        $organizer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Organizer removed successfully'
        ]);
    }

    /**
     * List all organizers for a tournament
     */
    public function listOrganizers($tournamentId)
    {
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $organizers = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->with('user')
            ->get()
            ->map(function($org) use ($tournament) {
                return [
                    'id' => $org->id,
                    'user_id' => $org->user_id,
                    'user' => $org->user ? [
                        'id' => $org->user->id,
                        'username' => $org->user->username,
                        'email' => $org->user->email,
                        'first_name' => $org->user->first_name,
                        'last_name' => $org->user->last_name,
                    ] : null,
                    'role' => $org->role,
                    'permissions' => $org->permissions,
                    'created_at' => $org->created_at,
                ];
            });

        // Include creator as implicit owner
        $creator = User::find($tournament->created_by);
        $organizers->prepend([
            'id' => null,
            'user_id' => $tournament->created_by,
            'user' => $creator ? [
                'id' => $creator->id,
                'username' => $creator->username,
                'email' => $creator->email,
                'first_name' => $creator->first_name,
                'last_name' => $creator->last_name,
            ] : null,
            'role' => 'owner',
            'permissions' => null,
            'created_at' => $tournament->created_at,
            'is_creator' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'organizers' => $organizers,
            'count' => $organizers->count()
        ]);
    }

    /**
     * Update organizer role
     */
    public function updateOrganizerRole(Request $request, $tournamentId, $userId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Only creator or owner organizers can update roles
        $isCreator = $tournament->created_by === $user->id;
        $isOwnerOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();

        if (!$isCreator && !$isOwnerOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $organizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $userId)
            ->first();

        if (!$organizer) {
            return response()->json(['status' => 'error', 'message' => 'Organizer not found'], 404);
        }

        $data = $request->validate([
            'role' => ['sometimes', Rule::in(['owner', 'organizer', 'viewer'])],
            'permissions' => 'sometimes|array',
        ]);

        // Prevent removing last owner
        if (isset($data['role']) && $data['role'] !== 'owner' && $organizer->role === 'owner') {
            $ownerCount = TournamentOrganizer::where('tournament_id', $tournament->id)
                ->where('role', 'owner')
                ->count();
            
            if ($ownerCount <= 1) {
                return response()->json(['status' => 'error', 'message' => 'Cannot remove last owner'], 422);
            }
        }

        if (isset($data['role'])) {
            $organizer->role = $data['role'];
        }
        if (isset($data['permissions'])) {
            $organizer->permissions = $data['permissions'];
        }
        $organizer->save();

        return response()->json([
            'status' => 'success',
            'organizer' => $organizer->load('user'),
            'message' => 'Organizer role updated successfully'
        ]);
    }

    /**
     * Withdraw from tournament
     */
    public function withdraw(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Check if withdrawal is allowed
        $allowWithdrawal = data_get($tournament, 'settings.allow_withdrawal', false);
        $tournamentStarted = $tournament->status === 'ongoing' || $tournament->status === 'completed';
        
        if ($tournamentStarted && !$allowWithdrawal) {
            return response()->json([
                'status' => 'error',
                'message' => 'Withdrawal is not allowed after tournament has started'
            ], 422);
        }

        // Find participant (individual or team)
        $participant = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereHas('team', function($teamQuery) use ($user) {
                      $teamQuery->where('owner_id', $user->id);
                  });
            })
            ->whereIn('status', ['approved', 'pending', 'confirmed'])
            ->first();

        if (!$participant) {
            return response()->json(['status' => 'error', 'message' => 'You are not registered for this tournament'], 404);
        }

        // For team registrations, verify user is team owner or manager
        if ($participant->participant_type === 'team' && $participant->team_id) {
            $team = Team::find($participant->team_id);
            $isOwner = $team && $team->owner_id === $user->id;
            $isManager = TeamMember::where('team_id', $participant->team_id)
                ->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'manager'])
                ->exists();

            if (!$isOwner && !$isManager) {
                return response()->json(['status' => 'error', 'message' => 'Only team owner or manager can withdraw team'], 403);
            }
        }

        DB::beginTransaction();
        try {
            // Update participant status
            $participant->update([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
            ]);

            // Update event participants
            $epQuery = EventParticipant::where('tournament_id', $tournament->id);
            if ($participant->participant_type === 'individual') {
                $epQuery->where('user_id', $participant->user_id);
            } else {
                $epQuery->where('team_id', $participant->team_id);
            }
            $epQuery->update(['status' => 'withdrawn']);

            // Handle matches
            if ($participant->participant_type === 'team') {
                $teamId = $participant->team_id;
                $matches = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
                    ->where(function($q) use ($teamId) {
                        $q->where('team_a_id', $teamId)
                          ->orWhere('team_b_id', $teamId);
                    })
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->get();

                foreach ($matches as $match) {
                    if ($match->status === 'pending') {
                        $match->update(['status' => 'bye']);
                    } elseif ($match->status === 'in_progress') {
                        // Mark as forfeit
                        $opponentId = $match->team_a_id == $teamId ? $match->team_b_id : $match->team_a_id;
                        $match->update([
                            'status' => 'forfeited',
                            'winner_team_id' => $opponentId,
                            'completed_at' => now(),
                        ]);
                    }
                }
            } else {
                // For free-for-all, handle user matches via meta
                $matches = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->whereJsonContains('meta->user_a_id', $participant->user_id)
                    ->orWhereJsonContains('meta->user_b_id', $participant->user_id)
                    ->get();

                foreach ($matches as $match) {
                    if ($match->status === 'pending') {
                        $match->update(['status' => 'bye']);
                    } elseif ($match->status === 'in_progress') {
                        $meta = $match->meta ?? [];
                        $opponentId = ($meta['user_a_id'] ?? null) == $participant->user_id 
                            ? ($meta['user_b_id'] ?? null) 
                            : ($meta['user_a_id'] ?? null);
                        
                        $meta['winner_user_id'] = $opponentId;
                        $match->update([
                            'status' => 'forfeited',
                            'meta' => $meta,
                            'completed_at' => now(),
                        ]);
                    }
                }
            }

            // Decrement analytics
            if ($participant->participant_type === 'team') {
                $tournament->analytics?->decrement('total_teams');
                $memberCount = TeamMember::where('team_id', $participant->team_id)
                    ->where('roster_status', 'active')
                    ->count();
                $tournament->analytics?->decrement('total_participants', $memberCount);
            } else {
                $tournament->analytics?->decrement('total_participants');
            }

            // Auto-promote from waitlist if available
            if (class_exists(\App\Models\TournamentWaitlist::class)) {
                $waitlistEntry = \App\Models\TournamentWaitlist::where('tournament_id', $tournament->id)
                    ->orderBy('position')
                    ->orderBy('joined_at')
                    ->first();

                if ($waitlistEntry) {
                    // Promote waitlist entry
                    $waitlistEntry->delete();
                    
                    // Create participant from waitlist
                    if ($waitlistEntry->participant_type === 'team') {
                        $newParticipant = TournamentParticipant::create([
                            'tournament_id' => $tournament->id,
                            'team_id' => $waitlistEntry->team_id,
                            'user_id' => Team::find($waitlistEntry->team_id)?->owner_id,
                            'type' => 'team',
                            'participant_type' => 'team',
                            'status' => $tournament->requires_documents ? 'pending' : 'approved',
                            'registered_at' => now(),
                        ]);
                    } else {
                        $newParticipant = TournamentParticipant::create([
                            'tournament_id' => $tournament->id,
                            'user_id' => $waitlistEntry->user_id,
                            'type' => 'individual',
                            'participant_type' => 'individual',
                            'status' => $tournament->requires_documents ? 'pending' : 'approved',
                            'registered_at' => now(),
                        ]);
                    }

                    // Send notification to promoted participant
                    $notifyUserId = $waitlistEntry->participant_type === 'individual' 
                        ? $waitlistEntry->user_id
                        : Team::find($waitlistEntry->team_id)?->owner_id;

                    if ($notifyUserId) {
                        $notification = Notification::create([
                            'type' => 'waitlist_promoted',
                            'data' => [
                                'tournament_id' => $tournament->id,
                                'tournament_name' => $tournament->name,
                                'participant_id' => $newParticipant->id,
                                'message' => "You have been promoted from the waitlist for {$tournament->name}",
                            ],
                            'created_by' => $user->id,
                        ]);
                        UserNotification::create([
                            'notification_id' => $notification->id,
                            'user_id' => $notifyUserId,
                            'is_read' => false,
                        ]);
                    }
                }
            }

            // Send notification to organizers
            $organizers = TournamentOrganizer::where('tournament_id', $tournament->id)
                ->pluck('user_id')
                ->toArray();
            $organizers[] = $tournament->created_by;
            $organizers = array_unique($organizers);

            foreach ($organizers as $orgId) {
                $notification = Notification::create([
                    'type' => 'participant_withdrawn',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'participant_id' => $participant->id,
                        'participant_type' => $participant->participant_type,
                        'message' => "A {$participant->participant_type} has withdrawn from {$tournament->name}",
                    ],
                    'created_by' => $user->id,
                ]);
                UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $orgId,
                    'is_read' => false,
                ]);
            }

            DB::commit();
            return response()->json([
                'status' => 'success',
                'message' => 'Successfully withdrawn from tournament'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to withdraw from tournament',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually advance bracket after match completion
     */
    public function advanceBracket(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // Find TeamMatchup for this event/match
        $teamMatchup = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
            ->where('event_id', $matchId)
            ->first();

        if (!$teamMatchup) {
            return response()->json(['status' => 'error', 'message' => 'Match not found in bracket'], 404);
        }

        if ($teamMatchup->status !== 'completed') {
            return response()->json(['status' => 'error', 'message' => 'Match must be completed before advancing'], 422);
        }

        try {
            $generator = new TournamentBracketGenerator();
            $nextMatch = $generator->advanceWinner($teamMatchup->id);

            if (!$nextMatch) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Match advanced (no next match - may be final)'
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Winner advanced to next match',
                'next_match' => $nextMatch
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to advance bracket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Join tournament waitlist
     */
    public function joinWaitlist(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        if ($tournament->status !== 'open_registration') {
            return response()->json(['status' => 'error', 'message' => 'Registration is not open'], 422);
        }

        $data = $request->validate([
            'team_id' => 'sometimes|nullable|exists:teams,id',
        ]);

        $teamId = $data['team_id'] ?? null;

        // Check if already registered or waitlisted
        if ($teamId) {
            $exists = TournamentParticipant::where('tournament_id', $tournament->id)
                ->where('team_id', $teamId)
                ->exists();
            if ($exists) {
                return response()->json(['status' => 'error', 'message' => 'Team already registered'], 422);
            }

            $waitlistExists = \App\Models\TournamentWaitlist::where('tournament_id', $tournament->id)
                ->where('team_id', $teamId)
                ->exists();
            if ($waitlistExists) {
                return response()->json(['status' => 'error', 'message' => 'Team already on waitlist'], 422);
            }
        } else {
            $exists = TournamentParticipant::where('tournament_id', $tournament->id)
                ->where('user_id', $user->id)
                ->exists();
            if ($exists) {
                return response()->json(['status' => 'error', 'message' => 'Already registered'], 422);
            }

            $waitlistExists = \App\Models\TournamentWaitlist::where('tournament_id', $tournament->id)
                ->where('user_id', $user->id)
                ->exists();
            if ($waitlistExists) {
                return response()->json(['status' => 'error', 'message' => 'Already on waitlist'], 422);
            }
        }

        $waitlistPosition = \App\Models\TournamentWaitlist::where('tournament_id', $tournament->id)
            ->max('position') + 1;

        $waitlist = \App\Models\TournamentWaitlist::create([
            'tournament_id' => $tournament->id,
            'user_id' => $teamId ? null : $user->id,
            'team_id' => $teamId,
            'participant_type' => $teamId ? 'team' : 'individual',
            'position' => $waitlistPosition,
            'joined_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'waitlist' => $waitlist,
            'position' => $waitlistPosition,
            'message' => 'Added to waitlist'
        ], 201);
    }

    /**
     * Remove self from waitlist
     */
    public function removeFromWaitlist($tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $waitlist = \App\Models\TournamentWaitlist::where('tournament_id', $tournament->id)
            ->where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereHas('team', function($teamQuery) use ($user) {
                      $teamQuery->where('owner_id', $user->id);
                  });
            })
            ->first();

        if (!$waitlist) {
            return response()->json(['status' => 'error', 'message' => 'Not on waitlist'], 404);
        }

        $waitlist->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Removed from waitlist'
        ]);
    }

    /**
     * Get waitlist (organizers only)
     */
    public function getWaitlist($tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $waitlist = \App\Models\TournamentWaitlist::where('tournament_id', $tournament->id)
            ->with(['user', 'team'])
            ->orderBy('position')
            ->orderBy('joined_at')
            ->get()
            ->map(function($entry) {
                return [
                    'id' => $entry->id,
                    'position' => $entry->position,
                    'participant_type' => $entry->participant_type,
                    'user' => $entry->user ? [
                        'id' => $entry->user->id,
                        'username' => $entry->user->username,
                        'email' => $entry->user->email,
                    ] : null,
                    'team' => $entry->team ? [
                        'id' => $entry->team->id,
                        'name' => $entry->team->name,
                    ] : null,
                    'joined_at' => $entry->joined_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'waitlist' => $waitlist,
            'count' => $waitlist->count()
        ]);
    }

    /**
     * Manually promote from waitlist
     */
    public function promoteFromWaitlist(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'waitlist_id' => 'required|integer|exists:tournament_waitlist,id',
        ]);

        $waitlistEntry = \App\Models\TournamentWaitlist::where('id', $data['waitlist_id'])
            ->where('tournament_id', $tournament->id)
            ->first();

        if (!$waitlistEntry) {
            return response()->json(['status' => 'error', 'message' => 'Waitlist entry not found'], 404);
        }

        // Check if tournament has available slots
        if ($tournament->max_teams) {
            $count = TournamentParticipant::where('tournament_id', $tournament->id)
                ->where('participant_type', $waitlistEntry->participant_type)
                ->whereIn('status', ['approved', 'pending', 'confirmed'])
                ->count();
            if ($count >= $tournament->max_teams) {
                return response()->json(['status' => 'error', 'message' => 'Tournament is still full'], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Create participant
            $tpStatus = $tournament->requires_documents ? 'pending' : 'approved';
            $participant = TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $waitlistEntry->user_id,
                'team_id' => $waitlistEntry->team_id,
                'type' => $waitlistEntry->participant_type,
                'participant_type' => $waitlistEntry->participant_type,
                'status' => $tpStatus,
                'registered_at' => now(),
            ]);

            // Remove from waitlist
            $waitlistEntry->delete();

            // Send notification
            $notifyUserId = $waitlistEntry->participant_type === 'individual' 
                ? $waitlistEntry->user_id
                : Team::find($waitlistEntry->team_id)?->owner_id;

            if ($notifyUserId) {
                $notification = Notification::create([
                    'type' => 'waitlist_promoted',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'participant_id' => $participant->id,
                        'message' => "You have been promoted from the waitlist for {$tournament->name}",
                    ],
                    'created_by' => $user->id,
                ]);
                UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $notifyUserId,
                    'is_read' => false,
                ]);
            }

            DB::commit();
            return response()->json([
                'status' => 'success',
                'participant' => $participant,
                'message' => 'Promoted from waitlist'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to promote from waitlist',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create phase for tournament event
     */
    public function createPhase(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'event_id' => 'required|exists:events,id',
            'phase_name' => 'required|string|max:255',
            'order' => 'sometimes|integer|min:1',
        ]);

        // Validate event belongs to tournament
        $event = Event::where('id', $data['event_id'])
            ->where('tournament_id', $tournament->id)
            ->first();

        if (!$event) {
            return response()->json(['status' => 'error', 'message' => 'Event not found in tournament'], 404);
        }

        // Auto-increment order if not provided
        if (!isset($data['order'])) {
            $maxOrder = TournamentPhase::where('event_id', $data['event_id'])->max('order') ?? 0;
            $data['order'] = $maxOrder + 1;
        }

        $phase = TournamentPhase::create($data);

        return response()->json([
            'status' => 'success',
            'phase' => $phase,
            'message' => 'Phase created successfully'
        ], 201);
    }

    /**
     * List phases for tournament events
     */
    public function listPhases($tournamentId)
    {
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Get phases for all tournament events
        $eventIds = Event::where('tournament_id', $tournament->id)->pluck('id');
        $phases = TournamentPhase::whereIn('event_id', $eventIds)
            ->with('event')
            ->orderBy('event_id')
            ->orderBy('order')
            ->get()
            ->groupBy('event_id')
            ->map(function($eventPhases, $eventId) {
                return [
                    'event_id' => $eventId,
                    'event_name' => Event::find($eventId)?->name,
                    'phases' => $eventPhases->map(function($phase) {
                        return [
                            'id' => $phase->id,
                            'phase_name' => $phase->phase_name,
                            'order' => $phase->order,
                            'created_at' => $phase->created_at,
                        ];
                    })->values(),
                ];
            })->values();

        return response()->json([
            'status' => 'success',
            'phases' => $phases,
            'count' => TournamentPhase::whereIn('event_id', $eventIds)->count()
        ]);
    }

    /**
     * Update phase
     */
    public function updatePhase(Request $request, $tournamentId, $phaseId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $phase = TournamentPhase::find($phaseId);
        if (!$phase) {
            return response()->json(['status' => 'error', 'message' => 'Phase not found'], 404);
        }

        // Validate phase belongs to tournament event
        $event = Event::where('id', $phase->event_id)
            ->where('tournament_id', $tournament->id)
            ->first();

        if (!$event) {
            return response()->json(['status' => 'error', 'message' => 'Phase does not belong to tournament'], 404);
        }

        $data = $request->validate([
            'phase_name' => 'sometimes|string|max:255',
            'order' => 'sometimes|integer|min:1',
        ]);

        $phase->update($data);

        return response()->json([
            'status' => 'success',
            'phase' => $phase,
            'message' => 'Phase updated successfully'
        ]);
    }

    /**
     * Delete phase
     */
    public function deletePhase($tournamentId, $phaseId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $phase = TournamentPhase::find($phaseId);
        if (!$phase) {
            return response()->json(['status' => 'error', 'message' => 'Phase not found'], 404);
        }

        // Validate phase belongs to tournament event
        $event = Event::where('id', $phase->event_id)
            ->where('tournament_id', $tournament->id)
            ->first();

        if (!$event) {
            return response()->json(['status' => 'error', 'message' => 'Phase does not belong to tournament'], 404);
        }

        $phase->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Phase deleted successfully'
        ]);
    }

    /**
     * Reorder phases
     */
    public function reorderPhases(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'phases' => 'required|array',
            'phases.*.id' => 'required|integer|exists:tournament_phases,id',
            'phases.*.order' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            foreach ($data['phases'] as $phaseData) {
                $phase = TournamentPhase::find($phaseData['id']);
                if ($phase) {
                    // Validate phase belongs to tournament event
                    $event = Event::where('id', $phase->event_id)
                        ->where('tournament_id', $tournament->id)
                        ->first();

                    if ($event) {
                        $phase->update(['order' => $phaseData['order']]);
                    }
                }
            }

            DB::commit();
            return response()->json([
                'status' => 'success',
                'message' => 'Phases reordered successfully'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reorder phases',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dispute match result
     */
    public function disputeResult(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        // Find TeamMatchup
        $teamMatchup = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
            ->where('event_id', $matchId)
            ->first();

        if (!$teamMatchup) {
            return response()->json(['status' => 'error', 'message' => 'Match not found'], 404);
        }

        // Check if user is participant in this match
        $isParticipant = false;
        if ($tournament->tournament_type === 'team vs team') {
            $userTeams = TeamMember::where('user_id', $user->id)
                ->whereIn('team_id', [$teamMatchup->team_a_id, $teamMatchup->team_b_id])
                ->exists();
            $isParticipant = $userTeams;
        } else {
            $meta = $teamMatchup->meta ?? [];
            $isParticipant = ($meta['user_a_id'] ?? null) == $user->id || ($meta['user_b_id'] ?? null) == $user->id;
        }

        if (!$isParticipant) {
            return response()->json(['status' => 'error', 'message' => 'You are not a participant in this match'], 403);
        }

        $teamMatchup->update([
            'is_disputed' => true,
            'dispute_reason' => $data['reason'],
            'disputed_at' => now(),
        ]);

        // Notify organizers
        $organizers = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->pluck('user_id')
            ->toArray();
        $organizers[] = $tournament->created_by;
        $organizers = array_unique($organizers);

        foreach ($organizers as $orgId) {
            $notification = Notification::create([
                'type' => 'match_disputed',
                'data' => [
                    'tournament_id' => $tournament->id,
                    'match_id' => $matchId,
                    'reason' => $data['reason'],
                    'message' => "Match result has been disputed",
                ],
                'created_by' => $user->id,
            ]);
            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $orgId,
                'is_read' => false,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Match result disputed. Organizers have been notified.'
        ]);
    }

    /**
     * Resolve dispute (organizers only)
     */
    public function resolveDispute(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'resolution' => 'required|string|max:1000',
            'keep_result' => 'sometimes|boolean', // true = keep current result, false = needs update
        ]);

        $teamMatchup = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
            ->where('event_id', $matchId)
            ->first();

        if (!$teamMatchup) {
            return response()->json(['status' => 'error', 'message' => 'Match not found'], 404);
        }

        if (!$teamMatchup->is_disputed) {
            return response()->json(['status' => 'error', 'message' => 'Match is not disputed'], 422);
        }

        $teamMatchup->update([
            'is_disputed' => false,
            'dispute_reason' => $teamMatchup->dispute_reason . "\n[RESOLVED] " . $data['resolution'],
            'disputed_at' => null,
        ]);

        // Add resolution note
        $notes = $teamMatchup->notes ?? '';
        $teamMatchup->notes = $notes . "\n[DISPUTE RESOLUTION] " . $data['resolution'];
        $teamMatchup->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Dispute resolved',
                'match' => $teamMatchup
            ]);
        }

    /**
     * Open tournament registration
     */
    public function openRegistration(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        if ($tournament->status !== 'draft') {
            return response()->json(['status' => 'error', 'message' => 'Only draft tournaments can open registration'], 422);
        }

        $tournament->update(['status' => 'open_registration']);

        // Send notifications to interested parties if needed
        // (optional enhancement)

        return response()->json([
            'status' => 'success',
            'message' => 'Registration opened successfully',
            'tournament' => $tournament
        ]);
    }

    /**
     * Close tournament registration
     */
    public function closeRegistration(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        if ($tournament->status !== 'open_registration') {
            return response()->json(['status' => 'error', 'message' => 'Registration is not open'], 422);
        }

        $tournament->update(['status' => 'registration_closed']);

        // Send notifications to participants
        $participants = TournamentParticipant::where('tournament_id', $tournament->id)
            ->whereIn('status', ['approved', 'pending'])
            ->get();

        foreach ($participants as $participant) {
            $userId = $participant->participant_type === 'individual' 
                ? $participant->user_id 
                : Team::find($participant->team_id)?->owner_id;

            if ($userId) {
                $notification = Notification::create([
                    'type' => 'tournament_registration_closed',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'message' => "Registration closed for tournament: {$tournament->name}",
                    ],
                    'created_by' => $user->id,
                ]);
                UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'is_read' => false,
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Registration closed successfully',
            'tournament' => $tournament
        ]);
    }

    /**
     * Start tournament
     */
    public function startTournament(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        if ($tournament->status !== 'registration_closed') {
            return response()->json(['status' => 'error', 'message' => 'Tournament must have registration closed before starting'], 422);
        }

        // Validate minimum participants
        $approvedCount = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('status', 'approved')
            ->count();

        $min = (int)($tournament->min_teams ?? 2);
        if ($approvedCount < $min) {
            return response()->json([
                'status' => 'error',
                'message' => "Need at least {$min} approved participants to start. Currently have {$approvedCount}."
            ], 422);
        }

        $tournament->update(['status' => 'ongoing']);

        // Send notifications to participants
        $participants = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('status', 'approved')
            ->get();

        foreach ($participants as $participant) {
            $userId = $participant->participant_type === 'individual' 
                ? $participant->user_id 
                : Team::find($participant->team_id)?->owner_id;

            if ($userId) {
                $notification = Notification::create([
                    'type' => 'tournament_started',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'message' => "Tournament {$tournament->name} has started!",
                    ],
                    'created_by' => $user->id,
                ]);
                UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'is_read' => false,
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Tournament started successfully',
            'tournament' => $tournament
        ]);
    }

    /**
     * Complete tournament
     */
    public function completeTournament(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        if ($tournament->status !== 'ongoing') {
            return response()->json(['status' => 'error', 'message' => 'Tournament must be ongoing to complete'], 422);
        }

        // Validate all matches are completed
        $hasOpenMatches = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
            ->whereNotIn('status', ['completed', 'cancelled', 'forfeited'])
            ->exists();

        if ($hasOpenMatches) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot complete tournament with unfinished matches. Please complete or cancel all matches first.'
            ], 422);
        }

        $tournament->update(['status' => 'completed']);

        // Send completion notifications to participants
        $participants = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('status', 'approved')
            ->get();

        foreach ($participants as $participant) {
            $userId = $participant->participant_type === 'individual' 
                ? $participant->user_id 
                : Team::find($participant->team_id)?->owner_id;

            if ($userId) {
                $notification = Notification::create([
                    'type' => 'tournament_completed',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'message' => "Tournament {$tournament->name} has been completed!",
                    ],
                    'created_by' => $user->id,
                ]);
                UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'is_read' => false,
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Tournament completed successfully',
            'tournament' => $tournament
        ]);
    }

    /**
     * Cancel tournament
     */
    public function cancelTournament(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOwnerOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();

        if (!$isCreator && !$isOwnerOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // Prevent cancellation if already completed
        if ($tournament->status === 'completed') {
            return response()->json(['status' => 'error', 'message' => 'Cannot cancel completed tournament'], 422);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:1000',
            'cancel_events' => 'sometimes|boolean', // Whether to cancel future events
        ]);

        DB::beginTransaction();
        try {
            // Update tournament status
            $tournament->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $data['reason'],
            ]);

            // Cancel future matches/events if requested
            if ($data['cancel_events'] ?? true) {
                Event::where('tournament_id', $tournament->id)
                    ->where('date', '>=', now()->toDateString())
                    ->where('is_tournament_game', true)
                    ->update(['cancelled_at' => now()]);

                // Cancel future matchups
                \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
                    ->whereIn('status', ['pending', 'scheduled'])
                    ->update(['status' => 'cancelled']);
            }

            // Send notifications to all participants
            $participants = TournamentParticipant::where('tournament_id', $tournament->id)
                ->whereIn('status', ['approved', 'pending', 'confirmed'])
                ->get();

            $notifyUserIds = [];
            foreach ($participants as $participant) {
                if ($participant->participant_type === 'individual') {
                    $notifyUserIds[] = $participant->user_id;
                } else {
                    $teamOwnerId = Team::find($participant->team_id)?->owner_id;
                    if ($teamOwnerId) {
                        $notifyUserIds[] = $teamOwnerId;
                    }
                }
            }
            $notifyUserIds = array_unique($notifyUserIds);

            foreach ($notifyUserIds as $userId) {
                $notification = Notification::create([
                    'type' => 'tournament_cancelled',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'reason' => $data['reason'],
                        'message' => "Tournament {$tournament->name} has been cancelled",
                    ],
                    'created_by' => $user->id,
                ]);
                UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'is_read' => false,
                ]);
            }

            DB::commit();
            return response()->json([
                'status' => 'success',
                'message' => 'Tournament cancelled successfully',
                'tournament' => $tournament
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel tournament',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create tournament template from existing tournament
     */
    public function createTemplate(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'sometimes|boolean',
        ]);

        $tournament = Tournament::find($data['tournament_id']);

        // Only creator can create template
        if ($tournament->created_by !== $user->id) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // Get phases for default_phases
        $eventIds = Event::where('tournament_id', $tournament->id)->pluck('id');
        $phases = TournamentPhase::whereIn('event_id', $eventIds)
            ->get()
            ->map(function($phase) {
                return [
                    'phase_name' => $phase->phase_name,
                    'order' => $phase->order,
                ];
            })
            ->toArray();

        $template = \App\Models\TournamentTemplate::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $tournament->type,
            'tournament_type' => $tournament->tournament_type,
            'settings' => $tournament->settings ?? [],
            'default_phases' => $phases,
            'created_by' => $user->id,
            'is_public' => $data['is_public'] ?? false,
        ]);

        return response()->json([
            'status' => 'success',
            'template' => $template,
            'message' => 'Template created successfully'
        ], 201);
    }

    /**
     * List available templates
     */
    public function listTemplates(Request $request)
    {
        $user = auth()->user();

        $query = \App\Models\TournamentTemplate::with('creator')
            ->where(function($q) use ($user) {
                $q->where('is_public', true)
                  ->orWhere('created_by', $user->id);
            });

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('tournament_type')) {
            $query->where('tournament_type', $request->tournament_type);
        }

        $templates = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'templates' => $templates,
            'count' => $templates->count()
        ]);
    }

    /**
     * Create tournament from template
     */
    public function createFromTemplate(Request $request, $templateId)
    {
        $user = auth()->user();
        $template = \App\Models\TournamentTemplate::find($templateId);

        if (!$template) {
            return response()->json(['status' => 'error', 'message' => 'Template not found'], 404);
        }

        // Check if template is public or user's own
        if (!$template->is_public && $template->created_by !== $user->id) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'registration_deadline' => 'nullable|date',
            'max_teams' => 'nullable|integer|min:1',
            'min_teams' => 'nullable|integer|min:1',
            'registration_fee' => 'nullable|numeric|min:0',
            'rules' => 'nullable|string',
            'prizes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $tournament = Tournament::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? $template->description,
                'type' => $template->type,
                'tournament_type' => $template->tournament_type,
                'created_by' => $user->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'registration_deadline' => $data['registration_deadline'] ?? null,
                'status' => 'draft',
                'settings' => $template->settings,
                'max_teams' => $data['max_teams'] ?? null,
                'min_teams' => $data['min_teams'] ?? null,
                'registration_fee' => $data['registration_fee'] ?? null,
                'rules' => $data['rules'] ?? null,
                'prizes' => $data['prizes'] ?? null,
            ]);

            // Add creator as owner organizer
            TournamentOrganizer::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'permissions' => null,
            ]);

            // Initialize analytics
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

            return response()->json([
                'status' => 'success',
                'tournament' => $tournament,
                'message' => 'Tournament created from template successfully'
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create tournament from template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update template
     */
    public function updateTemplate(Request $request, $templateId)
    {
        $user = auth()->user();
        $template = \App\Models\TournamentTemplate::find($templateId);

        if (!$template) {
            return response()->json(['status' => 'error', 'message' => 'Template not found'], 404);
        }

        if ($template->created_by !== $user->id) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
        ]);

        $template->update($data);

        return response()->json([
            'status' => 'success',
            'template' => $template,
            'message' => 'Template updated successfully'
        ]);
    }

    /**
     * Delete template
     */
    public function deleteTemplate($templateId)
    {
        $user = auth()->user();
        $template = \App\Models\TournamentTemplate::find($templateId);

        if (!$template) {
            return response()->json(['status' => 'error', 'message' => 'Template not found'], 404);
        }

        if ($template->created_by !== $user->id) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $template->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Template deleted successfully'
        ]);
    }
}
