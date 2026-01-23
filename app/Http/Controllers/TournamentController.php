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
use App\Models\AuditLog;
use App\Models\MatchNote;
use App\Models\EventScore;
use App\Services\AnalyticsService;
use Illuminate\Support\Facades\Storage;
use App\Services\TournamentBracketGenerator; // added import
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\URL;

class TournamentController extends Controller
{
    public function __construct()
    {
        // Ensure authenticated for create/update/delete
        // $this->middleware('auth')->only(['create', 'update', 'destroy', 'createGame', 'updateGame', 'deleteGame']);
        // $this->middleware('auth')->only(['create', 'update', 'destroy', 'createGame', 'updateGame', 'deleteGame', 'bulkImportParticipants','createInviteLink','setParticipantLock','generateBracketsPreview','exportParticipants','exportResults','checkinEvent','resetMatch','resetBracket']);
        
        // Use Sanctum for API authentication and protect controller mutating endpoints
        $this->middleware('auth')->only([
            'create', 'update', 'destroy',
            'createGame', 'updateGame', 'deleteGame',
            'register', 'withdraw',
            'bulkImportParticipants', 'createInviteLink', 'setParticipantLock',
            'generateBracketsPreview', 'generateBrackets',
            'exportParticipants', 'exportResults',
            'checkinEvent', 'checkinQR', 'checkinCode', 'checkinManual', 'viewCheckins',
            'assignTeams', 'autoBalanceTeams', 'replacePlayer',
            'approveParticipant', 'rejectParticipant', 'banParticipant', 'markNoShow',
            'uploadDocument', 'getDocuments', 'getParticipantDocuments', 'verifyDocument', 'deleteDocument',
            'resetMatch', 'resetBracket',
            'startMatch', 'endMatch', 'updateScore', 'issuePenalty', 'markForfeit', 'uploadResult',
            'createPhase', 'listPhases', 'updatePhase', 'deletePhase', 'reorderPhases',
            'addOrganizer', 'removeOrganizer', 'listOrganizers', 'updateOrganizerRole',
            'joinWaitlist', 'removeFromWaitlist', 'getWaitlist', 'promoteFromWaitlist',
            'createTemplate', 'listTemplates', 'createFromTemplate', 'updateTemplate', 'deleteTemplate',
            'advanceBracket'
        ]);
    
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
            'tournament_type' => ['required', Rule::in(['team vs team', 'free for all'])],
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
            'photo' => 'sometimes|file|image|max:10240|mimes:jpg,jpeg,png',
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

            // handle photo upload AFTER create so tournament id is available
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $path = $file->store("tournaments/{$tournament->id}/photo");
                $tournament->photo = $path;
                $tournament->save();
            }

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
     * Get tournament flow state - simplified state machine for UI
     * Returns current step, available actions, and summary data
     * GET /api/tournaments/{id}/flow
     */
    public function getFlowState(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::with(['events', 'participants.team', 'organizers.user'])
            ->find($tournamentId);

        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();
        $isAuthorized = $isCreator || $isOrganizer;

        // Get participants count
        $participantsCount = $tournament->participants()->where('status', 'approved')->count();
        $pendingCount = $tournament->participants()->where('status', 'pending')->count();

        // Get matches count
        $matches = Event::where('tournament_id', $tournament->id)
            ->where('is_tournament_game', true)
            ->get();
        $matchesCount = $matches->count();
        $completedMatches = $matches->filter(function($m) {
            return $m->status === 'completed';
        })->count();

        // Get events/games
        $events = $tournament->events()->where('is_tournament_game', true)->get();
        $hasBrackets = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)->exists();

        // Determine current step/phase
        $currentStep = 'setup';
        $availableActions = [];

        if ($tournament->status === 'draft') {
            $currentStep = 'draft';
            if ($isAuthorized) {
                $availableActions[] = [
                    'action' => 'open_registration',
                    'label' => 'Open Registration',
                    'method' => 'POST',
                    'endpoint' => "/api/tournaments/{$tournament->id}/open-registration",
                    'description' => 'Allow participants to register',
                ];
                $availableActions[] = [
                    'action' => 'edit',
                    'label' => 'Edit Tournament',
                    'method' => 'PUT',
                    'endpoint' => "/api/tournaments/update/{$tournament->id}",
                    'description' => 'Update tournament details',
                ];
            }
        } elseif ($tournament->status === 'open_registration') {
            $currentStep = 'registration';
            if ($isAuthorized) {
                $availableActions[] = [
                    'action' => 'approve_participants',
                    'label' => 'Approve Participants',
                    'method' => 'GET',
                    'endpoint' => "/api/tournaments/{$tournament->id}/participants",
                    'description' => "Review and approve {$pendingCount} pending participant(s)",
                ];
                $availableActions[] = [
                    'action' => 'close_registration',
                    'label' => 'Close Registration',
                    'method' => 'POST',
                    'endpoint' => "/api/tournaments/{$tournament->id}/close-registration",
                    'description' => 'Close registration and prepare brackets',
                ];
            }
        } elseif ($tournament->status === 'registration_closed') {
            $currentStep = 'setup_brackets';
            if ($isAuthorized) {
                if (!$hasBrackets && $events->count() > 0) {
                    $firstEvent = $events->first();
                    $availableActions[] = [
                        'action' => 'generate_brackets',
                        'label' => 'Generate Brackets',
                        'method' => 'POST',
                        'endpoint' => "/api/tournaments/{$tournament->id}/events/{$firstEvent->id}/generate-brackets",
                        'description' => 'Generate tournament brackets',
                        'payload' => [
                            'bracket_type' => 'single_elimination',
                            'seed_by' => 'random',
                        ],
                    ];
                }
                $availableActions[] = [
                    'action' => 'start_tournament',
                    'label' => 'Start Tournament',
                    'method' => 'POST',
                    'endpoint' => "/api/tournaments/{$tournament->id}/start",
                    'description' => 'Begin the tournament',
                ];
            }
        } elseif ($tournament->status === 'ongoing') {
            $currentStep = 'running';
            if ($isAuthorized) {
                $liveMatches = Event::where('tournament_id', $tournament->id)
                    ->where('is_tournament_game', true)
                    ->whereIn('status', ['in_progress', 'ongoing'])
                    ->count();

                $availableActions[] = [
                    'action' => 'manage_matches',
                    'label' => 'Manage Matches',
                    'method' => 'GET',
                    'endpoint' => "/api/tournaments/{$tournament->id}/matches",
                    'description' => "View and manage {$matchesCount} match(es)",
                ];
                $availableActions[] = [
                    'action' => 'view_live',
                    'label' => 'Live Matches',
                    'method' => 'GET',
                    'endpoint' => "/api/tournaments/{$tournament->id}/matches/live",
                    'description' => "{$liveMatches} match(es) in progress",
                ];
                $availableActions[] = [
                    'action' => 'view_standings',
                    'label' => 'View Standings',
                    'method' => 'GET',
                    'endpoint' => "/api/tournaments/{$tournament->id}/standings",
                    'description' => 'Current tournament standings',
                ];
                
                if ($completedMatches === $matchesCount && $matchesCount > 0) {
                    $availableActions[] = [
                        'action' => 'complete_tournament',
                        'label' => 'Complete Tournament',
                        'method' => 'POST',
                        'endpoint' => "/api/tournaments/{$tournament->id}/complete",
                        'description' => 'Mark tournament as completed',
                    ];
                }
            }
        } elseif ($tournament->status === 'completed') {
            $currentStep = 'completed';
            $availableActions[] = [
                'action' => 'view_standings',
                'label' => 'Final Standings',
                'method' => 'GET',
                'endpoint' => "/api/tournaments/{$tournament->id}/standings",
                'description' => 'View final tournament results',
            ];
        }

        // Build summary
        $summary = [
            'status' => $tournament->status,
            'participants_approved' => $participantsCount,
            'participants_pending' => $pendingCount,
            'matches_total' => $matchesCount,
            'matches_completed' => $completedMatches,
            'matches_remaining' => $matchesCount - $completedMatches,
            'has_brackets' => $hasBrackets,
            'events_count' => $events->count(),
        ];

