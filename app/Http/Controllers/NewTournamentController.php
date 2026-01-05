<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentOrganizer;
use App\Models\TournamentDocument;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventGame;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

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
                'min_teams' => $data['min_teams'] ?? null,
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
        ]);

        // Prevent adding events with a different sport unless tournament is multisport
        $newSport = strtolower(trim($data['sport']));
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

        try {
            $event = Event::create([
                'tournament_id' => $tournament->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'event_type' => 'tournament', // fixed as per your requirement
                'sport' => $data['sport'],
                'venue_id' => $data['venue_id'] ?? null,
                'facility_id' => $data['facility_id'] ?? null,
                'slots' => $tournament->max_teams ?? null,
                'date' => $data['date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'created_by' => $user->id,
                'game_number' => $data['game_number'] ?? null,
                'game_status' => $data['game_status'] ?? null,
                'is_tournament_game' => $data['is_tournament_game'] ?? true, // assuming true by default for sub tournaments
            ]);

            return response()->json([
                'message' => 'Event (sub-tournament) created successfully',
                'event' => $event,
            ], 201);

        } catch (\Throwable $e) {
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
}
