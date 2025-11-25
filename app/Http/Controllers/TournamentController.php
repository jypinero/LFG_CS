<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\TournamentAnalytics;
use App\Models\Event;                // added
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Booking;
use Illuminate\Validation\Rule;

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
        $events = Event::with(['venue.photos', 'facility', 'teams.team'])
            ->where('is_approved', true)
            ->where('event_type', 'tournament') // only tournament events
            ->withCount('participants')
            ->get()
            ->map(function($event) {
                // Calculate hours
                $start = Carbon::parse($event->start_time);
                $end = Carbon::parse($event->end_time);
                $hours = $start->diffInMinutes($end) / 60;

                // Calculate total cost
                $pricePerHour = $event->facility->price_per_hr ?? 0;
                $totalCost = $hours * $pricePerHour;

                $participantsCount = $event->participants_count ?? 0;
                $divide = $participantsCount > 0 ? ($totalCost / $participantsCount) : 0;
                $dividedpay = round($divide, 2);
                
                // Determine venue primary photo url (latest upload if available)
                $firstPhotoPath = null;
                if ($event->venue && $event->venue->photos && $event->venue->photos->count() > 0) {
                    $firstPhoto = $event->venue->photos->sortByDesc('uploaded_at')->first();
                    $firstPhotoPath = $firstPhoto ? $firstPhoto->image_path : null;
                }
                $venuePhotoUrl = $firstPhotoPath ? url('storage/' . ltrim($firstPhotoPath, '/')) : null;

                $eventData = [
                    'id' => $event->id,
                    'name' => $event->name,
                    'description' => $event->description,
                    'sport' => $event->sport,
                    'date' => $event->date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'participants_count' => $participantsCount,
                    'total_slots' => $event->slots,
                    'venue' => $event->venue->name ?? null,
                    'facility' => $event->facility->type ?? null,
                    'longitude' => $event->venue->longitude ?? null,
                    'latitude' => $event->venue->latitude ?? null,
                    'venue_photo_url' => $venuePhotoUrl,
                    'hours' => $hours,
                    'total_cost' => $totalCost,
                    'cost_per_slot' => $dividedpay,
                    'host' => optional(User::find($event->created_by))->username,
                    'event_type' => $event->event_type,
                    'is_approved' => (bool) $event->is_approved,
                    'approval_status' => $event->is_approved ? 'approved' : 'pending',
                    'approved_at' => $event->approved_at,
                    'tournament_id' => $event->tournament_id,
                    'game_number' => $event->game_number,
                    'game_status' => $event->game_status,
                    'is_tournament_game' => $event->tournament_id ? true : false,
                ];

                // Add team information for team vs team events
                if ($event->event_type === 'tournament') {
                    $eventData['teams'] = $event->teams->map(function($eventTeam) {
                        return [
                            'team_id' => $eventTeam->team_id,
                            'team_name' => $eventTeam->team->name ?? 'Unknown Team',
                        ];
                    });
                    $eventData['teams_count'] = $event->teams->count();
                    $eventData['slots_display'] = $event->teams->count() . '/' . $event->slots . ' teams';
                } else {
                    $eventData['slots_display'] = $participantsCount . '/' . $event->slots . ' participants';
                }

                return $eventData;
            });

        return response()->json([
            'status' => 'success',
            'events' => $events
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
                // generate 4-char code to match small DB column
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

            // enroll creating user as participant when EventParticipant model exists
            if (class_exists(\App\Models\EventParticipant::class)) {
                \App\Models\EventParticipant::create([
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'status' => 'confirmed',
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
}