        return response()->json([
            'status' => 'success',
            'tournament_id' => $tournament->id,
            'tournament_name' => $tournament->name,
            'current_step' => $currentStep,
            'tournament_status' => $tournament->status,
            'is_authorized' => $isAuthorized,
            'summary' => $summary,
            'available_actions' => $availableActions,
            'progress' => [
                'step' => $currentStep,
                'status' => $tournament->status,
                'completion_percentage' => $matchesCount > 0 
                    ? round(($completedMatches / $matchesCount) * 100) 
                    : 0,
            ],
        ]);
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
            'photo' => 'sometimes|file|image|max:10240|mimes:jpg,jpeg,png',
        ]);

        $tournament->fill($data);

        // handle photo replacement inline
        if ($request->hasFile('photo')) {
            // delete old file if present
            if (!empty($tournament->photo)) {
                try { Storage::delete($tournament->photo); } catch (\Throwable $ex) {}
            }
            $path = $request->file('photo')->store("tournaments/{$tournament->id}/photo");
            $tournament->photo = $path;
        }

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

        // If CSV requested, stream CSV response
        if (strtolower($request->input('format','')) === 'csv') {
            $filename = 'tournament_'.$tournament->id.'_participants_'.date('Ymd_His').'.csv';
            $callback = function() use ($tournamentParticipants) {
                $out = fopen('php://output','w');
                fputcsv($out, ['id','participant_type','user_id','username','team_id','team_name','status','registered_at']);
                foreach ($tournamentParticipants as $p) {
                    fputcsv($out, [
                        $p->id,
                        $p->participant_type,
                        $p->user_id,
                        $p->user?->username,
                        $p->team_id,
                        $p->team?->name,
                        $p->status,
                        $p->registered_at,
                    ]);
                }
                fclose($out);
            };
            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\""
            ]);
        }

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
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();
        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden', 'tournament' => $tournament->id, 'user' => $user->id], 403);
        }

        $participant = TournamentParticipant::where('id',$participantId)->where('tournament_id',$tournament->id)->first();
        if (! $participant) {
            return response()->json(['status' => 'error', 'message' => 'Participant not found'], 404);
        }
        if ($participant->status === 'approved') {
            return response()->json(['status' => 'error', 'message' => 'Already approved'], 422);
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
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
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
     * Get tournament schedule - all matches/games ordered by date and time
     * GET /api/tournaments/{tournamentId}/schedule
     */
    public function getSchedule(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        $query = Event::with(['teams', 'participants', 'venue', 'facility'])
            ->where('tournament_id', $tournament->id)
            ->where('is_tournament_game', true);

        // Filter by date range if provided
        if ($request->filled('start_date')) {
            $query->where('date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->where('date', '<=', $request->input('end_date'));
        }

        // Filter by phase if provided
        if ($request->filled('phase_id')) {
            $query->whereHas('tournament', function($q) use ($request) {
                $q->whereHas('phases', function($phaseQ) use ($request) {
                    $phaseQ->where('id', $request->input('phase_id'));
                });
            });
        }

        // Filter by game_number (round) if provided
        if ($request->filled('round')) {
            $query->where('game_number', $request->input('round'));
        }

        // Additional filters
        if ($request->filled('team_id')) {
            $query->whereHas('teams', function($q) use ($request) {
                $q->where('team_id', $request->input('team_id'));
            });
        }

        if ($request->filled('type')) {
            $type = $request->input('type');
            $now = now();
            if ($type === 'upcoming') {
                $query->where(function($q) use ($now) {
                    $q->where('date', '>', $now->toDateString())
                      ->orWhere(function($q2) use ($now) {
                          $q2->whereDate('date', $now->toDateString())
                             ->where('start_time', '>', $now->toTimeString());
                      });
                })->where('status', '!=', 'completed');
            } elseif ($type === 'past') {
                $query->where(function($q) use ($now) {
                    $q->where('date', '<', $now->toDateString())
                      ->orWhere(function($q2) use ($now) {
                          $q2->whereDate('date', $now->toDateString())
                             ->where('start_time', '<', $now->toTimeString());
                      })
                      ->orWhere('status', 'completed');
                });
            }
        }

        $daysAhead = $request->input('days_ahead', 7);
        $cutoffDate = now()->addDays($daysAhead);

        // Order by date and start_time
        $matches = $query->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $now = now();
        
        // Separate into upcoming, past, and live
        $upcoming = $matches->filter(function($match) use ($now, $cutoffDate) {
            $matchDateTime = \Carbon\Carbon::parse($match->date . ' ' . $match->start_time);
            return $matchDateTime->isFuture() && 
                   $matchDateTime->lte($cutoffDate) && 
                   $match->status !== 'completed';
        })->map(function($match) {
            return [
                'id' => $match->id,
                'name' => $match->name,
                'date' => $match->date,
                'start_time' => $match->start_time,
                'end_time' => $match->end_time,
                'game_number' => $match->game_number,
                'status' => $match->status,
                'sport' => $match->sport,
                'venue' => $match->venue ? [
                    'id' => $match->venue->id,
                    'name' => $match->venue->name,
                    'address' => $match->venue->address,
                    'city' => $match->venue->city,
                ] : null,
                'facility' => $match->facility ? [
                    'id' => $match->facility->id,
                    'type' => $match->facility->type,
                ] : null,
                'teams' => $match->teams->map(function($et) {
                    return [
                        'id' => $et->team->id ?? null,
                        'name' => $et->team->name ?? null,
                        'logo' => $et->team->logo ?? null,
                    ];
                }),
                'estimated_duration' => $match->end_time && $match->start_time 
                    ? \Carbon\Carbon::parse($match->end_time)->diffInMinutes(\Carbon\Carbon::parse($match->start_time))
                    : 120,
            ];
        })->values();

        $past = $matches->filter(function($match) use ($now) {
            $matchDateTime = \Carbon\Carbon::parse($match->date . ' ' . $match->start_time);
            return $matchDateTime->isPast() || $match->status === 'completed';
        })->map(function($match) {
            $teamMatchup = \App\Models\TeamMatchup::where('event_id', $match->id)->first();
            return [
                'id' => $match->id,
                'name' => $match->name,
                'date' => $match->date,
                'start_time' => $match->start_time,
                'end_time' => $match->end_time,
                'game_number' => $match->game_number,
                'status' => $match->status,
                'sport' => $match->sport,
                'venue' => $match->venue ? [
                    'id' => $match->venue->id,
                    'name' => $match->venue->name,
                ] : null,
                'teams' => $match->teams->map(function($et) {
                    return [
                        'id' => $et->team->id ?? null,
                        'name' => $et->team->name ?? null,
                        'score' => null,
                    ];
                }),
                'score_home' => $match->score_home ?? ($teamMatchup ? $teamMatchup->team_a_score : null),
                'score_away' => $match->score_away ?? ($teamMatchup ? $teamMatchup->team_b_score : null),
                'winner_team_id' => $match->winner_team_id ?? ($teamMatchup ? $teamMatchup->winner_team_id : null),
                'completed_at' => $match->completed_at ?? ($teamMatchup ? $teamMatchup->completed_at : null),
            ];
        })->values();

        $live = $matches->filter(function($match) use ($now) {
            $matchDateTime = \Carbon\Carbon::parse($match->date . ' ' . $match->start_time);
            $endDateTime = $match->end_time 
                ? \Carbon\Carbon::parse($match->date . ' ' . $match->end_time)
                : $matchDateTime->copy()->addHours(2);
            return $matchDateTime->isPast() && 
                   $endDateTime->isFuture() && 
                   in_array($match->status, ['in_progress', 'ongoing']) &&
                   $match->status !== 'completed';
        })->map(function($match) {
            $teamMatchup = \App\Models\TeamMatchup::where('event_id', $match->id)->first();
            return [
                'id' => $match->id,
                'name' => $match->name,
                'status' => $match->status,
                'score_home' => $match->score_home ?? ($teamMatchup ? $teamMatchup->team_a_score : null),
                'score_away' => $match->score_away ?? ($teamMatchup ? $teamMatchup->team_b_score : null),
                'current_round' => isset($teamMatchup->meta['round_scores']) 
                    ? count($teamMatchup->meta['round_scores']) + 1 
                    : null,
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'upcoming_matches' => $upcoming,
            'past_matches' => $past,
            'live_matches' => $live,
            'upcoming_count' => $upcoming->count(),
            'past_count' => $past->count(),
            'live_count' => $live->count(),
        ]);
    }

    /**
     * Get live/ongoing matches for a tournament
     * GET /api/tournaments/{tournamentId}/matches/live
     */
    public function getLiveMatches(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) {
            return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);
        }

        // Get matches that are in progress
        // Check game_status field and also filter by computed status
        $liveMatches = Event::with(['teams', 'participants', 'venue', 'facility'])
            ->where('tournament_id', $tournament->id)
            ->where('is_tournament_game', true)
            ->where(function($query) {
                // Matches with game_status 'in_progress'
                $query->where('game_status', 'in_progress')
                    // Or matches that are currently ongoing based on date/time
                    ->orWhere(function($q) {
                        $q->whereNotNull('date')
                          ->whereNotNull('start_time')
                          ->whereNotNull('end_time')
                          ->where('date', '<=', now()->toDateString())
                          ->whereRaw("CONCAT(date, ' ', start_time) <= ?", [now()->toDateTimeString()])
                          ->whereRaw("CONCAT(date, ' ', end_time) >= ?", [now()->toDateTimeString()])
                          ->where(function($statusQ) {
                              // Not completed or cancelled
                              $statusQ->where(function($sq) {
                                  $sq->whereNull('game_status')
                                     ->orWhereNotIn('game_status', ['completed', 'cancelled']);
                              });
                          });
                    });
            })
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->filter(function($match) {
                // Filter using computed status attribute to ensure match is actually live
                $computedStatus = $match->status;
                return in_array($computedStatus, ['ongoing', 'in_progress']) || 
                       ($computedStatus !== 'completed' && $computedStatus !== 'cancelled' && 
                        $match->game_status === 'in_progress');
            })
            ->map(function($match) {
                return [
                    'id' => $match->id,
                    'name' => $match->name,
                    'date' => $match->date,
                    'start_time' => $match->start_time,
                    'end_time' => $match->end_time,
                    'game_number' => $match->game_number,
                    'status' => $match->status,
                    'game_status' => $match->game_status ?? null,
                    'sport' => $match->sport,
                    'venue' => $match->venue ? [
                        'id' => $match->venue->id,
                        'name' => $match->venue->name,
                        'address' => $match->venue->address,
                    ] : null,
                    'teams' => $match->teams,
                    'participants' => $match->participants,
                ];
            })
            ->values(); // Re-index array after filter

        return response()->json([
            'status' => 'success',
            'live_matches' => $liveMatches,
            'count' => $liveMatches->count()
        ]);
    }

    /**
     * Get public tournament details (no authentication required)
     * GET /api/tournaments/public/{id}
     */
    public function getPublicTournament($id)
    {
        $tournament = Tournament::with([
            'events' => function($query) {
                $query->where('is_approved', true)
                      ->select('id', 'name', 'date', 'start_time', 'end_time', 'sport', 'game_number', 'is_tournament_game', 'tournament_id');
            },
            'organizers.user' => function($query) {
                $query->select('id', 'username', 'first_name', 'last_name');
            },
            'announcements' => function($query) {
                $query->whereNotNull('published_at')
                      ->where('published_at', '<=', now())
                      ->orderBy('created_at', 'desc');
            }
        ])->find($id);

        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Only show public tournaments (not drafts)
        if ($tournament->status === 'draft') {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Get public tournament data (sanitized - no sensitive info)
        $publicData = [
            'id' => $tournament->id,
            'name' => $tournament->name,
            'description' => $tournament->description,
            'location' => $tournament->location,
            'type' => $tournament->type,
            'tournament_type' => $tournament->tournament_type,
            'start_date' => $tournament->start_date,
            'end_date' => $tournament->end_date,
            'registration_deadline' => $tournament->registration_deadline,
            'status' => $tournament->status,
            'registration_fee' => $tournament->registration_fee,
            'rules' => $tournament->rules,
            'prizes' => $tournament->prizes,
            'max_teams' => $tournament->max_teams,
            'min_teams' => $tournament->min_teams,
            'rulebook_file' => $tournament->rulebook_file,
            'rulebook_url' => $tournament->rulebook_file ? Storage::url($tournament->rulebook_file) : null,
            'photo_url' => $tournament->photo ? Storage::url($tournament->photo) : null,
            'created_at' => $tournament->created_at,
            'events' => $tournament->events->map(function($event) {
                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'date' => $event->date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'sport' => $event->sport,
                    'game_number' => $event->game_number,
                ];
            }),
            'organizers' => $tournament->organizers->map(function($organizer) {
                return [
                    'id' => $organizer->user->id,
                    'username' => $organizer->user->username,
                    'first_name' => $organizer->user->first_name,
                    'last_name' => $organizer->user->last_name,
                    'role' => $organizer->role,
                ];
            }),
            'announcements' => $tournament->announcements->map(function($announcement) {
                return [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'content' => $announcement->content,
                    'created_at' => $announcement->created_at,
                ];
            }),
            // Public participant count (not individual details)
            'participants_count' => $tournament->participants()->where('status', 'approved')->count(),
        ];

        return response()->json([
            'status' => 'success',
            'tournament' => $publicData
        ]);
    }

    /**
     * Return full match details
     */
    public function getMatchDetails(Request $request, $tournamentId, $matchId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $match = Event::with(['teams.team','participants','venue','facility'])->where('tournament_id', $tournament->id)->where('id', $matchId)->first();
        if (! $match) return response()->json(['status'=>'error','message'=>'Match not found'], 404);

        $teamMatchup = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
            ->where('event_id', $match->id)
            ->first();

        $penalties = EventPenalty::where('event_id', $match->id)->orderBy('created_at')->get();
        $results = EventResult::where('event_id', $match->id)->orderBy('created_at')->get();
        
        // Get match notes
        $notes = MatchNote::with('creator')
            ->where('event_id', $match->id)
            ->where('tournament_id', $tournament->id)
            ->orderBy('timestamp', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        // Get score history from EventScore
        $scoreHistory = EventScore::with('recorder')
            ->where('event_id', $match->id)
            ->orderBy('timestamp', 'desc')
            ->get()
            ->map(function($score) {
                return [
                    'id' => $score->id,
                    'team_id' => $score->team_id,
                    'points' => $score->points,
                    'updated_at' => $score->timestamp,
                    'updated_by' => $score->recorder ? [
                        'id' => $score->recorder->id,
                        'username' => $score->recorder->username,
                    ] : null,
                ];
            });

        // Get round scores from TeamMatchup meta
        $roundScores = [];
        if ($teamMatchup && isset($teamMatchup->meta['round_scores'])) {
            $roundScores = $teamMatchup->meta['round_scores'];
        }

        // Get disputes
        $disputes = [];
        if ($teamMatchup && $teamMatchup->is_disputed) {
            $disputes[] = [
                'id' => $teamMatchup->id,
                'status' => 'pending',
                'reason' => $teamMatchup->dispute_reason ?? null,
                'disputed_at' => $teamMatchup->disputed_at ?? null,
            ];
        }

        // Build enhanced match response
        $matchData = $match->toArray();
        $matchData['score_home'] = $match->score_home ?? ($teamMatchup ? $teamMatchup->team_a_score : null);
        $matchData['score_away'] = $match->score_away ?? ($teamMatchup ? $teamMatchup->team_b_score : null);
        $matchData['round_scores'] = $roundScores;
        $matchData['spectator_count'] = 0; // Placeholder - would need implementation
        $matchData['referee'] = null; // Placeholder - would need implementation

        return response()->json([
            'status' => 'success',
            'match' => $matchData,
            'penalties' => $penalties,
            'results' => $results,
            'notes' => $notes->map(function($note) {
                return [
                    'id' => $note->id,
                    'content' => $note->content,
                    'type' => $note->type,
                    'timestamp' => $note->timestamp,
                    'created_by' => [
                        'id' => $note->creator->id ?? null,
                        'username' => $note->creator->username ?? null,
                    ],
                    'created_at' => $note->created_at,
                ];
            }),
            'score_history' => $scoreHistory,
            'disputes' => $disputes,
        ]);
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
                'round_scores' => 'sometimes|array',
                'round_scores.*.round' => 'required|integer|min:1',
                'round_scores.*.home' => 'required|integer|min:0',
                'round_scores.*.away' => 'required|integer|min:0',
                'round_scores.*.duration' => 'sometimes|nullable|integer|min:0',
                'notes' => 'sometimes|nullable|string|max:2000',
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
                
                // Store round_scores in meta
                if (isset($data['round_scores'])) {
                    $meta = $teamMatchup->meta ?? [];
                    $meta['round_scores'] = $data['round_scores'];
                    $teamMatchup->meta = $meta;
                }
                
                $teamMatchup->save();
            }

            // Save score history to EventScore
            $eventTeams = EventTeam::where('event_id', $match->id)->orderBy('id')->get();
            if ($eventTeams->count() >= 2) {
                EventScore::create([
                    'event_id' => $match->id,
                    'team_id' => $eventTeams[0]->team_id,
                    'points' => $data['score_home'],
                    'recorded_by' => $user->id,
                    'timestamp' => now(),
                ]);
                
                EventScore::create([
                    'event_id' => $match->id,
                    'team_id' => $eventTeams[1]->team_id,
                    'points' => $data['score_away'],
                    'recorded_by' => $user->id,
                    'timestamp' => now(),
                ]);
            }

            // Add note if provided
            if (!empty($data['notes'])) {
                MatchNote::create([
                    'event_id' => $match->id,
                    'tournament_id' => $tournament->id,
                    'created_by' => $user->id,
                    'content' => $data['notes'],
                    'type' => 'update',
                    'timestamp' => now(),
                ]);
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
            return response()->json(['status' => 'error', 'message' => 'Forbidden', 'tournament' => $tournament->id, 'user' => $user->id], 403);
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
            'details' => 'sometimes|nullable|string|max:2000',
            'team_id' => 'sometimes|nullable|integer|exists:teams,id',
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
        $disputingTeamId = $data['team_id'] ?? null;
        
        if ($tournament->tournament_type === 'team vs team') {
            $userTeams = TeamMember::where('user_id', $user->id)
                ->whereIn('team_id', [$teamMatchup->team_a_id, $teamMatchup->team_b_id])
                ->pluck('team_id')
                ->toArray();
            $isParticipant = !empty($userTeams);
            
            // If team_id provided, verify user is member of that team
            if ($disputingTeamId && !in_array($disputingTeamId, $userTeams)) {
                return response()->json(['status' => 'error', 'message' => 'You are not a member of the specified team'], 403);
            }
            
            // If no team_id provided but user is in multiple teams, use first team
            if (!$disputingTeamId && !empty($userTeams)) {
                $disputingTeamId = $userTeams[0];
            }
        } else {
            $meta = $teamMatchup->meta ?? [];
            $isParticipant = ($meta['user_a_id'] ?? null) == $user->id || ($meta['user_b_id'] ?? null) == $user->id;
        }

        if (!$isParticipant) {
            return response()->json(['status' => 'error', 'message' => 'You are not a participant in this match'], 403);
        }

        // Build dispute reason with details
        $disputeReason = $data['reason'];
        if (!empty($data['details'])) {
            $disputeReason .= "\n\nDetails: " . $data['details'];
        }

        // Store dispute info in meta
        $meta = $teamMatchup->meta ?? [];
        $meta['dispute'] = [
            'team_id' => $disputingTeamId,
            'user_id' => $user->id,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'disputed_at' => now()->toIso8601String(),
        ];
        
        $teamMatchup->update([
            'is_disputed' => true,
            'dispute_reason' => $disputeReason,
            'disputed_at' => now(),
            'meta' => $meta,
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
            'dispute_id' => 'sometimes|nullable|integer',
            'resolution' => 'required|string|max:1000',
            'final_score_home' => 'sometimes|nullable|integer|min:0',
            'final_score_away' => 'sometimes|nullable|integer|min:0',
            'notes' => 'sometimes|nullable|string|max:2000',
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

        $match = Event::find($matchId);
        
        // Update scores if provided
        if (isset($data['final_score_home']) && isset($data['final_score_away'])) {
            $teamMatchup->team_a_score = $data['final_score_home'];
            $teamMatchup->team_b_score = $data['final_score_away'];
            
            if ($match) {
                $match->score_home = $data['final_score_home'];
                $match->score_away = $data['final_score_away'];
                
                // Determine winner
                if ($data['final_score_home'] > $data['final_score_away']) {
                    $teams = EventTeam::where('event_id', $match->id)->orderBy('id')->pluck('team_id')->toArray();
                    $match->winner_team_id = $teams[0] ?? null;
                    $teamMatchup->winner_team_id = $match->winner_team_id;
                } elseif ($data['final_score_away'] > $data['final_score_home']) {
                    $teams = EventTeam::where('event_id', $match->id)->orderBy('id')->pluck('team_id')->toArray();
                    $match->winner_team_id = $teams[1] ?? null;
                    $teamMatchup->winner_team_id = $match->winner_team_id;
                } else {
                    $match->winner_team_id = null;
                    $teamMatchup->winner_team_id = null;
                }
                
                $match->save();
            }
        }

        // Update dispute status
        $resolutionText = $data['resolution'];
        if (!empty($data['notes'])) {
            $resolutionText .= "\n\nNotes: " . $data['notes'];
        }
        
        $teamMatchup->update([
            'is_disputed' => false,
            'dispute_reason' => $teamMatchup->dispute_reason . "\n\n[RESOLVED] " . $resolutionText,
            'disputed_at' => null,
        ]);

        // Add resolution note to match notes
        if (!empty($data['notes'])) {
            $notes = $teamMatchup->notes ?? '';
            $teamMatchup->notes = $notes . "\n[DISPUTE RESOLUTION] " . $data['notes'];
        }
        
        // Update meta with resolution
        $meta = $teamMatchup->meta ?? [];
        if (isset($meta['dispute'])) {
            $meta['dispute']['resolved'] = true;
            $meta['dispute']['resolved_by'] = $user->id;
            $meta['dispute']['resolution'] = $data['resolution'];
            $meta['dispute']['resolved_at'] = now()->toIso8601String();
            $teamMatchup->meta = $meta;
        }
        
        $teamMatchup->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Dispute resolved',
            'dispute' => [
                'id' => $teamMatchup->id,
                'status' => 'resolved',
                'resolution' => $data['resolution'],
                'resolved_at' => now()->toIso8601String(),
                'resolved_by' => [
                    'id' => $user->id,
                    'username' => $user->username,
                ],
            ],
            'match' => [
                'id' => $match ? $match->id : null,
                'score_home' => $teamMatchup->team_a_score,
                'score_away' => $teamMatchup->team_b_score,
                'winner_team_id' => $teamMatchup->winner_team_id,
            ],
            'standings_updated' => true,
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

    /**
     * Bulk import participants (JSON array or CSV file).
     * POST /api/tournaments/{id}/participants/bulk
     */
    public function bulkImportParticipants(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        // accept JSON array OR CSV upload
        $importRows = [];
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $handle = fopen($file->getRealPath(), 'r');
            $header = null;
            while (($row = fgetcsv($handle)) !== false) {
                if (!$header) {
                    $header = $row;
                    continue;
                }
                $importRows[] = array_combine($header, $row);
            }
            fclose($handle);
        } else {
            $data = $request->input('participants', []);
            if (!is_array($data)) return response()->json(['status'=>'error','message'=>'Invalid payload'], 422);
            $importRows = $data;
        }

        // DEBUG: return parsed rows and stop when ?debug=1
        if ($request->boolean('debug')) {
            return response()->json(['status'=>'debug','parsed_rows' => $importRows]);
        }

         // PER-ROW DIAGNOSTICS: use ?debug2=1 to return reasons why rows would be skipped
        if ($request->boolean('debug2')) {
            $diag = [];
            foreach ($importRows as $i => $r) {
                $type = strtolower($r['participant_type'] ?? $r['type'] ?? 'individual');
                $userId = $r['user_id'] ?? null;
                $teamId = $r['team_id'] ?? null;

                $userExists = $userId ? User::where('id', $userId)->exists() : false;
                $teamExists = $teamId ? Team::where('id', $teamId)->exists() : false;

                $already = false;
                if ($type === 'team' && $teamId) {
                    $already = TournamentParticipant::where('tournament_id', $tournament->id)->where('team_id', $teamId)->exists();
                } elseif ($type === 'individual' && $userId) {
                    $already = TournamentParticipant::where('tournament_id', $tournament->id)->where('user_id', $userId)->exists();
                }

                $skipReason = null;
                if ($type === 'team') {
                    if (empty($teamId)) $skipReason = 'missing team_id';
                    elseif (! $teamExists) $skipReason = 'team not found';
                    elseif ($already) $skipReason = 'already registered';
                    else $skipReason = 'ok';
                } else {
                    if (empty($userId)) $skipReason = 'missing user_id';
                    elseif (! $userExists) $skipReason = 'user not found';
                    elseif ($already) $skipReason = 'already registered';
                    else $skipReason = 'ok';
                }

                $diag[] = [
                    'row_index' => $i,
                    'row' => $r,
                    'type' => $type,
                    'user_exists' => $userExists,
                    'team_exists' => $teamExists,
                    'already_registered' => $already,
                    'skip_reason' => $skipReason,
                ];
            }

            return response()->json(['status' => 'debug', 'rows' => $diag]);
        }

        $created = [];
        DB::beginTransaction();
        try {
            foreach ($importRows as $r) {
                // expected keys: participant_type (individual|team), user_id, team_id
                $type = $r['participant_type'] ?? ($r['type'] ?? 'individual');
                if ($type === 'team' && !empty($r['team_id'])) {
                    $exists = TournamentParticipant::where('tournament_id',$tournament->id)->where('team_id',$r['team_id'])->exists();
                    if ($exists) continue;
                    $participant = TournamentParticipant::create([
                        'tournament_id' => $tournament->id,
                        'team_id' => $r['team_id'],
                        'user_id' => $r['user_id'] ?? null,
                        'type' => 'team',
                        'participant_type' => 'team',
                        'status' => $tournament->requires_documents ? 'pending' : 'approved',
                        'registered_at' => now(),
                    ]);
                    $created[] = $participant;
                } else {
                    // individual
                    $uid = $r['user_id'] ?? null;
                    if (!$uid) continue;
                    $exists = TournamentParticipant::where('tournament_id',$tournament->id)->where('user_id',$uid)->exists();
                    if ($exists) continue;
                    $participant = TournamentParticipant::create([
                        'tournament_id' => $tournament->id,
                        'user_id' => $uid,
                        'type' => 'individual',
                        'participant_type' => 'individual',
                        'status' => $tournament->requires_documents ? 'pending' : 'approved',
                        'registered_at' => now(),
                    ]);
                    $created[] = $participant;
                }
            }
            DB::commit();
            return response()->json(['status'=>'success','created_count'=>count($created),'created'=>$created], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status'=>'error','message'=>'Import failed','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Create an invite token (cached) and return invite URL.
     * POST /api/tournaments/{id}/invite-link
     */
    public function createInviteLink(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $ttl = $request->input('ttl_days', 7);
        $token = Str::random(40);
        Cache::put("tournament_invite:{$token}", $tournament->id, now()->addDays($ttl));

        // return a simple path; frontend will route appropriately
        $url = url("/invite/tournament/{$token}");

        return response()->json(['status'=>'success','invite' => ['token' => $token, 'url' => $url, 'expires_in_days' => $ttl]]);
    }

    /**
     * Toggle participant lock stored in tournament.settings.participants_locked
     * PATCH /api/tournaments/{id}/lock-participants
     */
    public function setParticipantLock(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $data = $request->validate(['locked' => 'required|boolean']);
        $settings = (array) $tournament->settings;
        $settings['participants_locked'] = (bool) $data['locked'];
        $tournament->settings = $settings;
        $tournament->save();

        return response()->json(['status'=>'success','tournament_id'=>$tournament->id,'participants_locked'=>$settings['participants_locked']]);
    }

    /**
     * Generate bracket preview WITHOUT persisting (single_elimination only for now).
     * GET /api/tournaments/{id}/bracket-preview?type=single_elimination&persist=0
     */
    public function generateBracketsPreview(Request $request, $tournamentId, $eventId)
    {
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $type = $request->input('type', 'single_elimination');
        $persist = (bool) $request->boolean('persist', false);

        // collect participant ids (approved)
        $participantType = $tournament->tournament_type === 'team vs team' ? 'team' : 'individual';
        $partQuery = TournamentParticipant::where('tournament_id', $tournament->id)->where('participant_type', $participantType)->where('status', 'approved');

        $participantIds = $partQuery->pluck($tournament->tournament_type === 'team vs team' ? 'team_id' : 'user_id')
            ->filter()->values()->all();

        if (count($participantIds) < 2) {
            return response()->json(['status'=>'error','message'=>'Need at least 2 approved participants for preview'], 422);
        }

        // Build a lightweight bracket array (no DB writes)
        $buildSingle = function(array $participants) use ($tournamentId, $eventId, $tournament) {
            $n = 1;
            while ($n < count($participants)) $n *= 2;
            $padded = $participants;
            while (count($padded) < $n) $padded[] = null;
            $round = 1;
            $matches = [];
            $matchNumber = 1;
            for ($i = 0; $i < count($padded); $i += 2) {
                $a = $padded[$i] ?? null;
                $b = $padded[$i+1] ?? null;
                $matches[] = [
                    'round_number' => $round,
                    'match_number' => $matchNumber++,
                    'team_a_id' => $a,
                    'team_b_id' => $b,
                    'team_a_name' => $a ? ($tournament->tournament_type === 'team vs team' ? Team::find($a)?->name : User::find($a)?->first_name . ' ' . User::find($a)?->last_name) : null,
                    'team_b_name' => $b ? ($tournament->tournament_type === 'team vs team' ? Team::find($b)?->name : User::find($b)?->first_name . ' ' . User::find($b)?->last_name) : null,
                    'status' => ($a === null || $b === null) ? 'bye' : 'pending',
                ];
            }
            return $matches;
        };

        if ($type !== 'single_elimination') {
            return response()->json(['status'=>'error','message'=>'Preview only supports single_elimination currently'], 422);
        }

        $bracket = $buildSingle($participantIds);

        // optionally persist by delegating to existing generator (if persist requested)
        if ($persist) {
            try {
                $generator = new TournamentBracketGenerator();
                $generator->generate(Event::find($eventId), $type, ['persist' => true]);
            } catch (\Throwable $e) {
                // ignore persistence errors for preview endpoint but report
                \Log::warning('Bracket persist failed during preview call', ['error'=>$e->getMessage()]);
            }
        }

        return response()->json(['status'=>'success','type'=>$type,'preview'=>$bracket]);
    }

    /**
     * Export participants CSV (streamed)
     * POST /api/tournaments/{id}/participants/export
     */
    public function exportParticipants(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $participants = TournamentParticipant::with(['user','team'])->where('tournament_id',$tournament->id)->get();

        $callback = function() use ($participants) {
            $out = fopen('php://output','w');
            fputcsv($out, ['id','participant_type','user_id','username','team_id','team_name','status','registered_at']);
            foreach ($participants as $p) {
                $row = [
                    $p->id,
                    $p->participant_type,
                    $p->user_id,
                    $p->user?->username,
                    $p->team_id,
                    $p->team?->name,
                    $p->status,
                    $p->registered_at,
                ];
                fputcsv($out, $row);
            }
            fclose($out);
        };

        $filename = 'tournament_'.$tournament->id.'_participants_'.date('Ymd_His').'.csv';
        return response()->streamDownload($callback, $filename, ['Content-Type'=>'text/csv']);
    }

    /**
     * Export results CSV (streamed)
     * POST /api/tournaments/{id}/results/export
     */
    public function exportResults(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $matchups = \App\Models\TeamMatchup::where('tournament_id',$tournament->id)->with(['event'])->orderBy('round_number')->get();

        $callback = function() use ($matchups) {
            $out = fopen('php://output','w');
            fputcsv($out, ['id','event_id','round','match_number','team_a_id','team_b_id','team_a_score','team_b_score','winner_team_id','status','completed_at']);
            foreach ($matchups as $m) {
                fputcsv($out, [
                    $m->id,
                    $m->event_id,
                    $m->round_number,
                    $m->match_number,
                    $m->team_a_id,
                    $m->team_b_id,
                    $m->team_a_score,
                    $m->team_b_score,
                    $m->winner_team_id,
                    $m->status,
                    $m->completed_at,
                ]);
            }
            fclose($out);
        };

        $filename = 'tournament_'.$tournament->id.'_results_'.date('Ymd_His').'.csv';
        return response()->streamDownload($callback, $filename, ['Content-Type'=>'text/csv']);
    }

    /**
     * Check-in to an event using QR code
     */
    public function checkinQR(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'qr_data' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error','message' => 'Validation failed','errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $userId = $user->id;

        $qrData = json_decode($request->qr_data, true);
        $eventId = $qrData['event_id'] ?? null;
        if (!$eventId) return response()->json(['status'=>'error','message'=>'Invalid QR code data.'], 400);

        $event = Event::find($eventId);
        if (! $event) return response()->json(['status'=>'error','message'=>'Event not found'], 404);

        if ($approvalResponse = $this->approvalRequiredResponse($event)) return $approvalResponse;
        if ($event->cancelled_at) return response()->json(['status'=>'error','message'=>'Cannot check-in to a cancelled event.'], 400);

        $participant = EventParticipant::where('event_id', $event->id)->where('user_id', $userId)->first();
        if (! $participant) return response()->json(['status'=>'error','message'=>'You are not a participant in this event.'], 403);

        $existingCheckin = \App\Models\EventCheckin::where('event_id', $event->id)->where('user_id', $userId)->first();
        if ($existingCheckin) return response()->json(['status'=>'error','message'=>'You have already checked in to this event.'], 409);

        $checkin = \App\Models\EventCheckin::create([
            'event_id' => $event->id,
            'user_id' => $userId,
            'checked_in_by' => $userId,
            'checkin_type' => 'qr_self',
            'checkin_time' => now(),
        ]);

        $participant->update(['status' => 'checked_in']);

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully checked in to the event.',
            'checkin' => $checkin,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'venue_name' => $event->venue?->name,
            ]
        ]);
    }

    /**
     * Check-in to an event using 4-digit code
     */
    public function checkinCode(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
            'code' => 'required|string|size:4',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $user = auth()->user();
        $userId = $user->id;
        $event = Event::find($request->event_id);
        if (! $event) return response()->json(['status'=>'error','message'=>'Event not found.'], 404);

        if ($approvalResponse = $this->approvalRequiredResponse($event)) return $approvalResponse;
        if ($event->checkin_code !== $request->code) return response()->json(['status'=>'error','message'=>'Invalid check-in code.'], 400);
        if ($event->cancelled_at) return response()->json(['status'=>'error','message'=>'Cannot check-in to a cancelled event.'], 400);

        $participant = EventParticipant::where('event_id', $event->id)->where('user_id', $userId)->first();
        if (! $participant) return response()->json(['status'=>'error','message'=>'You are not a participant in this event.'], 403);

        $existingCheckin = \App\Models\EventCheckin::where('event_id', $event->id)->where('user_id', $userId)->first();
        if ($existingCheckin) return response()->json(['status'=>'error','message'=>'You have already checked in to this event.'], 409);

        $checkin = \App\Models\EventCheckin::create([
            'event_id' => $event->id,
            'user_id' => $userId,
            'checked_in_by' => $userId,
            'checkin_type' => 'code_entry',
            'checkin_time' => now(),
        ]);

        $participant->update(['status' => 'checked_in']);

        return response()->json(['status'=>'success','message'=>'Successfully checked in to the event.','checkin'=>$checkin]);
    }

    /**
     * Manual check-in by event organizer
     */
    public function checkinManual(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $organizerId = auth()->user()->id;
        $event = Event::find($request->event_id);
        if (! $event) return response()->json(['status'=>'error','message'=>'Event not found.'], 404);

        if ($approvalResponse = $this->approvalRequiredResponse($event)) return $approvalResponse;
        if ($event->created_by !== $organizerId) return response()->json(['status'=>'error','message'=>'You can only check-in participants for events you created.'], 403);
        if ($event->cancelled_at) return response()->json(['status'=>'error','message'=>'Cannot check-in participants for a cancelled event.'], 400);

        $participant = EventParticipant::where('event_id', $event->id)->where('user_id', $request->user_id)->first();
        if (! $participant) return response()->json(['status'=>'error','message'=>'User is not a participant in this event.'], 404);

        $existingCheckin = \App\Models\EventCheckin::where('event_id', $event->id)->where('user_id', $request->user_id)->first();
        if ($existingCheckin) return response()->json(['status'=>'error','message'=>'User has already checked in to this event.'], 409);

        $checkin = \App\Models\EventCheckin::create([
            'event_id' => $event->id,
            'user_id' => $request->user_id,
            'checked_in_by' => $organizerId,
            'checkin_type' => 'manual_by_organizer',
            'checkin_time' => now(),
        ]);

        $participant->update(['status' => 'checked_in']);

        return response()->json(['status'=>'success','message'=>'User successfully checked in to the event.','checkin'=>$checkin]);
    }

    /**
     * View check-in status for an event
     */
    public function viewCheckins(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'error','message'=>'Validation failed','errors'=>$validator->errors()], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);
        if (! $event) return response()->json(['status'=>'error','message'=>'Event not found.'], 404);

        if (! $this->canViewEvent($event, $userId)) {
            return response()->json(['status'=>'error','message'=>'Event is pending venue approval'], 403);
        }

        $isCreator = $event->created_by === $userId;
        $isParticipant = EventParticipant::where('event_id', $event->id)->where('user_id', $userId)->exists();
        if (! $isCreator && ! $isParticipant) {
            return response()->json(['status'=>'error','message'=>'You can only view check-ins for events you created or participate in.'], 403);
        }

        $participants = EventParticipant::where('event_id', $event->id)->with(['user','team'])->get()->map(function($participant) use ($event) {
            $checkin = \App\Models\EventCheckin::where('event_id', $event->id)->where('user_id', $participant->user_id)->first();
            return [
                'user_id' => $participant->user_id,
                'username' => $participant->user?->username,
                'profile_photo' => $participant->user?->profile_photo ? \Storage::url($participant->user->profile_photo) : null,
                'status' => $participant->status,
                'checked_in' => $checkin ? true : false,
                'checkin_time' => $checkin?->checkin_time,
                'checkin_type' => $checkin?->checkin_type,
                'team_id' => $participant->team_id,
                'team_name' => $participant->team?->name,
            ];
        });

        $checkedInCount = $participants->where('checked_in', true)->count();
        $totalParticipants = $participants->count();

        return response()->json([
            'status'=>'success',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'checkin_code' => $event->checkin_code,
                'status' => $event->status,
                'is_approved' => (bool) $event->is_approved,
                'approval_status' => $event->is_approved ? 'approved' : 'pending',
                'approved_at' => $event->approved_at,
            ],
            'checkin_summary' => [
                'total_participants' => $totalParticipants,
                'checked_in' => $checkedInCount,
                'not_checked_in' => $totalParticipants - $checkedInCount,
            ],
            'participants' => $participants
        ]);
    }

    protected function approvalRequiredResponse($event)
    {
        if ($event->is_approved) return null;

        $user = auth()->user();
        if ($user && ($event->created_by === $user->id)) return null;

        // allow tournament organizers to proceed
        if ($event->tournament_id && TournamentOrganizer::where('tournament_id', $event->tournament_id)->where('user_id', $user->id)->exists()) {
            return null;
        }

        return response()->json(['status'=>'error','message'=>'Event pending venue approval'], 403);
    }

    /**
     * Helper: can the user view event (approval/ownership checks)
     */
    protected function canViewEvent($event, $userId)
    {
        if ($event->is_approved) return true;
        if ($event->created_by === $userId) return true;
        if ($event->tournament_id && TournamentOrganizer::where('tournament_id', $event->tournament_id)->where('user_id', $userId)->exists()) {
            return true;
        }
        return false;
    }


    /**
     * Reset a match to pre-start (admin).
     * POST /api/tournaments/{id}/matches/{matchId}/reset
     */
    public function resetMatch(Request $request, $tournamentId, $matchId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        $teamMatchup = \App\Models\TeamMatchup::where('tournament_id',$tournament->id)->where('event_id',$matchId)->first();
        if (! $teamMatchup) return response()->json(['status'=>'error','message'=>'Match not found in bracket'], 404);

        $teamMatchup->update([
            'status' => 'pending',
            'team_a_score' => null,
            'team_b_score' => null,
            'winner_team_id' => null,
            'completed_at' => null,
            'is_disputed' => false,
            'dispute_reason' => null,
            'notes' => null,
        ]);

        // also reset Event record if exists
        $event = Event::where('tournament_id',$tournament->id)->where('id',$matchId)->first();
        if ($event) {
            $event->update(['status' => 'pending', 'score_home' => null, 'score_away' => null, 'winner_team_id' => null, 'completed_at' => null]);
        }

        return response()->json(['status'=>'success','message'=>'Match reset']);
    }

    /**
     * Reset bracket for event (delete matchups) — admin only.
     * POST /api/tournaments/{id}/bracket/reset
     */
    public function resetBracket(Request $request, $tournamentId, $eventId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        if (! $tournament) return response()->json(['status'=>'error','message'=>'Tournament not found'], 404);

        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id',$tournament->id)->where('user_id',$user->id)->whereIn('role',['owner','organizer'])->exists();
        if (! $isCreator && ! $isOrganizer) return response()->json(['status'=>'error','message'=>'Forbidden'], 403);

        \App\Models\TeamMatchup::where('tournament_id',$tournament->id)->where('event_id',$eventId)->delete();

        return response()->json(['status'=>'success','message'=>'Bracket reset for event']);
    }

    /**
     * Get tournament activity log
     * GET /api/tournaments/{tournamentId}/activity-log
     */
    public function getActivityLog(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        
        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Check if user is organizer or creator
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();
        
        if (! $isCreator && ! $isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        // Get audit logs related to this tournament
        $query = AuditLog::where(function($q) use ($tournament) {
            // Direct tournament actions
            $q->where('entity_type', Tournament::class)
              ->where('entity_id', $tournament->id)
              // Tournament events
              ->orWhere(function($subQ) use ($tournament) {
                  $subQ->where('entity_type', Event::class)
                       ->whereIn('entity_id', $tournament->events()->pluck('id'));
              })
              // Tournament participants
              ->orWhere(function($subQ) use ($tournament) {
                  $subQ->where('entity_type', TournamentParticipant::class)
                       ->whereIn('entity_id', $tournament->participants()->pluck('id'));
              })
              // Tournament documents
              ->orWhere(function($subQ) use ($tournament) {
                  $subQ->where('entity_type', TournamentDocument::class)
                       ->whereIn('entity_id', $tournament->documents()->pluck('id'));
              });
        })
        ->with('actor:id,username,first_name,last_name')
        ->orderBy('created_at', 'desc');

        // Filter by action if provided
        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->input('action') . '%');
        }

        // Filter by actor if provided
        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->input('actor_id'));
        }

        // Pagination
        $perPage = min($request->input('per_page', 50), 100);
        $logs = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'activity_log' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Bulk approve participants
     * POST /api/tournaments/{tournamentId}/participants/bulk-approve
     */
    public function bulkApproveParticipants(Request $request, $tournamentId)
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

        $data = $request->validate([
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'required|integer|exists:tournament_participants,id',
        ]);

        $participantIds = $data['participant_ids'];
        $participants = TournamentParticipant::where('tournament_id', $tournament->id)
            ->whereIn('id', $participantIds)
            ->where('status', '!=', 'approved')
            ->get();

        if ($participants->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No participants found to approve',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $approved = [];
            $skipped = [];

            foreach ($participants as $participant) {
                if ($participant->status === 'approved') {
                    $skipped[] = $participant->id;
                    continue;
                }

                $participant->update(['status' => 'approved', 'approved_at' => now()]);

                // Update matching EventParticipant(s)
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

                // Update analytics
                if ($participant->participant_type === 'team') {
                    $tournament->analytics?->increment('total_teams');
                } else {
                    $tournament->analytics?->increment('total_participants');
                }

                $approved[] = $participant->id;
            }

            DB::commit();
            return response()->json([
                'status' => 'success',
                'approved_count' => count($approved),
                'approved_ids' => $approved,
                'skipped_count' => count($skipped),
                'skipped_ids' => $skipped,
                'message' => count($approved) . ' participant(s) approved successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve participants',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get spectator count for tournament matches
     * GET /api/tournaments/{tournamentId}/spectator-count
     */
    public function getSpectatorCount(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        
        if (! $tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Get live matches
        $liveMatches = Event::where('tournament_id', $tournament->id)
            ->where('is_tournament_game', true)
            ->where(function($query) {
                $query->where('game_status', 'in_progress')
                    ->orWhere(function($q) {
                        $q->whereNotNull('date')
                          ->whereNotNull('start_time')
                          ->whereNotNull('end_time')
                          ->where('date', '<=', now()->toDateString())
                          ->whereRaw("CONCAT(date, ' ', start_time) <= ?", [now()->toDateTimeString()])
                          ->whereRaw("CONCAT(date, ' ', end_time) >= ?", [now()->toDateTimeString()]);
                    });
            })
            ->get();

        // For now, return match IDs with placeholder counts
        // In a real implementation, you'd track actual viewers (e.g., via WebSocket connections, Redis, etc.)
        $spectatorData = $liveMatches->map(function($match) {
            return [
                'match_id' => $match->id,
                'match_name' => $match->name,
                'spectator_count' => 0, // Placeholder - implement actual tracking
                'viewers' => [], // Placeholder - implement actual viewer list if needed
            ];
        });

        $totalSpectators = $spectatorData->sum('spectator_count');

        return response()->json([
            'status' => 'success',
            'tournament_id' => $tournament->id,
            'total_spectators' => $totalSpectators,
            'live_matches_count' => $liveMatches->count(),
            'matches' => $spectatorData,
        ]);
    }

    /**
     * Update tournament settings (bulk)
     * PATCH /api/tournaments/{tournamentId}/settings
     */
    public function updateTournamentSettings(Request $request, $tournamentId)
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

        // Only allow settings update in draft or open_registration status
        if (! in_array($tournament->status, ['draft', 'open_registration'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Settings can only be updated when tournament is in draft or open_registration status',
            ], 422);
        }

        $data = $request->validate([
            'settings' => 'required|array',
            // Common settings that can be updated
            'participants_locked' => 'sometimes|boolean',
            'auto_advance_bracket' => 'sometimes|boolean',
            'allow_withdrawal' => 'sometimes|boolean',
            'require_checkin' => 'sometimes|boolean',
            'checkin_deadline_minutes' => 'sometimes|integer|min:0',
            'score_verification_required' => 'sometimes|boolean',
            'public_brackets' => 'sometimes|boolean',
            'public_standings' => 'sometimes|boolean',
        ]);

        $currentSettings = (array) $tournament->settings;
        
        // Merge new settings with existing
        if (isset($data['settings'])) {
            $currentSettings = array_merge($currentSettings, $data['settings']);
        } else {
            // If individual settings provided, merge them
            unset($data['settings']);
            foreach ($data as $key => $value) {
                $currentSettings[$key] = $value;
            }
        }

        $tournament->settings = $currentSettings;
        $tournament->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Tournament settings updated',
            'tournament_id' => $tournament->id,
            'settings' => $currentSettings,
        ]);
    }

    /**
     * Get public tournament standings (no auth required)
     * GET /api/tournaments/public/{tournamentId}/standings
     */
    public function getPublicStandings($tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Only show public tournaments (not drafts)
        if ($tournament->status === 'draft') {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $analyticsService = app(AnalyticsService::class);
        $analyticsService->calculateStandings($tournament);

        $standings = \App\Models\Standing::where('tournament_id', $tournament->id)
            ->orderBy('rank')
            ->get()
            ->map(function($standing) {
                if ($standing->team_id) {
                    $team = $standing->team;
                    return [
                        'rank' => $standing->rank,
                        'team_id' => $standing->team_id,
                        'team_name' => $team?->name ?? 'Unknown',
                        'team_logo' => $team?->logo ?? null,
                        'wins' => $standing->wins,
                        'losses' => $standing->losses,
                        'draws' => $standing->draws,
                        'points' => $standing->points,
                        'win_rate' => $standing->win_rate,
                        'matches_played' => $standing->wins + $standing->losses + $standing->draws,
                    ];
                } else {
                    $user = $standing->user;
                    return [
                        'rank' => $standing->rank,
                        'user_id' => $standing->user_id,
                        'name' => $user ? ($user->first_name . ' ' . $user->last_name) : 'Unknown',
                        'wins' => $standing->wins,
                        'losses' => $standing->losses,
                        'draws' => $standing->draws,
                        'points' => $standing->points,
                        'win_rate' => $standing->win_rate,
                        'matches_played' => $standing->wins + $standing->losses + $standing->draws,
                    ];
                }
            });

        return response()->json([
            'status' => 'success',
            'standings' => $standings,
            'count' => $standings->count(),
            'last_updated' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get public tournament schedule (no auth required)
     * GET /api/tournaments/public/{tournamentId}/schedule
     */
    public function getPublicSchedule(Request $request, $tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Only show public tournaments (not drafts)
        if ($tournament->status === 'draft') {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $now = now();
        $daysAhead = $request->input('days_ahead', 7);
        $cutoffDate = $now->copy()->addDays($daysAhead);

        $query = Event::with(['teams', 'venue', 'facility'])
            ->where('tournament_id', $tournament->id)
            ->where('is_tournament_game', true);

        // Apply filters
        if ($request->filled('round')) {
            $query->where('game_number', $request->input('round'));
        }
        if ($request->filled('team_id')) {
            $query->whereHas('teams', function($q) use ($request) {
                $q->where('team_id', $request->input('team_id'));
            });
        }
        if ($request->filled('start_date')) {
            $query->where('date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->where('date', '<=', $request->input('end_date'));
        }

        $matches = $query->orderBy('date')->orderBy('start_time')->get();

        $upcoming = $matches->filter(function($match) use ($now) {
            $matchDateTime = \Carbon\Carbon::parse($match->date . ' ' . $match->start_time);
            return $matchDateTime->isFuture() && $match->status !== 'completed';
        })->map(function($match) {
            return [
                'id' => $match->id,
                'name' => $match->name,
                'date' => $match->date,
                'start_time' => $match->start_time,
                'end_time' => $match->end_time,
                'game_number' => $match->game_number,
                'status' => $match->status,
                'sport' => $match->sport,
                'venue' => $match->venue ? [
                    'id' => $match->venue->id,
                    'name' => $match->venue->name,
                    'address' => $match->venue->address,
                ] : null,
                'teams' => $match->teams->map(function($et) {
                    return [
                        'id' => $et->team->id ?? null,
                        'name' => $et->team->name ?? null,
                        'logo' => $et->team->logo ?? null,
                    ];
                }),
            ];
        })->values();

        $past = $matches->filter(function($match) use ($now) {
            $matchDateTime = \Carbon\Carbon::parse($match->date . ' ' . $match->start_time);
            return $matchDateTime->isPast() || $match->status === 'completed';
        })->map(function($match) {
            return [
                'id' => $match->id,
                'name' => $match->name,
                'date' => $match->date,
                'start_time' => $match->start_time,
                'end_time' => $match->end_time,
                'game_number' => $match->game_number,
                'status' => $match->status,
                'sport' => $match->sport,
                'venue' => $match->venue ? [
                    'id' => $match->venue->id,
                    'name' => $match->venue->name,
                ] : null,
                'teams' => $match->teams->map(function($et) {
                    return [
                        'id' => $et->team->id ?? null,
                        'name' => $et->team->name ?? null,
                        'score' => null, // Would need to get from EventScore or match
                    ];
                }),
                'score_home' => $match->score_home ?? null,
                'score_away' => $match->score_away ?? null,
                'winner_team_id' => $match->winner_team_id ?? null,
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'upcoming_matches' => $upcoming,
            'past_matches' => $past,
            'upcoming_count' => $upcoming->count(),
            'past_count' => $past->count(),
        ]);
    }

    /**
     * Get public match details (no auth required, limited info)
     * GET /api/tournaments/public/{tournamentId}/matches/{matchId}
     */
    public function getPublicMatchDetail($tournamentId, $matchId)
    {
        $tournament = Tournament::find($tournamentId);
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Only show public tournaments (not drafts)
        if ($tournament->status === 'draft') {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $match = Event::with(['teams.team', 'venue'])
            ->where('tournament_id', $tournament->id)
            ->where('id', $matchId)
            ->where('is_tournament_game', true)
            ->first();

        if (!$match) {
            return response()->json(['status' => 'error', 'message' => 'Match not found'], 404);
        }

        $teamMatchup = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
            ->where('event_id', $match->id)
            ->first();

        return response()->json([
            'status' => 'success',
            'match' => [
                'id' => $match->id,
                'name' => $match->name,
                'date' => $match->date,
                'start_time' => $match->start_time,
                'end_time' => $match->end_time,
                'status' => $match->status,
                'sport' => $match->sport,
                'venue' => $match->venue ? [
                    'id' => $match->venue->id,
                    'name' => $match->venue->name,
                ] : null,
                'teams' => $match->teams->map(function($et) {
                    $team = $et->team;
                    return [
                        'id' => $team->id ?? null,
                        'name' => $team->name ?? null,
                        'logo' => $team->logo ?? null,
                    ];
                }),
                'score_home' => $match->score_home ?? ($teamMatchup ? $teamMatchup->team_a_score : null),
                'score_away' => $match->score_away ?? ($teamMatchup ? $teamMatchup->team_b_score : null),
                'winner_team_id' => $match->winner_team_id ?? ($teamMatchup ? $teamMatchup->winner_team_id : null),
                'completed_at' => $match->completed_at ?? ($teamMatchup ? $teamMatchup->completed_at : null),
            ],
        ]);
    }

    /**
     * Add match note/commentary
     * POST /api/tournaments/{tournamentId}/matches/{matchId}/notes
     */
    public function addMatchNote(Request $request, $tournamentId, $matchId)
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

        $match = Event::where('tournament_id', $tournament->id)
            ->where('id', $matchId)
            ->first();

        if (!$match) {
            return response()->json(['status' => 'error', 'message' => 'Match not found'], 404);
        }

        $data = $request->validate([
            'content' => 'required|string|max:2000',
            'type' => 'nullable|in:commentary,note,update',
            'timestamp' => 'nullable|date',
        ]);

        $note = MatchNote::create([
            'event_id' => $match->id,
            'tournament_id' => $tournament->id,
            'created_by' => $user->id,
            'content' => $data['content'],
            'type' => $data['type'] ?? 'commentary',
            'timestamp' => $data['timestamp'] ?? now(),
        ]);

        return response()->json([
            'status' => 'success',
            'note' => [
                'id' => $note->id,
                'match_id' => $note->event_id,
                'content' => $note->content,
                'type' => $note->type,
                'timestamp' => $note->timestamp,
                'created_by' => [
                    'id' => $user->id,
                    'username' => $user->username,
                ],
                'created_at' => $note->created_at,
            ],
        ], 201);
    }

    /**
     * Get match notes/commentary
     * GET /api/tournaments/{tournamentId}/matches/{matchId}/notes
     */
    public function getMatchNotes(Request $request, $tournamentId, $matchId)
    {
        $tournament = Tournament::find($tournamentId);
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $match = Event::where('tournament_id', $tournament->id)
            ->where('id', $matchId)
            ->first();

        if (!$match) {
            return response()->json(['status' => 'error', 'message' => 'Match not found'], 404);
        }

        $notes = MatchNote::with('creator')
            ->where('event_id', $match->id)
            ->where('tournament_id', $tournament->id)
            ->orderBy('timestamp', 'asc')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function($note) {
                return [
                    'id' => $note->id,
                    'match_id' => $note->event_id,
                    'content' => $note->content,
                    'type' => $note->type,
                    'timestamp' => $note->timestamp,
                    'created_by' => [
                        'id' => $note->creator->id ?? null,
                        'username' => $note->creator->username ?? null,
                    ],
                    'created_at' => $note->created_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'notes' => $notes,
            'count' => $notes->count(),
        ]);
    }

    /**
     * Get team profile within tournament context
     * GET /api/tournaments/{tournamentId}/teams/{teamId}
     */
    public function getTournamentTeamProfile(Request $request, $tournamentId, $teamId)
    {
        $tournament = Tournament::find($tournamentId);
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $team = Team::with('members.user')->find($teamId);
        if (!$team) {
            return response()->json(['status' => 'error', 'message' => 'Team not found'], 404);
        }

        // Check if team is a participant
        $participant = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('team_id', $teamId)
            ->where('status', 'approved')
            ->first();

        if (!$participant) {
            return response()->json(['status' => 'error', 'message' => 'Team is not a participant in this tournament'], 404);
        }

        // Get standings
        $standing = \App\Models\Standing::where('tournament_id', $tournament->id)
            ->where('team_id', $teamId)
            ->first();

        // Get matchups for this team
        $matchups = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
            ->where(function($q) use ($teamId) {
                $q->where('team_a_id', $teamId)->orWhere('team_b_id', $teamId);
            })
            ->where('status', 'completed')
            ->orderBy('completed_at', 'desc')
            ->get();

        $wins = 0;
        $losses = 0;
        $draws = 0;
        $pastResults = [];
        $totalPointsScored = 0;
        $totalPointsAgainst = 0;

        foreach ($matchups as $matchup) {
            $isTeamA = $matchup->team_a_id === $teamId;
            $opponentId = $isTeamA ? $matchup->team_b_id : $matchup->team_a_id;
            $opponent = Team::find($opponentId);
            
            $teamScore = $isTeamA ? $matchup->team_a_score : $matchup->team_b_score;
            $opponentScore = $isTeamA ? $matchup->team_b_score : $matchup->team_a_score;
            
            $totalPointsScored += $teamScore ?? 0;
            $totalPointsAgainst += $opponentScore ?? 0;

            $result = 'draw';
            if ($matchup->winner_team_id === $teamId) {
                $wins++;
                $result = 'win';
            } elseif ($matchup->winner_team_id === $opponentId) {
                $losses++;
                $result = 'loss';
            } else {
                $draws++;
            }

            $pastResults[] = [
                'match_id' => $matchup->event_id,
                'date' => $matchup->completed_at?->toDateString(),
                'opponent' => [
                    'id' => $opponent->id ?? null,
                    'name' => $opponent->name ?? null,
                    'logo' => $opponent->logo ?? null,
                ],
                'result' => $result,
                'score' => ($teamScore ?? 0) . '-' . ($opponentScore ?? 0),
                'team_score' => $teamScore ?? 0,
                'opponent_score' => $opponentScore ?? 0,
            ];
        }

        // Get upcoming matches
        $upcomingMatchups = \App\Models\TeamMatchup::where('tournament_id', $tournament->id)
            ->where(function($q) use ($teamId) {
                $q->where('team_a_id', $teamId)->orWhere('team_b_id', $teamId);
            })
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->orderBy('scheduled_at', 'asc')
            ->get();

        $upcomingMatches = [];
        foreach ($upcomingMatchups as $matchup) {
            $isTeamA = $matchup->team_a_id === $teamId;
            $opponentId = $isTeamA ? $matchup->team_b_id : $matchup->team_a_id;
            $opponent = Team::find($opponentId);
            $event = Event::find($matchup->event_id);

            $upcomingMatches[] = [
                'match_id' => $matchup->event_id,
                'name' => $event->name ?? null,
                'date' => $event->date ?? null,
                'start_time' => $event->start_time ?? null,
                'opponent' => [
                    'id' => $opponent->id ?? null,
                    'name' => $opponent->name ?? null,
                    'logo' => $opponent->logo ?? null,
                ],
            ];
        }

        // Calculate form (last 5 matches)
        $form = array_slice(array_map(function($r) {
            return strtoupper(substr($r['result'], 0, 1));
        }, $pastResults), 0, 5);

        $matchesPlayed = $wins + $losses + $draws;
        $winRate = $matchesPlayed > 0 ? ($wins / $matchesPlayed) * 100 : 0;

        return response()->json([
            'status' => 'success',
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'logo' => $team->logo,
                'members' => $team->members->map(function($member) {
                    return [
                        'id' => $member->id,
                        'user_id' => $member->user_id,
                        'username' => $member->user->username ?? null,
                        'first_name' => $member->user->first_name ?? null,
                        'last_name' => $member->user->last_name ?? null,
                        'role' => $member->role,
                    ];
                }),
                'tournament_record' => [
                    'wins' => $wins,
                    'losses' => $losses,
                    'draws' => $draws,
                    'win_rate' => $winRate,
                    'points' => $standing->points ?? 0,
                    'rank' => $standing->rank ?? null,
                    'matches_played' => $matchesPlayed,
                ],
                'seeding' => $participant->seed ?? null,
                'total_points_scored' => $totalPointsScored,
                'total_points_against' => $totalPointsAgainst,
                'point_differential' => $totalPointsScored - $totalPointsAgainst,
                'upcoming_matches' => $upcomingMatches,
                'past_results' => array_slice($pastResults, 0, 10), // Last 10 matches
                'form' => $form,
            ],
        ]);
    }
}
