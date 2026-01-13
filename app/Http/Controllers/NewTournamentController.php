<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentOrganizer;
use App\Models\TournamentDocument;
use App\Models\TournamentAnnouncement;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventGame;
use App\Models\Team;
use App\Models\User;
use App\Models\Notification;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Services\ChallongeService;
use App\Services\ChallongeOauthService;

class NewTournamentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function create(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['single_sport', 'multisport'])],
            'tournament_type' => ['required', Rule::in(['free for all', 'team vs team'])],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'registration_deadline' => 'nullable|date|before_or_equal:start_date',
            'status' => ['required', Rule::in(['draft', 'open_registration'])],
            'location' => 'nullable|string|max:255',
            'rules' => 'nullable|string',
            'max_teams' => 'nullable|integer|min:2',
            'min_teams' => 'nullable|integer|min:2',
            'photo' => 'sometimes|file|image|max:5120|mimes:jpg,jpeg,png',
        ]);

        DB::beginTransaction();

        try {
            $tournament = Tournament::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'tournament_type' => $data['tournament_type'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'registration_deadline' => $data['registration_deadline'] ?? null,
                'status' => $data['status'],
                'location' => $data['location'] ?? null,
                'rules' => $data['rules'] ?? null,
                'max_teams' => $data['max_teams'] ?? null,
                'min_teams' => $data['min_teams'] ?? 2,
                'created_by' => $user->id,
            ]);

            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $path = $file->store("public/tournaments/{$tournament->id}/photo");
                $tournament->photo = str_replace('public/', '', $path);
                $tournament->save();
            }

            TournamentOrganizer::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'permissions' => null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Tournament created successfully',
                'tournament' => $tournament,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create tournament',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Tournament::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('tournament_type')) {
            $query->where('tournament_type', $request->tournament_type);
        }

        $tournaments = $query->orderBy('start_date')->paginate(10);

        return response()->json($tournaments);
    }

    /**
     * Register participant (player or team) with event handling
     */
    public function registerParticipant(Request $request, $tournamentId)
    {
        $user = auth()->user();

        $tournament = Tournament::findOrFail($tournamentId);

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'team_id' => 'nullable|exists:teams,id',
            'type' => ['required', Rule::in(['individual', 'team'])],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $tournamentType = strtolower(str_replace(' ', '_', $tournament->tournament_type)); // e.g. "free_for_all" or "team_vs_team"

        if ($tournamentType === 'free_for_all') {
            // Only individual registration allowed
            if ($data['type'] !== 'individual') {
                return response()->json(['message' => 'Only individual registration allowed for free for all tournaments'], 422);
            }
            if (!empty($data['team_id'])) {
                return response()->json(['message' => 'Team ID should not be provided for individual registration'], 422);
            }
        } elseif ($tournamentType === 'team_vs_team') {
            // Only team registration allowed
            if ($data['type'] !== 'team') {
                return response()->json(['message' => 'Only team registration allowed for team vs team tournaments'], 422);
            }
            if (empty($data['team_id'])) {
                return response()->json(['message' => 'Team ID is required for team registration'], 422);
            }
        }

        $exists = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where(function ($q) use ($user, $data) {
                if (!empty($data['team_id'])) {
                    $q->where('team_id', $data['team_id']);
                } else {
                    $q->where('user_id', $user->id);
                }
            })
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Already registered'], 409);
        }

        $participant = TournamentParticipant::create([
            'tournament_id' => $tournament->id,
            'team_id' => $data['team_id'] ?? null,
            'user_id' => $user->id,
            'registered_at' => now(),
            'type' => $data['type'],
            'status' => 'pending',
        ]);

        return response()->json(['message' => 'Registration submitted', 'participant' => $participant], 201);
    }

    public function approveParticipant(Request $request, $participantId)
    {
        $user = auth()->user();

        $participant = TournamentParticipant::findOrFail($participantId);
        $tournament = $participant->tournament;

        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'rejection_reason' => 'nullable|string',
        ]);

        if ($data['action'] === 'approve') {
            $participant->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
        } else {
            $participant->update([
                'status' => 'rejected',
                'rejection_reason' => $data['rejection_reason'] ?? 'No reason provided',
                'approved_by' => $user->id,
            ]);
        }

        return response()->json(['message' => 'Participant status updated', 'participant' => $participant]);
    }

    public function listParticipants(Request $request, $tournamentId)
    {
        $status = $request->query('status'); // optional filter

        $query = TournamentParticipant::where('tournament_id', $tournamentId);

        if ($status) {
            $query->where('status', $status);
        }

        $participants = $query->with(['user', 'team', 'event'])->get();

        return response()->json(['participants' => $participants]);
    }

    public function listRejectedParticipants(Request $request, $tournamentId)
    {
        $user = auth()->user();

        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournamentId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $rejectedParticipants = TournamentParticipant::where('tournament_id', $tournamentId)
            ->where('status', 'rejected')
            ->with(['user', 'team', 'event'])
            ->get();

        return response()->json(['rejected_participants' => $rejectedParticipants]);
    }

    public function withdrawRegistration(Request $request, $participantId)
    {
        $user = auth()->user();

        $participant = TournamentParticipant::findOrFail($participantId);

        if ($participant->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($participant->status === 'withdrawn') {
            return response()->json(['message' => 'Already withdrawn'], 400);
        }

        $participant->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
        ]);

        return response()->json(['message' => 'Registration withdrawn successfully']);
    }

    public function uploadDocument(Request $request, $participantId)
    {
        $user = auth()->user();

        $participant = TournamentParticipant::findOrFail($participantId);

        $isOrganizer = TournamentOrganizer::where('tournament_id', $participant->tournament_id)
            ->where('user_id', $user->id)
            ->exists();

        if ($participant->user_id !== $user->id && !$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'document_type' => 'required|string|max:255',
            'document_name' => 'required|string|max:255',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $file = $request->file('file');
        $path = $file->store("public/tournaments/{$participant->tournament_id}/participants/{$participant->id}/documents");

        $document = TournamentDocument::create([
            'tournament_id' => $participant->tournament_id,
            'participant_id' => $participant->id,
            'document_type' => $request->document_type,
            'document_name' => $request->document_name,
            'file_path' => str_replace('public/', '', $path),
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'verification_status' => 'pending',
        ]);

        return response()->json(['message' => 'Document uploaded', 'document' => $document]);
    }

    public function createEvent(Request $request, $tournamentId)
    {
        $user = auth()->user();

        // Find the tournament (parent)
        $tournament = Tournament::findOrFail($tournamentId);

        // Check if user is organizer of the tournament
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournamentId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Validate request data for the event creation
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sport' => 'required|string|max:255',
            'venue_id' => 'nullable|exists:venues,id',
            'facility_id' => 'nullable|exists:facilities,id',
            'date' => 'required|date|after_or_equal:' . $tournament->start_date . '|before_or_equal:' . $tournament->end_date,
            'start_time' => 'required|string',  // Could add regex for HH:mm format
            'end_time' => 'required|string|after:start_time',
            'game_number' => 'nullable|integer',
            'game_status' => 'nullable|string',
            'is_tournament_game' => 'nullable|boolean',
            'event_type' => 'nullable|string',
        ]);

        // Prevent adding events with a different sport unless tournament is multisport
        $sport = $data['sport'];
        $newSport = strtolower(trim($sport));
        $existingSports = Event::where('tournament_id', $tournament->id)
            ->whereNotNull('sport')
            ->pluck('sport')
            ->map(function ($s) { return strtolower(trim($s)); })
            ->unique();

        if ($tournament->type !== 'multisport' && $existingSports->isNotEmpty()) {
            // if any existing sport differs from the requested sport, block creation
            $differs = $existingSports->contains(function ($s) use ($newSport) {
                return $s !== $newSport;
            });

            if ($differs) {
                return response()->json([
                    'message' => 'Tournament is not configured as multisport. To add events with different sports, set tournament type to "multisport".'
                ], 422);
            }
        }

        // check venue open/availability
        $venue = \App\Models\Venue::find($data['venue_id'] ?? null);
        if ($venue && data_get($venue, 'is_closed')) {
            return response()->json(['status'=>'error','message'=>'This venue is closed and not accepting new events.'], 403);
        }

        // prevent double booking same venue+facility + date + overlapping times
        $conflict = Event::where('venue_id', $data['venue_id'] ?? null)
            ->where('facility_id', $data['facility_id'] ?? null)
            ->where('date', $data['date'])
            ->where(function($q) use ($data) {
                $q->where('start_time', '<', $data['end_time'])
                  ->where('end_time', '>', $data['start_time']);
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

            \Log::error('createEvent failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $data ?? $request->all(),
                'tournament_id' => $tournamentId ?? null,
            ]);

            return response()->json([
                'message' => 'Failed to create event',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function listEvents($tournamentId)
    {
        // Verify tournament exists (optional)
        $tournament = Tournament::findOrFail($tournamentId);

        $events = Event::where('tournament_id', $tournamentId)
            ->orderBy('date', 'asc')
            ->get();

        return response()->json([
            'tournament' => $tournament->name,
            'events' => $events,
        ]);
    }

    public function updateEvent(Request $request, $eventId)
    {
        $user = auth()->user();

        $event = Event::findOrFail($eventId);
        $tournament = $event->tournament;

        // Check if user is organizer of the tournament
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|nullable',
            'sport' => 'sometimes|string|nullable',
            'venue_id' => 'sometimes|integer|nullable',
            'facility_id' => 'sometimes|integer|nullable',
            'slots' => 'sometimes|integer|nullable',
            'date' => 'sometimes|date|nullable',
            'start_time' => 'sometimes|string|nullable',
            'end_time' => 'sometimes|string|nullable',
            'is_approved' => 'sometimes|boolean',
        ]);

        $event->update($data);

        return response()->json([
            'message' => 'Event updated successfully',
            'event' => $event,
        ]);
    }

    public function cancelEvent($eventId)
    {
        $user = auth()->user();

        $event = Event::findOrFail($eventId);
        $tournament = $event->tournament;

        // Check if user is organizer of the tournament
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($event->cancelled_at) {
            return response()->json(['message' => 'Event already cancelled'], 400);
        }

        $event->update([
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
        ]);

        return response()->json(['message' => 'Event cancelled successfully', 'event' => $event]);
    }

    
    // Event registration with MAX SLOTS enforcement
    public function registerForEvent(Request $request, $eventId)
    {
        $user = auth()->user();

        $event = Event::with('tournament')->findOrFail($eventId);

        // Check if event has max slots and if full
        $approvedCount = EventParticipant::where('event_id', $event->id)
            ->where('status', 'approved')
            ->count();

        if ($event->slots && $approvedCount >= $event->slots) {
            return response()->json([
                'message' => 'Event is already full'
            ], 422);
        }

        $tournament = $event->tournament;

        // Normalize tournament type (team vs team or free for all)
        $tType = strtolower(preg_replace('/[^a-z0-9]+/','_', $tournament->tournament_type ?? ''));

        $isTeamBased = $tType === 'team_vs_team';
        $isFreeForAll = in_array($tType, ['free_for_all', 'freeforal', 'free_forall']);

        // validation rules depend on tournament type
        $rules = [];
        if ($isTeamBased) {
            $rules['team_id'] = 'required|exists:teams,id';
        } elseif ($isFreeForAll) {
            $rules['team_id'] = 'prohibited';
        } else {
            $rules['team_id'] = 'nullable|exists:teams,id';
        }

        $request->validate($rules);

        // Ensure user belongs to selected team if team-based
        if ($isTeamBased) {
            $teamId = $request->team_id;

            $isMember = \App\Models\TeamMember::where([
                'team_id' => $teamId,
                'user_id' => $user->id,
            ])->exists();

            if (!$isMember) {
                return response()->json(['message' => 'You are not a member of the selected team'], 403);
            }
        }

        // Prevent duplicate registration
        $alreadyRegistered = EventParticipant::where('event_id', $event->id)
            ->when($isTeamBased,
                fn ($q) => $q->where('team_id', $request->team_id),
                fn ($q) => $q->where('user_id', $user->id)
            )
            ->exists();

        if ($alreadyRegistered) {
            return response()->json(['message' => 'Already registered for this event'], 409);
        }

        $eventParticipant = EventParticipant::create([
            'event_id' => $event->id,
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'team_id' => $isTeamBased ? $request->team_id : null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Successfully registered for event',
            'event_participant' => $eventParticipant,
        ], 201);
    }

    public function approveEventParticipant(Request $request, $participantId)
    {
        $user = auth()->user();

        $participant = EventParticipant::findOrFail($participantId);
        $event = $participant->event;
        $tournament = $participant->tournament;

        // Check if user is organizer of the tournament
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'rejection_reason' => 'nullable|string',
        ]);

        if ($data['action'] === 'approve') {
            $participant->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
        } else {
            $participant->update([
                'status' => 'rejected',
                'rejection_reason' => $data['rejection_reason'] ?? 'No reason provided',
                'approved_by' => $user->id,
            ]);
        }

        return response()->json(['message' => 'Participant status updated', 'participant' => $participant]);
    }

    public function listEventParticipants(Request $request, $eventId)
    {
        
        $status = $request->query('status'); // optional filter

        $query = EventParticipant::where('event_id', $eventId);

        if ($status) {
            $query->where('status', $status);
        }

        $participants = $query->with(['user', 'team'])->get();

        return response()->json(['participants' => $participants]);
    }

    public function generateSchedule($eventId)
    {
        $user = auth()->user();

        $event = Event::with('tournament')->findOrFail($eventId);
        $tournament = $event->tournament;

        // ✅ Authorization
        $isOrganizer = TournamentOrganizer::where([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
        ])->exists();

        if (! $isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // ✅ Approved participants only
        $participants = EventParticipant::where('event_id', $event->id)
            ->where('status', 'approved')
            ->get();

        if ($participants->count() < 2) {
            return response()->json(['message' => 'Not enough approved participants'], 422);
        }

        // ✅ Determine entries based on tournament type
        if ($tournament->tournament_type === 'free for all') {
            $entries = $participants->pluck('user_id')->values();
            $entryType = 'user';
        } else {
            $entries = $participants->pluck('team_id')->filter()->values();
            $entryType = 'team';
        }

        if ($entries->count() < 2) {
            return response()->json(['message' => 'Not enough valid entries to generate schedule'], 422);
        }

        // Shuffle for fairness
        $entries = $entries->shuffle();

        // 🔒 Transaction-safe creation
        try {
            DB::beginTransaction();

            // Optional: Clear existing schedule
            EventGame::where('event_id', $event->id)->delete();

            $round = 1;
            $match = 1;

            for ($i = 0; $i < $entries->count(); $i += 2) {
                $data = [
                    'event_id' => $event->id,
                    'tournament_id' => $tournament->id,
                    'round_number' => $round,
                    'match_number' => $match++,
                    'status' => 'scheduled',
                ];

                if ($entryType === 'team') {
                    $data['team_a_id'] = $entries[$i];
                    $data['team_b_id'] = $entries[$i + 1] ?? null;
                } else {
                    $data['user_a_id'] = $entries[$i];
                    $data['user_b_id'] = $entries[$i + 1] ?? null;
                }

                EventGame::create([
                    'event_id' => $event->id,
                    'tournament_id' => $tournament->id,
                    'round_number' => $round,
                    'match_number' => $match++,
                    'match_stage' => 'winners',
                    'team_a_id' => $entryType === 'team' ? $entries[$i] : null,
                    'team_b_id' => $entryType === 'team' ? ($entries[$i + 1] ?? null) : null,
                    'user_a_id' => $entryType === 'user' ? $entries[$i] : null,
                    'user_b_id' => $entryType === 'user' ? ($entries[$i + 1] ?? null) : null,
                    'status' => 'scheduled',
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Event schedule generated successfully',
                'total_matches' => $match - 1
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Schedule generation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Submit score and advance bracket
    public function submitScore(Request $request, $gameId)
    {
        $game = EventGame::findOrFail($gameId);

        $data = $request->validate([
            'score_a' => 'required|integer|min:0',
            'score_b' => 'required|integer|min:0',
        ]);

        $winner = $data['score_a'] > $data['score_b']
            ? $game->team_a_id
            : $game->team_b_id;

        $game->update([
            'score_a' => $data['score_a'],
            'score_b' => $data['score_b'],
            'winner_team_id' => $winner,
            'status' => 'completed',
        ]);

        $this->advanceDoubleEliminationBracket($game);

        return response()->json([
            'message' => 'Score recorded',
            'game' => $game,
        ]);
    }

    private function advanceDoubleEliminationBracket(EventGame $game)
    {
        $event = $game->event;
        $tournament = $event->tournament;

        $bracketType = $game->match_stage; // 'winners', 'losers', or 'grand_final'
        $currentRound = $game->round_number;
        $nextRound = $currentRound + 1;

        // Wait until all matches in current round & bracket are completed before advancing
        $completedGames = EventGame::where('event_id', $event->id)
            ->where('match_stage', $bracketType)
            ->where('round_number', $currentRound)
            ->where('status', 'completed')
            ->get();

        $totalGamesInRound = EventGame::where('event_id', $event->id)
            ->where('match_stage', $bracketType)
            ->where('round_number', $currentRound)
            ->count();

        if ($completedGames->count() < $totalGamesInRound) {
            // Some matches still not done, so no advancement yet
            return;
        }

        if ($bracketType === 'winners') {
            $winners = $completedGames->pluck('winner_team_id')->filter()->values();

            // Losers of winners bracket round drop to losers bracket next round
            $losers = $completedGames->map(function ($g) {
                return $g->team_a_id === $g->winner_team_id ? $g->team_b_id : $g->team_a_id;
            })->filter()->values();

            // Advance winners in winners bracket
            $this->createMatches($event, 'winners', $nextRound, $winners);

            // Send losers to losers bracket round - usually currentRound in losers bracket logic
            $losersRound = $currentRound; // adjust if your logic needs offset

            $this->addLosersBracketMatches($event, $losersRound, $losers);

        } elseif ($bracketType === 'losers') {
            $winners = $completedGames->pluck('winner_team_id')->filter()->values();

            // Losers in losers bracket are eliminated (no further matches)
            $losers = $completedGames->map(function ($g) {
                return $g->team_a_id === $g->winner_team_id ? $g->team_b_id : $g->team_a_id;
            })->filter();

            // Optionally, mark eliminated participants (you can implement this method)
            foreach ($losers as $loserId) {
                $this->eliminateParticipant($event->id, $loserId);
            }

            // Advance winners in losers bracket
            $this->createMatches($event, 'losers', $nextRound, $winners);

            // Check if losers bracket winner advances to grand final
            if ($winners->count() === 1 && $this->isLosersBracketFinalRound($event, $nextRound)) {
                $this->assignGrandFinal($event, $winners->first());
            }

        } elseif ($bracketType === 'grand_final') {
            $winnerId = $completedGames->first()->winner_team_id;

            // Fetch grand final game info (usually 1 match)
            $grandFinalGame = $completedGames->first();

            if ($grandFinalGame->round_number === 1) {
                // First grand final match

                // Check if winner is from losers bracket (challenger)
                $losersBracketWinnerId = $this->getLosersBracketWinnerId($event);

                if ($winnerId === $losersBracketWinnerId) {
                    // Reset grand final for a rematch (double elimination rule)
                    $this->resetGrandFinal($event);
                } else {
                    // Winner is from winners bracket, champion declared
                    $this->declareChampion($event, $winnerId);
                }
            } else {
                // Second grand final match (rematch)
                $this->declareChampion($event, $winnerId);
            }
        }
    }

    private function createMatches($event, $bracketType, $roundNumber, $teams)
    {
        for ($i = 0; $i < $teams->count(); $i += 2) {
            EventGame::create([
                'event_id' => $event->id,
                'tournament_id' => $event->tournament_id,
                'round_number' => $roundNumber,
                'match_number' => ($i / 2) + 1,
                'match_stage' => $bracketType,
                'team_a_id' => $teams[$i],
                'team_b_id' => $teams[$i + 1] ?? null, // bye if null
                'status' => 'scheduled',
            ]);
        }
    }

    private function addLosersBracketMatches($event, $roundNumber, $teams)
    {
        // You can customize placements here if needed.
        $this->createMatches($event, 'losers', $roundNumber, $teams);
    }

    private function eliminateParticipant($eventId, $teamId)
    {
        // Mark participant eliminated - for example, update status in EventParticipant or elsewhere
        // Assuming EventParticipant model exists and links teams to event:

        \App\Models\EventParticipant::where('event_id', $eventId)
            ->where('team_id', $teamId)
            ->update(['status' => 'eliminated']);
    }

    private function isLosersBracketFinalRound($event, $roundNumber)
    {
        // Return true if this round is the last round in losers bracket before grand final
        // You can decide based on total rounds, or a field stored on event, or count remaining teams

        $maxLosersRound = EventGame::where('event_id', $event->id)
            ->where('match_stage', 'losers')
            ->max('round_number');

        return $roundNumber >= $maxLosersRound;
    }

    private function assignGrandFinal($event, $losersBracketWinnerId)
    {
        // Create grand final match between winners bracket champion and losers bracket winner

        $winnersBracketChampionId = $this->getWinnersBracketWinnerId($event);

        // Clear existing grand final matches if any
        EventGame::where('event_id', $event->id)
            ->where('match_stage', 'grand_final')
            ->delete();

        EventGame::create([
            'event_id' => $event->id,
            'tournament_id' => $event->tournament_id,
            'round_number' => 1,
            'match_number' => 1,
            'match_stage' => 'grand_final',
            'team_a_id' => $winnersBracketChampionId,
            'team_b_id' => $losersBracketWinnerId,
            'status' => 'scheduled',
        ]);
    }

    private function getWinnersBracketWinnerId($event)
    {
        // Get winners bracket champion (last winners bracket round winner)
        $lastRound = EventGame::where('event_id', $event->id)
            ->where('match_stage', 'winners')
            ->max('round_number');

        $lastWinnersRoundGames = EventGame::where('event_id', $event->id)
            ->where('match_stage', 'winners')
            ->where('round_number', $lastRound)
            ->get();

        // Usually only 1 winner in last round
        $winner = $lastWinnersRoundGames->pluck('winner_team_id')->filter()->first();

        return $winner;
    }

    private function getLosersBracketWinnerId($event)
    {
        // Get losers bracket champion (last losers bracket round winner)
        $lastRound = EventGame::where('event_id', $event->id)
            ->where('match_stage', 'losers')
            ->max('round_number');

        $lastLosersRoundGames = EventGame::where('event_id', $event->id)
            ->where('match_stage', 'losers')
            ->where('round_number', $lastRound)
            ->get();

        // Usually only 1 winner in last round
        $winner = $lastLosersRoundGames->pluck('winner_team_id')->filter()->first();

        return $winner;
    }

    private function resetGrandFinal($event)
    {
        // Set rematch grand final round 2 (reset grand final match)

        // Delete previous round 2 if exists
        EventGame::where('event_id', $event->id)
            ->where('match_stage', 'grand_final')
            ->where('round_number', 2)
            ->delete();

        $grandFinalGame = EventGame::where('event_id', $event->id)
            ->where('match_stage', 'grand_final')
            ->where('round_number', 1)
            ->first();

        if (!$grandFinalGame) return;

        // Create rematch as round 2 grand final
        EventGame::create([
            'event_id' => $event->id,
            'tournament_id' => $event->tournament_id,
            'round_number' => 2,
            'match_number' => 1,
            'match_stage' => 'grand_final',
            'team_a_id' => $grandFinalGame->team_a_id,
            'team_b_id' => $grandFinalGame->team_b_id,
            'status' => 'scheduled',
        ]);
    }

    private function declareChampion($event, $teamId)
    {
        // Mark the event as complete and save champion team id

        $event->update([
            'game_status' => 'completed',
            'champion_team_id' => $teamId,
        ]);
    }

    /**
     * Get tournament details
     * GET /api/tournaments/{tournamentId}
     */
    public function show($tournamentId)
    {
        $user = auth()->user();
        
        $tournament = Tournament::with([
            'events' => function($query) {
                $query->orderBy('date', 'asc');
            },
            'participants.user',
            'participants.team',
            'organizers.user',
            'documents',
            'announcements' => function($query) {
                $query->orderBy('is_pinned', 'desc')
                      ->orderBy('published_at', 'desc');
            }
        ])->findOrFail($tournamentId);

        // Check if user is organizer (for draft visibility)
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        // Hide draft tournaments from non-organizers
        if ($tournament->status === 'draft' && !$isOrganizer && $tournament->created_by !== $user->id) {
            return response()->json(['message' => 'Tournament not found'], 404);
        }

        // Add participant counts
        $tournament->participants_count = $tournament->participants()->where('status', 'approved')->count();
        $tournament->pending_participants_count = $tournament->participants()->where('status', 'pending')->count();
        $tournament->is_organizer = $isOrganizer || $tournament->created_by === $user->id;

        return response()->json([
            'message' => 'Tournament retrieved successfully',
            'tournament' => $tournament,
        ]);
    }

    /**
     * Update tournament
     * PUT /api/tournaments/{tournamentId}
     */
    public function update(Request $request, $tournamentId)
    {
        $user = auth()->user();
        
        $tournament = Tournament::findOrFail($tournamentId);

        // Check if user is organizer
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOrganizer && $tournament->created_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => ['sometimes', Rule::in(['single_sport', 'multisport'])],
            'tournament_type' => ['sometimes', Rule::in(['free for all', 'team vs team'])],
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'registration_deadline' => 'nullable|date|before_or_equal:start_date',
            'status' => ['sometimes', Rule::in(['draft', 'open_registration', 'registration_closed', 'ongoing', 'completed', 'cancelled'])],
            'location' => 'nullable|string|max:255',
            'rules' => 'nullable|string',
            'max_teams' => 'nullable|integer|min:2',
            'min_teams' => 'nullable|integer|min:2',
            'photo' => 'sometimes|file|image|max:5120|mimes:jpg,jpeg,png',
        ]);

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $path = $file->store("public/tournaments/{$tournament->id}/photo");
            $data['photo'] = str_replace('public/', '', $path);
        }

        $tournament->update($data);

        return response()->json([
            'message' => 'Tournament updated successfully',
            'tournament' => $tournament->fresh(),
        ]);
    }

    /**
     * Delete tournament
     * DELETE /api/tournaments/{tournamentId}
     */
    public function destroy($tournamentId)
    {
        $user = auth()->user();
        
        $tournament = Tournament::findOrFail($tournamentId);

        // Only creator can delete
        if ($tournament->created_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Prevent deletion if tournament has started
        if (in_array($tournament->status, ['ongoing', 'completed'])) {
            return response()->json([
                'message' => 'Cannot delete tournament that has started or completed. Cancel it instead.'
            ], 422);
        }

        $tournament->delete();

        return response()->json([
            'message' => 'Tournament deleted successfully'
        ]);
    }

    /**
     * Get user's tournaments (organizer dashboard)
     * GET /api/tournaments/my
     */
    public function myTournaments(Request $request)
    {
        $user = auth()->user();
        
        $query = Tournament::with([
            'events' => function($query) {
                $query->where('is_approved', true);
            },
            'participants',
            'organizers.user',
        ])->where(function($q) use ($user) {
            $q->where('created_by', $user->id)
              ->orWhereHas('organizers', function($orgQ) use ($user) {
                  $orgQ->where('user_id', $user->id);
              });
        });

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('tournament_type')) {
            $query->where('tournament_type', $request->tournament_type);
        }

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
     * List event games/matches
     * GET /api/tournaments/events/{eventId}/games
     */
    public function listEventGames(Request $request, $eventId)
    {
        $event = Event::findOrFail($eventId);
        
        $query = EventGame::where('event_id', $eventId)
            ->with(['team_a', 'team_b', 'event', 'tournament']);

        // Filters
        if ($request->filled('match_stage')) {
            $query->where('match_stage', $request->match_stage);
        }

        if ($request->filled('round_number')) {
            $query->where('round_number', $request->round_number);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $games = $query->orderBy('match_stage')
            ->orderBy('round_number', 'asc')
            ->orderBy('match_number', 'asc')
            ->get();

        // Calculate bracket summary
        $bracketSummary = [
            'winners_bracket' => [
                'rounds' => EventGame::where('event_id', $eventId)
                    ->where('match_stage', 'winners')
                    ->max('round_number') ?? 0,
                'total_matches' => EventGame::where('event_id', $eventId)
                    ->where('match_stage', 'winners')
                    ->count(),
            ],
            'losers_bracket' => [
                'rounds' => EventGame::where('event_id', $eventId)
                    ->where('match_stage', 'losers')
                    ->max('round_number') ?? 0,
                'total_matches' => EventGame::where('event_id', $eventId)
                    ->where('match_stage', 'losers')
                    ->count(),
            ],
            'grand_final' => [
                'rounds' => EventGame::where('event_id', $eventId)
                    ->where('match_stage', 'grand_final')
                    ->max('round_number') ?? 0,
                'total_matches' => EventGame::where('event_id', $eventId)
                    ->where('match_stage', 'grand_final')
                    ->count(),
            ],
        ];

        return response()->json([
            'event_id' => $event->id,
            'event_name' => $event->name,
            'games' => $games,
            'bracket_summary' => $bracketSummary,
        ]);
    }

    /**
     * Get bracket view
     * GET /api/tournaments/events/{eventId}/bracket
     */
    public function getBracket($eventId)
    {
        $event = Event::findOrFail($eventId);
        
        $games = EventGame::where('event_id', $eventId)
            ->with(['team_a', 'team_b', 'user_a', 'user_b'])
            ->orderByRaw("FIELD(match_stage, 'winners', 'losers', 'grand_final')")
            ->orderBy('round_number', 'asc')
            ->orderBy('match_number', 'asc')
            ->get();

        // Group by match_stage and round_number
        $bracket = [
            'winners' => [],
            'losers' => [],
            'grand_final' => [],
        ];

        foreach ($games as $game) {
            $stage = $game->match_stage;
            $round = $game->round_number;
            
            if (!isset($bracket[$stage][$round])) {
                $bracket[$stage][$round] = [];
            }
            
            $bracket[$stage][$round][] = $game;
        }

        // Get champion if event is completed
        $champion = null;
        if ($event->game_status === 'completed' && $event->champion_team_id) {
            $championTeam = Team::find($event->champion_team_id);
            $champion = [
                'team_id' => $event->champion_team_id,
                'team' => $championTeam,
            ];
        }

        return response()->json([
            'event_id' => $event->id,
            'event_name' => $event->name,
            'bracket' => $bracket,
            'champion' => $champion,
        ]);
    }

    /**
     * Get single game details
     * GET /api/tournaments/event-game/{gameId}
     */
    public function getGame($gameId)
    {
        $game = EventGame::with([
            'event',
            'tournament',
            'team_a',
            'team_b',
            'user_a',
            'user_b',
        ])->findOrFail($gameId);

        return response()->json([
            'game' => $game,
        ]);
    }

    /**
     * Get tournament schedule
     * GET /api/tournaments/{tournamentId}/schedule
     */
    public function getTournamentSchedule(Request $request, $tournamentId)
    {
        $tournament = Tournament::findOrFail($tournamentId);
        
        $query = EventGame::where('tournament_id', $tournamentId)
            ->with(['event', 'team_a', 'team_b', 'user_a', 'user_b']);

        // Filters
        if ($request->filled('event_id')) {
            $query->where('event_id', $request->event_id);
        }

        if ($request->filled('match_stage')) {
            $query->where('match_stage', $request->match_stage);
        }

        if ($request->filled('round_number')) {
            $query->where('round_number', $request->round_number);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereHas('event', function($q) use ($request) {
                $q->where('date', '>=', $request->date_from);
            });
        }

        if ($request->filled('date_to')) {
            $query->whereHas('event', function($q) use ($request) {
                $q->where('date', '<=', $request->date_to);
            });
        }

        $games = $query->orderBy('event_id')
            ->orderBy('match_stage')
            ->orderBy('round_number', 'asc')
            ->orderBy('match_number', 'asc')
            ->get();

        // Group by event
        $schedule = [];
        foreach ($games as $game) {
            $eventId = $game->event_id;
            if (!isset($schedule[$eventId])) {
                $schedule[$eventId] = [
                    'event_id' => $game->event->id,
                    'event_name' => $game->event->name,
                    'games' => [],
                ];
            }
            $schedule[$eventId]['games'][] = $game;
        }

        // Summary
        $summary = [
            'total_events' => count($schedule),
            'total_matches' => $games->count(),
            'completed_matches' => $games->where('status', 'completed')->count(),
            'upcoming_matches' => $games->where('status', 'scheduled')->count(),
        ];

        return response()->json([
            'tournament_id' => $tournament->id,
            'tournament_name' => $tournament->name,
            'schedule' => array_values($schedule),
            'summary' => $summary,
        ]);
    }

    /**
     * Get event champion
     * GET /api/tournaments/events/{eventId}/champion
     */
    public function getEventChampion($eventId)
    {
        $event = Event::findOrFail($eventId);
        
        $isCompleted = $event->game_status === 'completed';
        $champion = null;
        $finalMatch = null;

        if ($isCompleted) {
            // Try to get champion from event champion_team_id
            if ($event->champion_team_id) {
                $championTeam = Team::find($event->champion_team_id);
                $champion = [
                    'team_id' => $event->champion_team_id,
                    'team' => $championTeam,
                ];
            } else {
                // Fallback: get from last grand final match
                $grandFinal = EventGame::where('event_id', $eventId)
                    ->where('match_stage', 'grand_final')
                    ->where('status', 'completed')
                    ->orderBy('round_number', 'desc')
                    ->orderBy('match_number', 'desc')
                    ->first();
                
                if ($grandFinal && $grandFinal->winner_team_id) {
                    $championTeam = Team::find($grandFinal->winner_team_id);
                    $champion = [
                        'team_id' => $grandFinal->winner_team_id,
                        'team' => $championTeam,
                    ];
                    $finalMatch = $grandFinal;
                }
            }

            // Get final match if not already set
            if (!$finalMatch) {
                $finalMatch = EventGame::where('event_id', $eventId)
                    ->where('match_stage', 'grand_final')
                    ->where('status', 'completed')
                    ->orderBy('round_number', 'desc')
                    ->orderBy('match_number', 'desc')
                    ->first();
            }
        }

        return response()->json([
            'event_id' => $event->id,
            'event_name' => $event->name,
            'is_completed' => $isCompleted,
            'champion' => $champion,
            'final_match' => $finalMatch,
        ]);
    }

    /**
     * Get tournament results
     * GET /api/tournaments/{tournamentId}/results
     */
    public function getTournamentResults($tournamentId)
    {
        $tournament = Tournament::findOrFail($tournamentId);
        
        if ($tournament->status !== 'completed') {
            return response()->json([
                'message' => 'Tournament is not completed yet'
            ], 422);
        }

        $events = Event::where('tournament_id', $tournamentId)
            ->where('game_status', 'completed')
            ->with(['venue', 'facility'])
            ->get();

        $eventsResults = [];
        foreach ($events as $event) {
            // Get champion
            $champion = null;
            if ($event->champion_team_id) {
                $championTeam = Team::find($event->champion_team_id);
                $champion = [
                    'team_id' => $event->champion_team_id,
                    'team' => $championTeam,
                ];
            }

            // Calculate standings from completed games
            $standings = $this->calculateEventStandings($event);

            $eventsResults[] = [
                'event_id' => $event->id,
                'event_name' => $event->name,
                'champion' => $champion,
                'final_standings' => $standings,
            ];
        }

        // Overall champion (for single-sport tournaments)
        $overallChampion = null;
        if ($tournament->type === 'single_sport' && count($eventsResults) === 1) {
            $overallChampion = $eventsResults[0]['champion'] ?? null;
        }

        return response()->json([
            'tournament_id' => $tournament->id,
            'tournament_name' => $tournament->name,
            'status' => $tournament->status,
            'events' => $eventsResults,
            'overall_champion' => $overallChampion,
        ]);
    }

    /**
     * Calculate event standings
     */
    private function calculateEventStandings($event)
    {
        $games = EventGame::where('event_id', $event->id)
            ->where('status', 'completed')
            ->get();

        $standings = [];
        
        foreach ($games as $game) {
            // Process team_a
            if ($game->team_a_id) {
                if (!isset($standings[$game->team_a_id])) {
                    $standings[$game->team_a_id] = [
                        'team_id' => $game->team_a_id,
                        'wins' => 0,
                        'losses' => 0,
                        'points_for' => 0,
                        'points_against' => 0,
                    ];
                }
                
                $standings[$game->team_a_id]['points_for'] += $game->score_a ?? 0;
                $standings[$game->team_a_id]['points_against'] += $game->score_b ?? 0;
                
                if ($game->winner_team_id === $game->team_a_id) {
                    $standings[$game->team_a_id]['wins']++;
                } else {
                    $standings[$game->team_a_id]['losses']++;
                }
            }

            // Process team_b
            if ($game->team_b_id) {
                if (!isset($standings[$game->team_b_id])) {
                    $standings[$game->team_b_id] = [
                        'team_id' => $game->team_b_id,
                        'wins' => 0,
                        'losses' => 0,
                        'points_for' => 0,
                        'points_against' => 0,
                    ];
                }
                
                $standings[$game->team_b_id]['points_for'] += $game->score_b ?? 0;
                $standings[$game->team_b_id]['points_against'] += $game->score_a ?? 0;
                
                if ($game->winner_team_id === $game->team_b_id) {
                    $standings[$game->team_b_id]['wins']++;
                } else {
                    $standings[$game->team_b_id]['losses']++;
                }
            }
        }

        // Sort by wins, then by point difference
        usort($standings, function($a, $b) {
            if ($a['wins'] === $b['wins']) {
                $diffA = $a['points_for'] - $a['points_against'];
                $diffB = $b['points_for'] - $b['points_against'];
                return $diffB <=> $diffA;
            }
            return $b['wins'] <=> $a['wins'];
        });

        // Add rank and team info
        $rank = 1;
        foreach ($standings as &$standing) {
            $standing['rank'] = $rank++;
            $standing['team'] = Team::find($standing['team_id']);
            $standing['point_difference'] = $standing['points_for'] - $standing['points_against'];
        }

        return $standings;
    }

    /**
     * Get public tournament view
     * GET /api/tournaments/public/{tournamentId}
     */
    public function getPublicTournament($tournamentId)
    {
        $tournament = Tournament::findOrFail($tournamentId);
        
        // Hide draft tournaments
        if ($tournament->status === 'draft') {
            return response()->json(['message' => 'Tournament not found'], 404);
        }

        // Load only public data
        $tournament->load([
            'events' => function($query) {
                $query->where('is_approved', true)
                      ->orderBy('date', 'asc');
            },
            'announcements' => function($query) {
                $query->whereNotNull('published_at')
                      ->where('published_at', '<=', now())
                      ->orderBy('is_pinned', 'desc')
                      ->orderBy('published_at', 'desc');
            }
        ]);

        // Only include participant count, not details
        $tournament->participants_count = $tournament->participants()
            ->where('status', 'approved')
            ->count();

        // Remove sensitive data
        unset($tournament->participants);
        unset($tournament->organizers);
        unset($tournament->documents);

        return response()->json([
            'tournament' => $tournament,
        ]);
    }

    /**
     * Create announcement
     * POST /api/tournaments/{tournamentId}/announcements
     */
    public function createAnnouncement(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::findOrFail($tournamentId);
        
        // Check organizer/creator permission
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'is_pinned' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            $announcement = TournamentAnnouncement::create([
                'tournament_id' => $tournament->id,
                'title' => $data['title'],
                'content' => $data['content'],
                'created_by' => $user->id,
                'priority' => $data['priority'] ?? 'medium',
                'is_pinned' => $data['is_pinned'] ?? false,
                'published_at' => now(),
            ]);

            // Get all tournament participants (teams + individuals)
            $participantUserIds = TournamentParticipant::where('tournament_id', $tournament->id)
                ->whereIn('status', ['approved', 'confirmed', 'pending'])
                ->get()
                ->map(function($p) {
                    if ($p->type === 'individual') {
                        return $p->user_id;
                    } else {
                        // get team owner
                        return Team::find($p->team_id)?->owner_id;
                    }
                })
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            // Send notifications to all participants
            if (!empty($participantUserIds)) {
                $notification = Notification::create([
                    'type' => 'tournament_announcement',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'announcement_id' => $announcement->id,
                        'title' => $announcement->title,
                        'content' => $announcement->content,
                        'priority' => $announcement->priority,
                    ],
                    'created_by' => $user->id,
                ]);

                foreach ($participantUserIds as $userId) {
                    UserNotification::create([
                        'notification_id' => $notification->id,
                        'user_id' => $userId,
                        'is_read' => false,
                    ]);
                }
            }

            DB::commit();
            return response()->json([
                'message' => 'Announcement created and notifications sent',
                'announcement' => $announcement,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create announcement',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get announcements
     * GET /api/tournaments/{tournamentId}/announcements
     */
    public function getAnnouncements($tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::findOrFail($tournamentId);
        
        // Check if user is organizer (to see unpublished announcements)
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();
        $isCreator = $tournament->created_by === $user->id;

        $query = TournamentAnnouncement::where('tournament_id', $tournament->id)
            ->with(['creator']);

        // Non-organizers only see published announcements
        if (!$isOrganizer && !$isCreator) {
            $query->whereNotNull('published_at')
                  ->where('published_at', '<=', now());
        }

        $announcements = $query->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->get()
            ->map(function($a) {
                return [
                    'id' => $a->id,
                    'tournament_id' => $a->tournament_id,
                    'title' => $a->title,
                    'content' => $a->content,
                    'priority' => $a->priority,
                    'is_pinned' => $a->is_pinned,
                    'created_by' => $a->created_by,
                    'creator_name' => $a->creator ? $a->creator->first_name . ' ' . $a->creator->last_name : 'Unknown',
                    'published_at' => $a->published_at,
                    'created_at' => $a->created_at,
                    'updated_at' => $a->updated_at,
                ];
            });

        return response()->json([
            'announcements' => $announcements,
            'count' => $announcements->count(),
        ]);
    }

    /**
     * Update announcement
     * PUT /api/tournaments/{tournamentId}/announcements/{announcementId}
     */
    public function updateAnnouncement(Request $request, $tournamentId, $announcementId)
    {
        $user = auth()->user();
        $tournament = Tournament::findOrFail($tournamentId);
        
        // Check permission
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $announcement = TournamentAnnouncement::where('id', $announcementId)
            ->where('tournament_id', $tournament->id)
            ->firstOrFail();

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'is_pinned' => 'sometimes|boolean',
        ]);

        $announcement->update($data);

        return response()->json([
            'message' => 'Announcement updated',
            'announcement' => $announcement,
        ]);
    }

    /**
     * Delete announcement
     * DELETE /api/tournaments/{tournamentId}/announcements/{announcementId}
     */
    public function deleteAnnouncement($tournamentId, $announcementId)
    {
        $user = auth()->user();
        $tournament = Tournament::findOrFail($tournamentId);
        
        // Check permission
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $announcement = TournamentAnnouncement::where('id', $announcementId)
            ->where('tournament_id', $tournament->id)
            ->firstOrFail();

        $announcement->delete();

        return response()->json([
            'message' => 'Announcement deleted',
        ]);
    }

    /**
     * Open tournament registration
     * POST /api/tournaments/{tournamentId}/open-registration
     */
    public function openRegistration(Request $request, $tournamentId)
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

        if ($tournament->status !== 'draft') {
            return response()->json(['status' => 'error', 'message' => 'Only draft tournaments can open registration'], 422);
        }

        $tournament->update(['status' => 'open_registration']);

        return response()->json([
            'status' => 'success',
            'message' => 'Registration opened successfully',
            'tournament' => $tournament
        ]);
    }

    /**
     * Close tournament registration
     * POST /api/tournaments/{tournamentId}/close-registration
     */
    public function closeRegistration(Request $request, $tournamentId)
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

        if ($tournament->status !== 'open_registration') {
            return response()->json(['status' => 'error', 'message' => 'Registration is not open'], 422);
        }

        $tournament->update(['status' => 'registration_closed']);

        // Send notifications to participants
        $participants = TournamentParticipant::where('tournament_id', $tournament->id)
            ->whereIn('status', ['approved', 'pending', 'confirmed'])
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
     * POST /api/tournaments/{tournamentId}/start
     */
    public function startTournament(Request $request, $tournamentId)
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
     * POST /api/tournaments/{tournamentId}/complete
     */
       public function completeTournament(Request $request, $tournamentId)
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

    public function pushTournamentToChallonge(Request $request, ChallongeOauthService $oauth)
    {
        $user = $request->user();
        $tournamentId = $request->tournament_id;

        $tournament = Tournament::findOrFail($tournamentId);

        // check organizer permission
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->exists();
        if (! $isOrganizer) return response()->json(['message'=>'Unauthorized'], 403);

        $remote = (new ChallongeService)->createTournament($tournament, ['tournament_type' => 'double elimination']);
        if (! $remote) {
            return response()->json(['message'=>'Failed to create challonge tournament'], 500);
        }

        // store challonge url/identifier on local tournament if desired
        $tournament->update(['external_tournament_url' => $remote['url'] ?? ($remote['id'] ?? null)]);

        // add participants example: mapping teams or users -> name + misc (local id)
        $participants = [];
        // gather team entries across events or tournament participants as needed
        foreach ($tournament->participants()->where('status','approved')->get() as $p) {
            $name = $p->team_id ? \App\Models\Team::find($p->team_id)?->name : \App\Models\User::find($p->user_id)?->username;
            $participants[] = ['name' => $name ?: 'entry-'.$p->id, 'misc' => 'local:'.$p->id];
        }

        $addRes = (new ChallongeService)->addParticipants($remote['url'] ?? $remote['id'], $participants);
        if ($addRes === null) {
            return response()->json(['message'=>'Failed to add participants'], 500);
        }

        // Optionally push event games to challonge
        // foreach events call $challonge->pushEventToChallonge($event, $remote['url'] ?? $remote['id']);

        // then start
        $start = $oauth->startTournament($user, $remote['id']);
        if ($start->failed()) {
            \Log::error('Failed to start challonge tournament', ['body'=>$start->body()]);
        }

        return response()->json(['message'=>'Pushed to Challonge','remote'=>$remote], 201);
    }

    public function joinedTournaments(Request $request)
    {
        $user = auth()->user();

        // get team ids the user belongs to (if TeamMember model exists)
        $teamIds = \App\Models\TeamMember::where('user_id', $user->id)->pluck('team_id')->toArray();

        $query = Tournament::with(['events', 'participants', 'organizers.user'])
            ->whereHas('participants', function ($q) use ($user, $teamIds) {
                $q->where(function ($q2) use ($user, $teamIds) {
                    $q2->where('user_id', $user->id);
                    if (!empty($teamIds)) {
                        $q2->orWhereIn('team_id', $teamIds);
                    }
                });
            });

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // default per_page = 5
        $perPage = min($request->input('per_page', 5), 100);
        $tournaments = $query->orderBy('start_date', 'desc')->paginate($perPage);

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

}
