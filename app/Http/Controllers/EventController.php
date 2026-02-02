<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Venue;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventCheckin;
use App\Models\EventScore;
use App\Models\EventTeam;
use App\Models\EventGame;
use App\Models\VenueReview;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\TeamMember; // ADDED
use App\Models\TeamRating;
use App\Models\Booking;
use App\Models\VenueUser;
use App\Models\MessageThread;
use App\Models\ThreadParticipant;
use App\Models\PlayerRating;
use App\Mail\EventCancelledMail;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

class EventController extends Controller
{
    /**
     * Generate a unique 4-digit check-in code
     */
    private function generateCheckinCode()
    {
        do {
            $code = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        } while (Event::where('checkin_code', $code)->exists());
        
        return $code;
    }

    /**
     * Generate a unique share token for events
     */
    private function generateShareToken()
    {
        do {
            $token = bin2hex(random_bytes(16)); // 32 character hex string
        } while (Event::where('share_token', $token)->exists());
        
        return $token;
    }

    private function userManagesVenue(Event $event, ?int $userId): bool
    {
        if (! $userId || ! class_exists(\App\Models\VenueUser::class)) {
            return false;
        }

        return VenueUser::where('venue_id', $event->venue_id)
            ->where('user_id', $userId)
            ->whereIn('role', ['owner', 'manager', 'Owner', 'Manager'])
            ->exists();
    }

    private function canViewEvent(Event $event, ?int $userId): bool
    {
        if ($event->is_approved) {
            return true;
        }

        if (! $userId) {
            return false;
        }

        if ($event->created_by === $userId) {
            return true;
        }

        return $this->userManagesVenue($event, $userId);
    }

    private function approvalRequiredResponse(Event $event)
    {
        if ($event->is_approved) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Event is pending venue approval.'
        ], 403);
    }
    /**
     * Display a listing of the resource.
     */
   public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 5), 100);
        $page = (int) $request->input('page', 1);

        // Build base query for regular events (exclude tournament games)
        $query = Event::with(['venue.photos', 'facility', 'teams.team'])
            ->where('is_approved', true)
            ->where('is_tournament_game', false)
            ->whereNull('cancelled_at')
            ->withCount('participants');

        // Filters
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }
        if ($request->filled('start_date')) {
            $query->where('date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->where('date', '<=', $request->input('end_date'));
        }
        if ($request->filled('day')) {
            $dayOfWeek = (int) $request->input('day');
            if ($dayOfWeek >= 0 && $dayOfWeek <= 6) {
                $mysqlDay = $dayOfWeek + 1;
                $query->whereRaw('DAYOFWEEK(date) = ?', [$mysqlDay]);
            }
        }
        if ($request->filled('time_from')) {
            $query->where('start_time', '>=', $request->input('time_from'));
        }
        if ($request->filled('time_to')) {
            $query->where('start_time', '<=', $request->input('time_to'));
        }
        if ($request->filled('sport')) {
            $query->where('sport', $request->input('sport'));
        }

        // paginate
        $paginated = $query->orderBy('date')->orderBy('start_time')
            ->paginate($perPage, ['*'], 'page', $page);

        $events = $paginated->getCollection()->map(function($event) {
            // Calculate hours
            $start = Carbon::parse($event->start_time);
            $end = Carbon::parse($event->end_time);
            $hours = $start->diffInMinutes($end) / 60;

            // Calculate total cost
            $pricePerHour = $event->facility->price_per_hr ?? 0;
            $totalCost = $hours * $pricePerHour;

            $divide = $event->participants_count > 0
                ? round($totalCost / $event->participants_count, 2)
                : 0;
            $dividedpay = $divide;

            // Venue photo
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
                'participants_count' => $event->participants_count,
                'total_slots' => $event->slots,
                'venue' => $event->venue->name ?? null,
                'facility' => $event->facility->type ?? null,
                'longitude' => $event->venue->longitude ?? null,
                'latitude' => $event->venue->latitude ?? null,
                'venue_photo_url' => $venuePhotoUrl,
                'hours' => $hours,
                'total_cost' => $totalCost,
                'cost_per_slot' => $dividedpay,
                'host' => User::find($event->created_by)->username,
                'event_type' => $event->event_type,
                'is_approved' => (bool) $event->is_approved,
                'approval_status' => $event->is_approved ? 'approved' : 'pending',
                'approved_at' => $event->approved_at,
            ];

            if ($event->event_type === 'team vs team') {
                $eventData['teams'] = $event->teams->map(function($eventTeam) {
                    return [
                        'team_id' => $eventTeam->team_id,
                        'team_name' => $eventTeam->team->name ?? 'Unknown Team',
                    ];
                });
                $eventData['teams_count'] = $event->teams->count();
                $eventData['slots_display'] = $event->teams->count() . '/' . $event->slots . ' teams';
            } else {
                $eventData['slots_display'] = $event->participants_count . '/' . $event->slots . ' participants';
            }

            return $eventData;
        })->values();

        return response()->json([
            'status' => 'success',
            'events' => $events,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Get suggested tournament games based on user location
     * Returns tournament games near the user's location
     */
    public function getSuggestedTournamentGames(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'radius' => 'sometimes|numeric|min:1|max:100', // radius in km, default 10km
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $radius = $request->input('radius', 10); // default 10km

        // Calculate distance using Haversine formula
        $haversine = "(6371 * acos(cos(radians(?)) 
            * cos(radians(venues.latitude)) 
            * cos(radians(venues.longitude) - radians(?)) 
            + sin(radians(?)) 
            * sin(radians(venues.latitude))))";

        $tournamentGames = Event::with(['venue.photos', 'facility', 'tournament'])
            ->join('venues', 'events.venue_id', '=', 'venues.id')
            ->where('events.is_approved', true)
            ->where('events.is_tournament_game', true)
            ->whereNotNull('venues.latitude')
            ->whereNotNull('venues.longitude')
            ->selectRaw("events.*, {$haversine} AS distance", [$latitude, $longitude, $latitude])
            ->havingRaw("distance <= ?", [$radius])
            ->orderBy('distance')
            ->limit(20) // Limit to 20 nearest tournament games
            ->get()
            ->map(function($event) {
                // Determine venue primary photo url
                $firstPhotoPath = null;
                if ($event->venue && $event->venue->photos && $event->venue->photos->count() > 0) {
                    $firstPhoto = $event->venue->photos->sortByDesc('uploaded_at')->first();
                    $firstPhotoPath = $firstPhoto ? $firstPhoto->image_path : null;
                }
                $venuePhotoUrl = $firstPhotoPath ? url('storage/' . ltrim($firstPhotoPath, '/')) : null;

                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'description' => $event->description,
                    'sport' => $event->sport,
                    'date' => $event->date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'venue' => $event->venue->name,
                    'facility' => $event->facility->type ?? null,
                    'longitude' => $event->venue->longitude ?? null,
                    'latitude' => $event->venue->latitude ?? null,
                    'venue_photo_url' => $venuePhotoUrl,
                    'distance' => round($event->distance, 2), // distance in km
                    'tournament_id' => $event->tournament_id,
                    'tournament_name' => $event->tournament->name ?? null,
                    'is_tournament_game' => true,
                    'is_suggested' => true, // Mark as suggested
                ];
            });

        return response()->json([
            'status' => 'success',
            'suggested_tournament_games' => $tournamentGames,
            'count' => $tournamentGames->count()
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        
    }

    public function eventlist($event_id)
    {
        $event = Event::with(['venue', 'facility', 'teams.team'])
            ->withCount('participants')
            ->find($event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found'
            ], 404);
        }

        $userId = auth()->id();
        if (! $this->canViewEvent($event, $userId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event is pending venue approval'
            ], 403);
        }

        // Calculate hours
        $start = \Carbon\Carbon::parse($event->start_time);
        $end = \Carbon\Carbon::parse($event->end_time);
        $hours = $start->diffInMinutes($end) / 60;

        // Calculate total cost
        $pricePerHour = $event->facility->price_per_hr ?? 0;
        $totalCost = $hours * $pricePerHour;

        $divide = $event->participants_count > 0 ? $totalCost / $event->participants_count : 0;
        $dividedpay = round($divide, 2);

        // Get participants with user info
        $participants = EventParticipant::where('event_id', $event_id)
            ->with('user')
            ->get();

        $eventData = [
            'id' => $event->id,
            'name' => $event->name,
            'description' => $event->description,
            'date' => $event->date,
            'time' => $event->start_time . ' - ' . $event->end_time,
            'creator' => User::find($event->created_by)->username,
            'venue' => [
                'name' => $event->venue->name,
                'address' => $event->venue->address,
            ],
            'Price/hour' => $event->facility->price_per_hr ?? 0,
            'Chip-in approx' => $dividedpay,
            'event_type' => $event->event_type,
            'is_approved' => (bool) $event->is_approved,
            'approval_status' => $event->is_approved ? 'approved' : 'pending',
            'approved_at' => $event->approved_at,
        ];

        // Add team-specific information for team vs team events
        if ($event->event_type === 'team vs team') {
            $eventData['slots'] = $event->teams->count() . '/' . $event->slots . ' teams';
            $eventData['teams'] = $event->teams->map(function($eventTeam) {
                $teamMembers = EventParticipant::where('event_id', $eventTeam->event_id)
                    ->where('team_id', $eventTeam->team_id)
                    ->with('user')
                    ->get();
                
                return [
                    'team_id' => $eventTeam->team_id,
                    'team_name' => $eventTeam->team->name ?? 'Unknown Team',
                    'members' => $teamMembers->map(function($member) {
                        return $member->user ? [
                            'user_id' => $member->user->id,
                            'username' => $member->user->username,
                            'profile_photo' => $member->user->profile_photo ? Storage::url($member->user->profile_photo) : null,
                        ] : [
                            'user_id' => null,
                            'username' => 'Unknown User',
                            'profile_photo' => null,
                        ];
                    })
                ];
            });
        } else {
            $eventData['slots'] = $event->participants_count . '/' . $event->slots;
            $eventData['participants'] = [
                'name' => $participants->map(function($participant) {
                    return $participant->user ? [
                        'user_id' => $participant->user->id,
                        'username' => $participant->user->username,
                        'profile_photo' => $participant->user->profile_photo ? Storage::url($participant->user->profile_photo) : null,
                        'team_id' => $participant->team_id,
                    ] : [
                        'user_id' => null,
                        'username' => 'Unknown User',
                        'profile_photo' => null,
                        'team_id' => $participant->team_id,
                    ];
                })
            ];
        }

        return response()->json([
            'status' => 'success',
            'event' => $eventData,
        ]);
    }


    public function allschedule(){
        $user = auth()->user();

        // Get all events where user is a participant or creator
        $events = Event::with(['venue', 'facility', 'participants', 'checkins'])
            ->where(function($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere(function($inner) use ($user) {
                      $inner->where('is_approved', true)
                            ->whereHas('participants', function($q2) use ($user) {
                                $q2->where('user_id', $user->id);
                            });
                  });
            })
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(function($event) use ($user) {
                // Get current user's check-in status if they're a participant
                $userCheckin = $event->checkins->where('user_id', $user->id)->first();
                
                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'description' => $event->description,
                    'event_type' => $event->event_type,
                    'date' => $event->date,
                    'sport' => $event->sport,
                    'host' => User::find($event->created_by)->username ?? null,
                    'venue' => $event->venue->name ?? null,
                    'longitude' => $event->venue->longitude ?? null,
                    'latitude' => $event->venue->latitude ?? null,
                    'facility' => $event->facility->type ?? null,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'slots' => $event->slots,
                    'participants_count' => $event->participants->count(),
                    'is_approved' => (bool) $event->is_approved,
                    'approval_status' => $event->is_approved ? 'approved' : 'pending',
                    'approved_at' => $event->approved_at,
                    'cancelled_at' => $event->cancelled_at,
                    // Check-in related fields
                    'checkin_code' => $event->checkin_code,
                    'can_checkin' => $event->is_approved && !$event->cancelled_at,
                    'user_checked_in' => $userCheckin ? true : false,
                    'user_checkin_time' => $userCheckin ? $userCheckin->checkin_time : null,
                    // Indicate if user is the creator
                    'is_creator' => $event->created_by === $user->id,
                ];
            });

        return response()->json([
            'status' => 'success',
            'schedule' => $events
        ]);
    }

    public function allusercreated(Request $request)
    {
        $user = auth()->user();

        $perPage = min((int) $request->input('per_page', 5), 100);
        $page = (int) $request->input('page', 1);

        $query = Event::with(['venue', 'facility', 'participants', 'checkins'])
            ->where('created_by', $user->id)
            ->orderBy('date')
            ->orderBy('start_time');

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $events = $paginated->getCollection()->map(function($event) use ($user) {
            $userCheckin = $event->checkins->where('user_id', $user->id)->first();
            
            return [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'event_type' => $event->event_type,
                'date' => $event->date,
                'sport' => $event->sport,
                'host' => User::find($event->created_by)->username ?? null,
                'venue' => $event->venue->name ?? null,
                'longitude' => $event->venue->longitude ?? null,
                'latitude' => $event->venue->latitude ?? null,
                'facility' => $event->facility->type ?? null,
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
                'slots' => $event->slots,
                'participants_count' => $event->participants->count(),
                'is_approved' => (bool) $event->is_approved,
                'approval_status' => $event->is_approved ? 'approved' : 'pending',
                'approved_at' => $event->approved_at,
                'cancelled_at' => $event->cancelled_at,
                'checkin_code' => $event->checkin_code,
                'can_checkin' => $event->is_approved && !$event->cancelled_at,
                'user_checked_in' => $userCheckin ? true : false,
                'user_checkin_time' => $userCheckin ? $userCheckin->checkin_time : null,
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'schedule' => $events,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function eventsByVenue(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'venue_id' => 'required|exists:venues,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $events = Event::with(['venue.photos', 'facility'])
            ->where('venue_id', $request->venue_id)
            ->where('is_approved', true)
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(function($event) {
                // Determine venue primary photo url (latest upload if available)
                $firstPhotoPath = null;
                if ($event->venue && $event->venue->photos && $event->venue->photos->count() > 0) {
                    $firstPhoto = $event->venue->photos->sortByDesc('uploaded_at')->first();
                    $firstPhotoPath = $firstPhoto ? $firstPhoto->image_path : null;
                }
                $venuePhotoUrl = $firstPhotoPath ? url('storage/' . ltrim($firstPhotoPath, '/')) : null;
                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'description' => $event->description,
                    'date' => $event->date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'host' => User::find($event->created_by)->username ?? null,
                    'venue' => $event->venue->name ?? null,
                    'venue_photo_url' => $venuePhotoUrl,
                    'facility' => $event->facility->type ?? null,
                    'slots' => $event->slots,
                    'is_approved' => (bool) $event->is_approved,
                    'approval_status' => $event->is_approved ? 'approved' : 'pending',
                    'approved_at' => $event->approved_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'events' => $events
        ]);
    }

    public function userschedule($date)
    {
        $user = auth()->user();

        // Get events where user is a participant or creator, and date matches the input
        $events = Event::with(['venue', 'facility'])
            ->where(function($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere(function($inner) use ($user) {
                      $inner->where('is_approved', true)
                            ->whereHas('participants', function($q2) use ($user) {
                                $q2->where('user_id', $user->id);
                            });
                  });
            })
            ->where('date', $date) // <-- filter by input date
            ->orderBy('start_time')
            ->get()
            ->map(function($event) {
                return [
                    'id' => $event->id,
                    'date' => $event->date,
                    'sport' => $event->sport,
                    'host' => User::find($event->created_by)->username ?? null,
                    'venue' => $event->venue->name ?? null,
                    'longitude' => $event->venue->longitude ?? null,
                    'latitude' => $event->venue->latitude ?? null,
                    'facility' => $event->facility->type ?? null,
                    'start_time' => $event->start_time,
                    'is_approved' => (bool) $event->is_approved,
                    'approval_status' => $event->is_approved ? 'approved' : 'pending',
                    'approved_at' => $event->approved_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'schedule' => $events
        ]);
    }

    /**
     * Helper method to process events and categorize them
     */
    private function processEventGroup($events, $user, $today, $tomorrow, $now, $userRatingsByEvent, $teamRatingsByEvent = [], $userTeamIds = [])
    {
        $processed = $events->map(function($event) use ($user, $today, $tomorrow, $now, $userRatingsByEvent, $teamRatingsByEvent, $userTeamIds) {
            // Get current user's check-in status if they're a participant
            $userCheckin = $event->checkins->where('user_id', $user->id)->first();
            
            // Determine if event is cancelled
            $isCancelled = !is_null($event->cancelled_at);
            
            // Handle end_time - use start_time if end_time is null
            $endTime = $event->end_time ?? $event->start_time ?? '23:59:59';
            $startTime = $event->start_time ?? '00:00:00';
            
            // Create event datetime using Carbon for proper timezone handling
            $eventDate = Carbon::parse($event->date)->format('Y-m-d');
            $eventStartDateTime = Carbon::parse($eventDate . ' ' . $startTime);
            $eventEndDateTime = Carbon::parse($eventDate . ' ' . $endTime);
            
            // Determine if game is past/completed (only if not cancelled) - based on date+time
            $isPast = !$isCancelled && $eventEndDateTime->isPast();
            
            // Extract date part from event date
            $eventDateOnly = Carbon::parse($event->date)->format('Y-m-d');
            
            // Determine if game is today
            $isToday = !$isCancelled && $eventDateOnly === $today;
            
            // Determine if game is tomorrow
            $isTomorrow = !$isCancelled && $eventDateOnly === $tomorrow;
            
            // Determine if game is upcoming (future, not today or tomorrow)
            $isUpcoming = !$isCancelled && !$isPast && !$isToday && !$isTomorrow;
            
            // Calculate hours until event start for 24h proximity priority
            $hoursUntilStart = $isUpcoming ? $now->diffInHours($eventStartDateTime, false) : null;
            $isWithin24Hours = $isUpcoming && $hoursUntilStart !== null && $hoursUntilStart <= 24 && $hoursUntilStart >= 0;
            
            // Check if current user has already rated players in this event
            $userHasRated = in_array($event->id, $userRatingsByEvent);
            
            // Calculate sort priority
            // 0 = Within 24 hours (if upcoming) - NEW
            // 1 = Today
            // 2 = Tomorrow
            // 3 = Upcoming (>= 24 hours away)
            // 4 = Past
            // 5 = Cancelled (always bottom)
            if ($isCancelled) {
                $priority = 5;
                $priority24h = 1; // Not applicable for cancelled
            } elseif ($isToday) {
                $priority = 1;
                $priority24h = 0; // Not applicable for today
            } elseif ($isTomorrow) {
                $priority = 2;
                $priority24h = 0; // Not applicable for tomorrow
            } elseif ($isWithin24Hours) {
                $priority = 0; // Highest priority for events within 24 hours
                $priority24h = 0;
            } elseif ($isUpcoming) {
                $priority = 3;
                $priority24h = 1; // >= 24 hours away
            } else {
                $priority = 4; // Past
                $priority24h = 1; // Not applicable for past
            }
            
            return [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'event_type' => $event->event_type,
                'date' => $event->date,
                'sport' => $event->sport,
                'host' => User::find($event->created_by)->username ?? null,
                'venue' => $event->venue->name ?? null,
                'longitude' => $event->venue->longitude ?? null,
                'latitude' => $event->venue->latitude ?? null,
                'facility' => $event->facility->type ?? null,
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
                'slots' => $event->slots,
                'participants_count' => $event->participants->count(),
                'is_approved' => (bool) $event->is_approved,
                'approval_status' => $event->is_approved ? 'approved' : 'pending',
                'approved_at' => $event->approved_at,
                'cancelled_at' => $event->cancelled_at,
                // Check-in related fields
                'checkin_code' => $event->checkin_code,
                'can_checkin' => $event->is_approved && !$isCancelled && !$isPast,
                'user_checked_in' => $userCheckin ? true : false,
                'user_checkin_time' => $userCheckin ? $userCheckin->checkin_time : null,
                // Indicate if user is the creator
                'is_creator' => $event->created_by === $user->id,
                // Status fields
                'is_past' => $isPast,
                'is_today' => $isToday,
                'is_tomorrow' => $isTomorrow,
                'is_upcoming' => $isUpcoming,
                'is_within_24h' => $isWithin24Hours,
                'status' => $isCancelled ? 'cancelled' : ($isPast ? 'completed' : ($isToday ? 'today' : ($isTomorrow ? 'tomorrow' : ($isWithin24Hours ? 'upcoming_24h' : 'upcoming')))),
                'user_has_rated' => $userHasRated,
                'is_rating_pending' => !$userHasRated && $isPast,
                'sort_priority' => $priority,
                'priority' => $priority, // Keep for backwards compatibility
                'priority_24h' => $priority24h, // For sorting upcoming by proximity
                'hours_until_start' => $hoursUntilStart, // Hours until event start for "happening soon" display
                // Team rating information for team vs team events
                'team_ratings' => ($event->event_type === 'team vs team' && isset($teamRatingsByEvent[$event->id])) ? $teamRatingsByEvent[$event->id] : null,
                'user_team_has_rated' => ($event->event_type === 'team vs team' && isset($teamRatingsByEvent[$event->id]) && !empty($teamRatingsByEvent[$event->id])) ? true : false,
                'is_team_rating_pending' => ($event->event_type === 'team vs team' && $isPast && (!isset($teamRatingsByEvent[$event->id]) || empty($teamRatingsByEvent[$event->id]))) ? true : false,
                'user_team_ids' => ($event->event_type === 'team vs team') ? $userTeamIds : [],
            ];
        })
        ->sortBy(function($event) {
            // Sorting logic: priority ASC, then by priority_24h for upcoming, then date/time
            $priority = $event['sort_priority'] ?? $event['priority'] ?? 5;
            $priority24h = $event['priority_24h'] ?? 1;
            $date = $event['date'];
            $startTime = $event['start_time'] ?? '00:00:00';
            
            // For cancelled and past games (priority 4-5), sort by date DESC (most recent at bottom/top)
            if ($priority === 4 || $priority === 5) {
                return [
                    $priority,
                    -strtotime($date . ' ' . $startTime), // Negative for descending
                ];
            }
            
            // For upcoming with 24h priority: sort by priority_24h, then date ASC, then time ASC
            if ($priority === 0 || $priority === 3) {
                return [
                    $priority,
                    $priority24h, // 0 = <24h, 1 = >=24h
                    $date,
                    $startTime
                ];
            }
            
            // For today and tomorrow: sort by time ASC
            return [
                $priority,
                $date,
                $startTime
            ];
        })
        ->values();
        
        return $processed;
    }

    public function myGames()
    {
        $user = auth()->user();
        $today = Carbon::today()->format('Y-m-d');
        $tomorrow = Carbon::tomorrow()->format('Y-m-d');
        $now = Carbon::now();

        // Base query condition for events where user is a participant or creator
        $baseCondition = function($q) use ($user) {
            $q->where('created_by', $user->id)
              ->orWhere(function($inner) use ($user) {
                  $inner->where('is_approved', true)
                        ->whereHas('participants', function($q2) use ($user) {
                            $q2->where('user_id', $user->id);
                        });
              });
        };

        // Get regular events (non-tournament)
        $regularEvents = Event::with(['venue', 'facility', 'participants', 'checkins'])
            ->where($baseCondition)
            ->where(function($q) {
                $q->whereNull('is_tournament_game')
                  ->orWhere('is_tournament_game', false);
            })
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        // Get tournament events
        $tournamentEvents = Event::with(['venue', 'facility', 'participants', 'checkins'])
            ->where($baseCondition)
            ->where('is_tournament_game', true)
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        // Optimize: Get all event IDs and fetch ratings in one query to avoid N+1
        $allEventIds = $regularEvents->pluck('id')->merge($tournamentEvents->pluck('id'))->toArray();
        $userRatingsByEvent = PlayerRating::whereIn('event_id', $allEventIds)
            ->where('rater_user_id', $user->id)
            ->whereNotNull('created_at')
            ->pluck('event_id')
            ->unique()
            ->toArray();

        // Get user's active teams for team rating checks
        $userTeamIds = TeamMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->where('roster_status', 'active')
            ->pluck('team_id')
            ->toArray();

        // Get team ratings for team vs team events
        $teamRatingsByEvent = [];
        if (!empty($userTeamIds)) {
            $teamRatings = TeamRating::whereIn('event_id', $allEventIds)
                ->whereIn('rater_team_id', $userTeamIds)
                ->get()
                ->groupBy('event_id');
            
            foreach ($teamRatings as $eventId => $ratings) {
                $teamRatingsByEvent[$eventId] = $ratings->map(function($rating) {
                    return [
                        'rater_team_id' => $rating->rater_team_id,
                        'rated_team_id' => $rating->rated_team_id,
                        'rating' => $rating->rating,
                        'comment' => $rating->comment,
                    ];
                })->toArray();
            }
        }

        // Process both groups using the helper method
        $processedRegularEvents = $this->processEventGroup($regularEvents, $user, $today, $tomorrow, $now, $userRatingsByEvent, $teamRatingsByEvent, $userTeamIds);
        $processedTournamentEvents = $this->processEventGroup($tournamentEvents, $user, $today, $tomorrow, $now, $userRatingsByEvent, $teamRatingsByEvent, $userTeamIds);

        // Helper function to categorize events
        $categorizeEvents = function($events) {
            $todayGames = $events->where('is_today', true)->values();
            $tomorrowGames = $events->where('is_tomorrow', true)->values();
            // Upcoming includes both <24h (priority 0) and >=24h (priority 3)
            $upcomingGames = $events->filter(function($event) {
                $priority = $event['sort_priority'] ?? $event['priority'] ?? 5;
                return $priority === 0 || $priority === 3;
            })->values();
            // Upcoming within 24h are priority 0
            $upcoming24hGames = $events->where('is_within_24h', true)->values();
            $pastGames = $events->where('is_past', true)->values();
            $cancelledGames = $events->where('sort_priority', 5)->values();

            return [
                'games' => $events,
                'today' => $todayGames,
                'tomorrow' => $tomorrowGames,
                'upcoming' => $upcomingGames,
                'upcoming_24h' => $upcoming24hGames,
                'past' => $pastGames,
                'cancelled' => $cancelledGames,
                'summary' => [
                    'total' => $events->count(),
                    'today_count' => $todayGames->count(),
                    'tomorrow_count' => $tomorrowGames->count(),
                    'upcoming_count' => $upcomingGames->count(),
                    'upcoming_24h_count' => $upcoming24hGames->count(),
                    'past_count' => $pastGames->count(),
                    'cancelled_count' => $cancelledGames->count(),
                ]
            ];
        };

        $eventsCategory = $categorizeEvents($processedRegularEvents);
        $tournamentsCategory = $categorizeEvents($processedTournamentEvents);

        // Combine events and tournaments for unified rendering
        $allGames = $processedRegularEvents->merge($processedTournamentEvents);
        $allGamesCategory = $categorizeEvents($allGames);

        // Get tournament schedules for participants
        $tournamentSchedules = [];
        if (!empty($tournamentEvents)) {
            $tournamentEventIds = $tournamentEvents->pluck('id')->toArray();
            $tournamentIds = $tournamentEvents->pluck('tournament_id')->filter()->unique()->toArray();

            if (!empty($tournamentIds)) {
                // Get EventGame records for tournaments where user is a participant
                $eventGames = EventGame::whereIn('tournament_id', $tournamentIds)
                    ->whereIn('event_id', $tournamentEventIds)
                    ->with(['event', 'team_a', 'team_b', 'user_a', 'user_b'])
                    ->orderBy('game_date')
                    ->orderBy('start_time')
                    ->get();

                // Group by tournament and event
                $schedulesByTournament = [];
                foreach ($eventGames as $game) {
                    $tournamentId = $game->tournament_id;
                    $eventId = $game->event_id;

                    if (!isset($schedulesByTournament[$tournamentId])) {
                        $schedulesByTournament[$tournamentId] = [];
                    }
                    if (!isset($schedulesByTournament[$tournamentId][$eventId])) {
                        $schedulesByTournament[$tournamentId][$eventId] = [];
                    }

                    $schedulesByTournament[$tournamentId][$eventId][] = [
                        'id' => $game->id,
                        'round_number' => $game->round_number,
                        'match_number' => $game->match_number,
                        'match_stage' => $game->match_stage,
                        'team_a' => $game->team_a ? [
                            'id' => $game->team_a->id,
                            'name' => $game->team_a->name,
                        ] : null,
                        'team_b' => $game->team_b ? [
                            'id' => $game->team_b->id,
                            'name' => $game->team_b->name,
                        ] : null,
                        'user_a' => $game->user_a ? [
                            'id' => $game->user_a->id,
                            'username' => $game->user_a->username,
                        ] : null,
                        'user_b' => $game->user_b ? [
                            'id' => $game->user_b->id,
                            'username' => $game->user_b->username,
                        ] : null,
                        'score_a' => $game->score_a,
                        'score_b' => $game->score_b,
                        'winner_team_id' => $game->winner_team_id,
                        'winner_user_id' => $game->winner_user_id,
                        'game_date' => $game->game_date,
                        'start_time' => $game->start_time,
                        'end_time' => $game->end_time,
                        'status' => $game->status,
                    ];
                }

                // Format for response
                foreach ($schedulesByTournament as $tournamentId => $events) {
                    foreach ($events as $eventId => $games) {
                        $tournamentSchedules[] = [
                            'tournament_id' => $tournamentId,
                            'event_id' => $eventId,
                            'games' => $games,
                            'total_matches' => count($games),
                        ];
                    }
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'events' => $eventsCategory,
            'tournaments' => $tournamentsCategory,
            'games' => $allGamesCategory['games'], // Combined games array for unified rendering
            'today' => $allGamesCategory['today'],
            'tomorrow' => $allGamesCategory['tomorrow'],
            'upcoming' => $allGamesCategory['upcoming'],
            'upcoming_24h' => $allGamesCategory['upcoming_24h'],
            'past' => $allGamesCategory['past'],
            'cancelled' => $allGamesCategory['cancelled'],
            'summary' => [
                'total' => $allGamesCategory['summary']['total'],
                'events_count' => $eventsCategory['summary']['total'],
                'tournaments_count' => $tournamentsCategory['summary']['total'],
                'today_count' => $allGamesCategory['summary']['today_count'],
                'tomorrow_count' => $allGamesCategory['summary']['tomorrow_count'],
                'upcoming_count' => $allGamesCategory['summary']['upcoming_count'],
                'upcoming_24h_count' => $allGamesCategory['summary']['upcoming_24h_count'],
                'past_count' => $allGamesCategory['summary']['past_count'],
                'cancelled_count' => $allGamesCategory['summary']['cancelled_count'],
            ],
            'tournament_schedules' => $tournamentSchedules,
        ]);
    }

    public function joinEvent(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
            'team_id' => 'nullable|exists:teams,id', // allow optional team
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        if ($approvalResponse = $this->approvalRequiredResponse($event)) {
            return $approvalResponse;
        }

        if ($approvalResponse = $this->approvalRequiredResponse($event)) {
            return $approvalResponse;
        }

        if (! $this->canViewEvent($event, $userId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event is pending venue approval'
            ], 403);
        }

        if ($approvalResponse = $this->approvalRequiredResponse($event)) {
            return $approvalResponse;
        }

        if ($event->created_by == $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot join your own event.'
            ], 403);
        }

        // Check if this is a team vs team event
        if ($event->event_type === 'team vs team') {
            return response()->json([
                'status' => 'error',
                'message' => 'This is a team vs team event. Please join with your team using the team join endpoint.'
            ], 400);
        }

        $alreadyJoined = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->exists();

        if ($alreadyJoined) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already joined this event.'
            ], 409);
        }

        // Check if event is full (for free for all events)
        if ($event->event_type === 'free for all') {
            $currentParticipants = EventParticipant::where('event_id', $event->id)->count();
            if ($currentParticipants >= $event->slots) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This event is full. No more participants can join.'
                ], 409);
            }
        }

        // Check for conflicting events
        $conflict = Event::whereHas('participants', function($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->where('date', $event->date)
            ->where('game_status', '!=', 'cancelled')
            ->where(function($q) use ($event) {
                $q->whereBetween('start_time', [$event->start_time, $event->end_time])
                  ->orWhereBetween('end_time', [$event->start_time, $event->end_time])
                  ->orWhere(function($q2) use ($event) {
                      $q2->where('start_time', '<=', $event->start_time)
                         ->where('end_time', '>=', $event->end_time);
                  });
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already joined another event with a conflicting date and time.'
            ], 409);
        }

        // If team_id provided, ensure user is a member of that team
        $teamId = $request->input('team_id');
        if ($teamId) {
            $isMember = TeamMember::where('team_id', $teamId)
                ->where('user_id', $userId)
                ->exists();

            if (! $isMember) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are not a member of the specified team.'
                ], 403);
            }
        }

        $participant = EventParticipant::create([
            'event_id' => $event->id,
            'user_id' => $userId,
            'team_id' => $teamId, // set nullable team id
            'status' => 'confirmed',
        ]);

        // send notif to event creator
        $creatorid = $event->created_by;

        $notification = Notification::create([
            'type' => 'event_joined',
            'data' => [
                'message' => auth()->user()->username . ' has joined your event: ' . $event->name,
                'event_id' => $event->id,
                'user_id' => $userId,
            ],
            'created_by' => $userId,
        ]);

        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $creatorid,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);

        // Auto-join participant to event group chat if it exists
        $this->addParticipantToEventChat($event->id, $userId);

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully joined the event.',
            'participant' => $participant
        ], 201);
    }

    public function joinEventAsTeam(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
            'team_id' => 'required|exists:teams,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);
        $teamId = $request->team_id;

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        if ($approvalResponse = $this->approvalRequiredResponse($event)) {
            return $approvalResponse;
        }

        if ($approvalResponse = $this->approvalRequiredResponse($event)) {
            return $approvalResponse;
        }

        // Check if event is team vs team type
        if ($event->event_type !== 'team vs team') {
            return response()->json([
                'status' => 'error',
                'message' => 'This event is not a team vs team event.'
            ], 400);
        }

        // Check if user is a member of the specified team
        $isTeamMember = TeamMember::where('team_id', $teamId)
            ->where('user_id', $userId)
            ->exists();

        if (!$isTeamMember) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not a member of the specified team.'
            ], 403);
        }

        // Check if team has already joined this event
        $teamAlreadyJoined = EventTeam::where('event_id', $event->id)
            ->where('team_id', $teamId)
            ->exists();

        if ($teamAlreadyJoined) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your team has already joined this event.'
            ], 409);
        }

        // Check if event has available slots (for teams)
        $currentTeamCount = EventTeam::where('event_id', $event->id)->count();
        if ($currentTeamCount >= $event->slots) {
            return response()->json([
                'status' => 'error',
                'message' => 'This event is full. No more teams can join.'
            ], 409);
        }

        // Check for conflicting events for all team members
        $teamMembers = TeamMember::where('team_id', $teamId)->pluck('user_id');
        $conflict = Event::whereHas('participants', function($q) use ($teamMembers) {
                $q->whereIn('user_id', $teamMembers);
            })
            ->where('date', $event->date)
            ->where(function($q) use ($event) {
                $q->whereBetween('start_time', [$event->start_time, $event->end_time])
                  ->orWhereBetween('end_time', [$event->start_time, $event->end_time])
                  ->orWhere(function($q2) use ($event) {
                      $q2->where('start_time', '<=', $event->start_time)
                         ->where('end_time', '>=', $event->end_time);
                  });
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'status' => 'error',
                'message' => 'One or more team members have conflicting events at this time.'
            ], 409);
        }

        // Create EventTeam record
        $eventTeam = EventTeam::create([
            'event_id' => $event->id,
            'team_id' => $teamId,
        ]);

        // Auto-enroll all team members as participants
        $enrolledParticipants = [];
        foreach ($teamMembers as $memberId) {
            $participant = EventParticipant::create([
                'event_id' => $event->id,
                'user_id' => $memberId,
                'team_id' => $teamId,
                'status' => 'confirmed',
            ]);
            $enrolledParticipants[] = $participant;
        }

        // Send notification to event creator
        $creatorId = $event->created_by;
        $team = \App\Models\Team::find($teamId);

        $notification = Notification::create([
            'type' => 'team_joined_event',
            'data' => [
                'message' => 'Team ' . $team->name . ' has joined your scrimmage: ' . $event->name,
                'event_id' => $event->id,
                'team_id' => $teamId,
            ],
            'created_by' => $userId,
        ]);

        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $creatorId,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);

        // Auto-join all team members to event group chat
        foreach ($enrolledParticipants as $participant) {
            $this->addParticipantToEventChat($event->id, $participant->user_id);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Team successfully joined the scrimmage.',
            'event_team' => $eventTeam,
            'enrolled_participants' => $enrolledParticipants
        ], 201);
    }

    public function inviteTeamToEvent(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
            'team_id' => 'required|exists:teams,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);
        $teamId = $request->team_id;

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        // Check if event is team vs team type
        if ($event->event_type !== 'team vs team') {
            return response()->json([
                'status' => 'error',
                'message' => 'This event is not a team vs team event.'
            ], 400);
        }

        // Check if user is the event creator or a team captain
        $isEventCreator = $event->created_by == $userId;
        $isTeamCaptain = TeamMember::where('team_id', $teamId)
            ->where('user_id', $userId)
            ->where('role', 'captain')
            ->exists();

        if (!$isEventCreator && !$isTeamCaptain) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only invite teams if you are the event creator or a team captain.'
            ], 403);
        }

        // Check if team has already joined this event
        $teamAlreadyJoined = EventTeam::where('event_id', $event->id)
            ->where('team_id', $teamId)
            ->exists();

        if ($teamAlreadyJoined) {
            return response()->json([
                'status' => 'error',
                'message' => 'This team has already joined the event.'
            ], 409);
        }

        // Check if event has available slots
        $currentTeamCount = EventTeam::where('event_id', $event->id)->count();
        if ($currentTeamCount >= $event->slots) {
            return response()->json([
                'status' => 'error',
                'message' => 'This event is full. No more teams can join.'
            ], 409);
        }

        // Get team captain for notification
        $teamCaptain = TeamMember::where('team_id', $teamId)
            ->where('role', 'captain')
            ->with('user')
            ->first();

        if (!$teamCaptain) {
            return response()->json([
                'status' => 'error',
                'message' => 'Team captain not found.'
            ], 404);
        }

        // Send invitation notification to team captain
        $notification = Notification::create([
            'type' => 'team_invitation',
            'data' => [
                'message' => 'You have been invited to join the scrimmage: ' . $event->name,
                'event_id' => $event->id,
                'team_id' => $teamId,
                'invited_by' => $userId,
            ],
            'created_by' => $userId,
        ]);

        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $teamCaptain->user_id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'pending',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Team invitation sent successfully.',
            'invitation' => [
                'event_id' => $event->id,
                'team_id' => $teamId,
                'invited_to' => $teamCaptain->user->username,
            ]
        ], 201);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sport' => 'required|exists:sports,name',  // ✅ Validate that sport name exists in sports table
            'event_type' => 'required|in:free for all,team vs team,tournament,multisport',
            'venue_id' => 'required|exists:venues,id',
            'facility_id' => 'required|exists:facilities,id',
            'slots' => 'required|integer|min:1',
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'team_ids' => 'required_if:event_type,team vs team|array|min:1',
            'team_ids.*' => 'exists:teams,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Additional validation for team vs team events
        if ($request->event_type === 'team vs team') {
            $user = auth()->user();
            $teamIds = $request->team_ids;
            
            // Check if user is a member of at least one specified team
            $isMemberOfAnyTeam = TeamMember::where('user_id', $user->id)
                ->whereIn('team_id', $teamIds)
                ->exists();
                
            if (!$isMemberOfAnyTeam) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You must be a member of at least one of the specified teams to create a team vs team event.'
                ], 403);
            }
        }

        // Block creation if venue is closed
        $venue = \App\Models\Venue::find($request->venue_id);
        if ($venue && $venue->is_closed) {
            return response()->json([
                'status' => 'error',
                'message' => 'This venue is closed and not accepting new events.'
            ], 403);
        }

        // Block creation if facility is closed
        $facility = \App\Models\Facilities::find($request->facility_id);
        if ($facility && $facility->is_closed) {
            return response()->json([
                'status' => 'error',
                'message' => 'This facility is closed and not accepting new bookings.'
            ], 403);
        }

        // Prevent double booking: same venue + facility, same date, overlapping times
        $conflict = Event::where('venue_id', $request->venue_id)
            ->where('facility_id', $request->facility_id)
            ->where('date', $request->date)
            ->where('game_status', '!=', 'scheduled')
            // overlap check: existing.start < new.end AND existing.end > new.start
            ->where(function($q) use ($request) {
                $q->where('start_time', '<', $request->end_time)
                ->where('end_time', '>', $request->start_time)
                ->where('game_status', '!=', 'cancelled');
            })
            ->exists();
            // $conflict dont check status if cancel

        if ($conflict) {
            return response()->json([
                'status' => 'error',
                'message' => 'Venue and facility are already booked for the selected date and time.'
            ], 409);
        }

        $user = auth()->user();

        $event = Event::create([
            'name' => $request->name,
            'description' => $request->description,
            'event_type' => $request->event_type,
            'sport' => $request->sport,  // ✅ Use sport name from request
            'venue_id' => $request->venue_id,
            'facility_id' => $request->facility_id,
            'slots' => $request->slots,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'created_by' => $user->id,
            'checkin_code' => $this->generateCheckinCode(),
            'share_token' => $this->generateShareToken(),
            'is_approved' => false,
            'approved_at' => null,
        ]);

        // Create booking for venue approval
        $booking = Booking::create([
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
            
            // Create EventTeam records and auto-enroll all team members
            foreach ($teamIds as $teamId) {
                // Create EventTeam record
                EventTeam::create([
                    'event_id' => $event->id,
                    'team_id' => $teamId,
                ]);
                
                // Get all active team members and auto-enroll them
                $teamMembers = TeamMember::where('team_id', $teamId)
                    ->where('is_active', true)
                    ->where('roster_status', 'active')
                    ->get();
                foreach ($teamMembers as $member) {
                    $participant = EventParticipant::create([
                        'event_id' => $event->id,
                        'user_id' => $member->user_id,
                        'team_id' => $teamId,
                        'status' => 'confirmed',
                    ]);
                    $enrolledParticipants[] = $participant;
                }
            }
            
            // Auto-create group chat for event
            $this->createEventGroupChat($event);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Team vs team event created successfully',
                'event' => $event->fresh(),
                'booking' => $booking,
                'teams' => $teamIds,
                'enrolled_participants' => $enrolledParticipants,
                'approval_status' => 'pending',
            ], 201);
        } else {
            // Handle free for all events (existing logic)
            $participant = EventParticipant::create([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'status' => 'confirmed',
            ]);

            // Auto-create group chat for event
            $this->createEventGroupChat($event);

            return response()->json([
                'status' => 'success',
                'message' => 'Event created successfully',
                'event' => $event->fresh(),
                'booking' => $booking,
                'creator_participant' => $participant,
                'approval_status' => 'pending',
            ], 201);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $event = Event::with(['venue.photos', 'facility', 'teams.team', 'participants.user', 'checkins.user'])
            ->withCount('participants')
            ->find($id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found'
            ], 404);
        }

        $userId = auth()->id();
        if (! $this->canViewEvent($event, $userId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event is pending venue approval'
            ], 403);
        }

        // Calculate hours and costs
        $start = Carbon::parse($event->start_time);
        $end = Carbon::parse($event->end_time);
        $hours = $start->diffInMinutes($end) / 60;
        $pricePerHour = $event->facility ? ($event->facility->price_per_hr ?? 0) : 0;
        $totalCost = $hours * $pricePerHour;
        $costPerPerson = $event->participants_count > 0 ? round($totalCost / $event->participants_count, 2) : 0;
        
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
            'event_type' => $event->event_type,
            'sport' => $event->sport,
            'status' => $event->status,
            'date' => $event->date,
            'start_time' => $event->start_time,
            'end_time' => $event->end_time,
            'slots' => $event->slots,
            'participants_count' => $event->participants_count,
            'checkin_code' => $event->checkin_code,
            'cancelled_at' => $event->cancelled_at,
            'is_approved' => (bool) $event->is_approved,
            'approval_status' => $event->is_approved ? 'approved' : 'pending',
            'approved_at' => $event->approved_at,
            'venue' => $event->venue ? [
                'id' => $event->venue->id,
                'name' => $event->venue->name,
                'address' => $event->venue->address,
                'latitude' => $event->venue->latitude,
                'longitude' => $event->venue->longitude,
                'photo_url' => $venuePhotoUrl,
            ] : null,
            'facility' => $event->facility ? [
                'id' => $event->facility->id,
                'type' => $event->facility->type,
                'price_per_hr' => $event->facility->price_per_hr,
            ] : null,
            'creator' => $event->creator ? [
                'id' => $event->creator->id,
                'username' => $event->creator->username,
            ] : null,
            'costs' => [
                'total_cost' => $totalCost,
                'cost_per_person' => $costPerPerson,
                'hours' => $hours,
            ],
        ];

        // Add team-specific information for team vs team events
        if ($event->event_type === 'team vs team') {
            $eventData['teams'] = $event->teams->map(function($eventTeam) {
                return [
                    'team_id' => $eventTeam->team_id,
                    'team_name' => $eventTeam->team->name ?? 'Unknown Team',
                    'group' => $eventTeam->group,
                ];
            });
        }

        // Add participants with check-in status
        $eventData['participants'] = $event->participants->map(function($participant) {
            $checkin = EventCheckin::where('event_id', $participant->event_id)
                ->where('user_id', $participant->user_id)
                ->first();
            return [
                'user_id' => $participant->user_id,
                'username' => $participant->user->username,
                'profile_photo' => $participant->user->profile_photo ? Storage::url($participant->user->profile_photo) : null,
                'status' => $participant->status,
                'checked_in' => $checkin ? true : false,
                'checkin_time' => $checkin ? $checkin->checkin_time : null,
                'team_id' => $participant->team_id,
                ];
            });

        return response()->json([
            'status' => 'success',
            'event' => $eventData
        ]);
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
        $event = Event::find($id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found'
            ], 404);
        }

        // Check if user is the event creator
        if ($event->created_by !== auth()->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only update events you created'
            ], 403);
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot update a cancelled event'
            ], 400);
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'venue_id' => 'sometimes|required|exists:venues,id',
            'facility_id' => 'sometimes|required|exists:facilities,id',
            'slots' => 'sometimes|required|integer|min:1',
            'date' => 'sometimes|required|date',
            'start_time' => 'sometimes|required|date_format:H:i:s',
            'end_time' => 'sometimes|required|date_format:H:i:s|after:start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check for double booking if venue/facility/date/time changed
        if ($request->hasAny(['venue_id', 'facility_id', 'date', 'start_time', 'end_time'])) {
            $venueId = $request->venue_id ?? $event->venue_id;
            $facilityId = $request->facility_id ?? $event->facility_id;
            $date = $request->date ?? $event->date;
            $startTime = $request->start_time ?? $event->start_time;
            $endTime = $request->end_time ?? $event->end_time;

            $conflict = Event::where('venue_id', $venueId)
                ->where('facility_id', $facilityId)
                ->where('date', $date)
                ->where('id', '!=', $event->id) // Exclude current event
                ->where(function($q) use ($startTime, $endTime) {
                    $q->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
                })
                ->exists();

            if ($conflict) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Venue and facility are already booked for the selected date and time.'
                ], 409);
            }
        }

        // Update event
        $event->update($request->only([
            'name', 'description', 'venue_id', 'facility_id', 
            'slots', 'date', 'start_time', 'end_time'
        ]));

        // Send notification to all participants about the update
        $participants = EventParticipant::where('event_id', $event->id)->get();
        foreach ($participants as $participant) {
            if ($participant->user_id !== $event->created_by) {
                $notification = Notification::create([
                    'type' => 'event_updated',
                    'data' => [
                        'message' => 'Event "' . $event->name . '" has been updated',
                        'event_id' => $event->id,
                    ],
                    'created_by' => $event->created_by,
                ]);

                UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $participant->user_id,
                    'pinned' => false,
                    'is_read' => false,
                    'action_state' => 'none',
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Event updated successfully',
            'event' => $event->fresh(['venue', 'facility'])
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $event = Event::find($id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found'
            ], 404);
        }

        // Check if user is the event creator
        if ($event->created_by !== auth()->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only cancel events you created'
            ], 403);
        }

        // Check if event is already cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event is already cancelled'
            ], 400);
        }

        // Prevent cancellation on the day of the booking
        $eventDate = Carbon::parse($event->date)->format('Y-m-d');
        $today = Carbon::today()->format('Y-m-d');
        if ($eventDate === $today) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot cancel events on the day of the booking'
            ], 400);
        }

        // Cancel the event (soft delete by setting cancelled_at)
        $event->update(['cancelled_at' => now()]);

        // Load event relationships for notifications
        $event->load(['venue', 'facility']);

        // Send notification to all participants about the cancellation
        $participants = EventParticipant::where('event_id', $event->id)
            ->with('user')
            ->get();
        
        foreach ($participants as $participant) {
            if ($participant->user_id !== $event->created_by) {
                // Create in-app notification
                $notification = Notification::create([
                    'type' => 'event_cancelled',
                    'data' => [
                        'message' => 'Event "' . $event->name . '" has been cancelled',
                        'event_id' => $event->id,
                    ],
                    'created_by' => $event->created_by,
                ]);

                // Create user notification (triggers push notification via observer)
                UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $participant->user_id,
                    'pinned' => false,
                    'is_read' => false,
                    'action_state' => 'none',
                ]);

                // Send email notification
                if ($participant->user && $participant->user->email) {
                    try {
                        Mail::to($participant->user->email)->send(new EventCancelledMail($event, $participant->user));
                    } catch (\Exception $e) {
                        // Log error but don't break the cancellation process
                        \Illuminate\Support\Facades\Log::error('Failed to send event cancellation email', [
                            'user_id' => $participant->user_id,
                            'event_id' => $event->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Event cancelled successfully',
            'event' => $event->fresh()
        ]);
    }

    /**
     * Leave an event (for participants)
     */
    public function leaveEvent(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot leave a cancelled event.'
            ], 400);
        }

        // Check if user is the event creator
        if ($event->created_by == $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot leave your own event. Cancel it instead.'
            ], 403);
        }

        // Find participant record
        $participant = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not a participant in this event.'
            ], 404);
        }

        // For team vs team events, check if user is part of a team
        if ($event->event_type === 'team vs team' && $participant->team_id) {
            // Check if this is the last member of the team in this event
            $teamMembersInEvent = EventParticipant::where('event_id', $event->id)
                ->where('team_id', $participant->team_id)
                ->count();

            if ($teamMembersInEvent === 1) {
                // Remove the entire team from the event
                EventTeam::where('event_id', $event->id)
                    ->where('team_id', $participant->team_id)
                    ->delete();
            }
        }

        // Remove participant
        $participant->delete();

        // Send notification to event creator
        $notification = Notification::create([
            'type' => 'event_left',
            'data' => [
                'message' => auth()->user()->username . ' has left your event: ' . $event->name,
                'event_id' => $event->id,
                'user_id' => $userId,
            ],
            'created_by' => $userId,
        ]);

        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $event->created_by,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully left the event.'
        ]);
    }

    /**
     * Remove a participant from an event (for event creators)
     */
    public function removeParticipant(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $creatorId = auth()->user()->id;
        $event = Event::find($request->event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        // Check if user is the event creator
        if ($event->created_by !== $creatorId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only remove participants from events you created.'
            ], 403);
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot remove participants from a cancelled event.'
            ], 400);
        }

        // Check if trying to remove the creator
        if ($request->user_id == $creatorId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot remove yourself from your own event.'
            ], 400);
        }

        // Find participant record
        $participant = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$participant) {
            return response()->json([
                'status' => 'error',
                'message' => 'User is not a participant in this event.'
            ], 404);
        }

        // For team vs team events, handle team removal
        if ($event->event_type === 'team vs team' && $participant->team_id) {
            // Remove all team members from the event
            EventParticipant::where('event_id', $event->id)
                ->where('team_id', $participant->team_id)
                ->delete();

            // Remove team from event
            EventTeam::where('event_id', $event->id)
                ->where('team_id', $participant->team_id)
                ->delete();

            $team = \App\Models\Team::find($participant->team_id);
            $message = 'Team "' . $team->name . '" has been removed from your event: ' . $event->name;
        } else {
            // Remove individual participant
            $participant->delete();
            $user = User::find($request->user_id);
            $message = 'User "' . $user->username . '" has been removed from your event: ' . $event->name;
        }

        // Send notification to removed user(s)
        $removedUserIds = $event->event_type === 'team vs team' && $participant->team_id
            ? \App\Models\TeamMember::where('team_id', $participant->team_id)->pluck('user_id')
            : collect([$request->user_id]);

        foreach ($removedUserIds as $userId) {
            $notification = Notification::create([
                'type' => 'removed_from_event',
                'data' => [
                    'message' => 'You have been removed from event: ' . $event->name,
                    'event_id' => $event->id,
                ],
                'created_by' => $creatorId,
            ]);

            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Participant removed successfully.'
        ]);
    }

    /**
     * Check-in to an event using QR code
     */
    public function checkinQR(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'qr_data' => 'required|string', // QR code data from scan
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        
        // Parse QR code data
        $qrData = json_decode($request->qr_data, true);
        $eventId = $qrData['event_id'] ?? null;
        
        if (!$eventId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid QR code data.'
            ], 400);
        }

        $event = Event::find($eventId);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        if ($approvalResponse = $this->approvalRequiredResponse($event)) {
            return $approvalResponse;
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot check-in to a cancelled event.'
            ], 400);
        }

        // Check if user is a participant
        $participant = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not a participant in this event.'
            ], 403);
        }

        // Check if already checked in
        $existingCheckin = EventCheckin::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->first();

        if ($existingCheckin) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already checked in to this event.'
            ], 409);
        }

        // Create check-in record
        $checkin = EventCheckin::create([
            'event_id' => $event->id,
            'user_id' => $userId,
            'checked_in_by' => $userId,
            'checkin_type' => 'qr_self',
            'checkin_time' => now(),
        ]);

        // Update participant status
        $participant->update(['status' => 'checked_in']);

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully checked in to the event.',
            'checkin' => $checkin,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'venue_name' => $event->venue->name,
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
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        if ($approvalResponse = $this->approvalRequiredResponse($event)) {
            return $approvalResponse;
        }

        // Verify check-in code
        if ($event->checkin_code !== $request->code) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid check-in code.'
            ], 400);
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot check-in to a cancelled event.'
            ], 400);
        }

        // Check if user is a participant
        $participant = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not a participant in this event.'
            ], 403);
        }

        // Check if already checked in
        $existingCheckin = EventCheckin::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->first();

        if ($existingCheckin) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already checked in to this event.'
            ], 409);
        }

        // Create check-in record
        $checkin = EventCheckin::create([
            'event_id' => $event->id,
            'user_id' => $userId,
            'checked_in_by' => $userId,
            'checkin_type' => 'code_entry',
            'checkin_time' => now(),
        ]);

        // Update participant status
        $participant->update(['status' => 'checked_in']);

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully checked in to the event.',
            'checkin' => $checkin
        ]);
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
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $organizerId = auth()->user()->id;
        $event = Event::find($request->event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        if ($approvalResponse = $this->approvalRequiredResponse($event)) {
            return $approvalResponse;
        }

        // Check if user is the event creator
        if ($event->created_by !== $organizerId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only check-in participants for events you created.'
            ], 403);
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot check-in participants for a cancelled event.'
            ], 400);
        }

        // Check if user is a participant
        $participant = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$participant) {
            return response()->json([
                'status' => 'error',
                'message' => 'User is not a participant in this event.'
            ], 404);
        }

        // Check if already checked in
        $existingCheckin = EventCheckin::where('event_id', $event->id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existingCheckin) {
            return response()->json([
                'status' => 'error',
                'message' => 'User has already checked in to this event.'
            ], 409);
        }

        // Create check-in record
        $checkin = EventCheckin::create([
            'event_id' => $event->id,
            'user_id' => $request->user_id,
            'checked_in_by' => $organizerId,
            'checkin_type' => 'manual_by_organizer',
            'checkin_time' => now(),
        ]);

        // Update participant status
        $participant->update(['status' => 'checked_in']);

        return response()->json([
            'status' => 'success',
            'message' => 'User successfully checked in to the event.',
            'checkin' => $checkin
        ]);
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
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        if (! $this->canViewEvent($event, $userId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event is pending venue approval'
            ], 403);
        }

        // Check if user is event creator or participant
        $isCreator = $event->created_by === $userId;
        $isParticipant = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->exists();

        if (!$isCreator && !$isParticipant) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only view check-ins for events you created or participate in.'
            ], 403);
        }

        // Get all participants with their check-in status
        $participants = EventParticipant::where('event_id', $event->id)
            ->with(['user', 'team'])
            ->get()
            ->map(function($participant) use ($event) {
                $checkin = EventCheckin::where('event_id', $event->id)
                    ->where('user_id', $participant->user_id)
                    ->first();

                return [
                    'user_id' => $participant->user_id,
                    'username' => $participant->user->username,
                    'profile_photo' => $participant->user->profile_photo ? Storage::url($participant->user->profile_photo) : null,
                    'status' => $participant->status,
                    'checked_in' => $checkin ? true : false,
                    'checkin_time' => $checkin ? $checkin->checkin_time : null,
                    'checkin_type' => $checkin ? $checkin->checkin_type : null,
                    'team_id' => $participant->team_id,
                    'team_name' => $participant->team ? $participant->team->name : null,
                ];
            });

        $checkedInCount = $participants->where('checked_in', true)->count();
        $totalParticipants = $participants->count();

        return response()->json([
            'status' => 'success',
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

    /**
     * Record score for a team in competitive events
     */
    public function recordScore(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
            'team_id' => 'required|exists:teams,id',
            'points' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        // Check if event is competitive (tournament or team vs team)
        if (!$event->isCompetitive()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Scoring is only available for tournament and team vs team events.'
            ], 400);
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot record scores for a cancelled event.'
            ], 400);
        }

        // Check if user is event creator or team member
        $isCreator = $event->created_by === $userId;
        $isTeamMember = \App\Models\TeamMember::where('team_id', $request->team_id)
            ->where('user_id', $userId)
            ->exists();

        if (!$isCreator && !$isTeamMember) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only record scores for events you created or teams you belong to.'
            ], 403);
        }

        // Check if team is participating in this event
        $teamInEvent = EventTeam::where('event_id', $event->id)
            ->where('team_id', $request->team_id)
            ->exists();

        if (!$teamInEvent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Team is not participating in this event.'
            ], 404);
        }

        // Create or update score record
        $score = EventScore::updateOrCreate(
            [
                'event_id' => $event->id,
                'team_id' => $request->team_id,
            ],
            [
                'points' => $request->points,
                'recorded_by' => $userId,
                'timestamp' => now(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Score recorded successfully.',
            'score' => $score
        ]);
    }

    /**
     * View scores/leaderboard for competitive events
     */
    public function viewScores(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        // Check if event is competitive
        if (!$event->isCompetitive()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Scores are only available for tournament and team vs team events.'
            ], 400);
        }

        // Check if user is event creator or participant
        $isCreator = $event->created_by === $userId;
        $isParticipant = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->exists();

        if (!$isCreator && !$isParticipant) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only view scores for events you created or participate in.'
            ], 403);
        }

        // Get scores with team information
        $scores = EventScore::where('event_id', $event->id)
            ->with(['team', 'recorder'])
            ->orderBy('points', 'desc')
            ->orderBy('timestamp', 'asc') // Earlier timestamp wins tiebreaker
            ->get()
            ->map(function($score) {
                return [
                    'team_id' => $score->team_id,
                    'team_name' => $score->team->name,
                    'points' => $score->points,
                    'recorded_by' => $score->recorder->username,
                    'recorded_at' => $score->timestamp,
                ];
            });

        // Get all teams in the event (including those without scores)
        $allTeams = EventTeam::where('event_id', $event->id)
            ->with('team')
            ->get()
            ->map(function($eventTeam) use ($scores) {
                $teamScore = $scores->where('team_id', $eventTeam->team_id)->first();
                return [
                    'team_id' => $eventTeam->team_id,
                    'team_name' => $eventTeam->team->name,
                    'points' => $teamScore ? $teamScore['points'] : 0,
                    'recorded_by' => $teamScore ? $teamScore['recorded_by'] : null,
                    'recorded_at' => $teamScore ? $teamScore['recorded_at'] : null,
                    'has_score' => $teamScore ? true : false,
                ];
            })
            ->sortByDesc('points')
            ->values();

        return response()->json([
            'status' => 'success',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'is_approved' => (bool) $event->is_approved,
                'approval_status' => $event->is_approved ? 'approved' : 'pending',
                'approved_at' => $event->approved_at,
            ],
            'leaderboard' => $allTeams,
            'total_teams' => $allTeams->count(),
            'teams_with_scores' => $scores->count(),
        ]);
    }

    /**
     * Update score for a team
     */
    public function updateScore(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
            'team_id' => 'required|exists:teams,id',
            'points' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $event = Event::find($request->event_id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        // Check if event is competitive
        if (!$event->isCompetitive()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Scoring is only available for tournament and team vs team events.'
            ], 400);
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot update scores for a cancelled event.'
            ], 400);
        }

        // Check if user is event creator or team member
        $isCreator = $event->created_by === $userId;
        $isTeamMember = \App\Models\TeamMember::where('team_id', $request->team_id)
            ->where('user_id', $userId)
            ->exists();

        if (!$isCreator && !$isTeamMember) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only update scores for events you created or teams you belong to.'
            ], 403);
        }

        // Find existing score
        $score = EventScore::where('event_id', $event->id)
            ->where('team_id', $request->team_id)
            ->first();

        if (!$score) {
            return response()->json([
                'status' => 'error',
                'message' => 'No score found for this team. Use record score endpoint first.'
            ], 404);
        }

        // Update score
        $score->update([
            'points' => $request->points,
            'recorded_by' => $userId,
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Score updated successfully.',
            'score' => $score->fresh()
        ]);
    }

    /**
     * Accept or decline team invitation
     */
    public function respondTeamInvitation(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'notification_id' => 'required|exists:notifications,id',
            'action' => 'required|in:accept,decline',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $notification = Notification::find($request->notification_id);

        if (!$notification) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notification not found.'
            ], 404);
        }

        // Check if notification is for team invitation
        if ($notification->type !== 'team_invitation') {
            return response()->json([
                'status' => 'error',
                'message' => 'This notification is not a team invitation.'
            ], 400);
        }

        // Check if user is the intended recipient
        $userNotification = UserNotification::where('notification_id', $notification->id)
            ->where('user_id', $userId)
            ->first();

        if (!$userNotification) {
            return response()->json([
                'status' => 'error',
                'message' => 'This invitation is not for you.'
            ], 403);
        }

        // Check if already responded
        if ($userNotification->action_state !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already responded to this invitation.'
            ], 400);
        }

        $eventId = $notification->data['event_id'] ?? null;
        $teamId = $notification->data['team_id'] ?? null;

        if (!$eventId || !$teamId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid invitation data.'
            ], 400);
        }

        $event = Event::find($eventId);
        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        if ($request->action === 'accept') {
            if ($approvalResponse = $this->approvalRequiredResponse($event)) {
                return $approvalResponse;
            }
        }

        // Check if user is team captain
        $isTeamCaptain = TeamMember::where('team_id', $teamId)
            ->where('user_id', $userId)
            ->where('role', 'captain')
            ->exists();

        if (!$isTeamCaptain) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only team captains can respond to team invitations.'
            ], 403);
        }

        // Update notification state
        $userNotification->action_state = $request->action;
        $userNotification->is_read = true;
        $userNotification->save();

        if ($request->action === 'accept') {
            // Check if team has already joined this event
            $teamAlreadyJoined = EventTeam::where('event_id', $eventId)
                ->where('team_id', $teamId)
                ->exists();

            if ($teamAlreadyJoined) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your team has already joined this event.'
                ], 409);
            }

            // Check if event has available slots
            $currentTeamCount = EventTeam::where('event_id', $eventId)->count();
            if ($currentTeamCount >= $event->slots) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This event is full. No more teams can join.'
                ], 409);
            }

            // Check for conflicting events for all team members
            $teamMembers = TeamMember::where('team_id', $teamId)->pluck('user_id');
            $conflict = Event::whereHas('participants', function($q) use ($teamMembers) {
                    $q->whereIn('user_id', $teamMembers);
                })
                ->where('date', $event->date)
                ->where(function($q) use ($event) {
                    $q->whereBetween('start_time', [$event->start_time, $event->end_time])
                      ->orWhereBetween('end_time', [$event->start_time, $event->end_time])
                      ->orWhere(function($q2) use ($event) {
                          $q2->where('start_time', '<=', $event->start_time)
                             ->where('end_time', '>=', $event->end_time);
                      });
                })
                ->exists();

            if ($conflict) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'One or more team members have conflicting events at this time.'
                ], 409);
            }

            // Create EventTeam record
            $eventTeam = EventTeam::create([
                'event_id' => $eventId,
                'team_id' => $teamId,
            ]);

            // Auto-enroll all team members as participants
            $enrolledParticipants = [];
            foreach ($teamMembers as $memberId) {
                $participant = EventParticipant::create([
                    'event_id' => $eventId,
                    'user_id' => $memberId,
                    'team_id' => $teamId,
                    'status' => 'confirmed',
                ]);
                $enrolledParticipants[] = $participant;
            }

            // Send notification to event creator
            $creatorId = $event->created_by;
            $team = \App\Models\Team::find($teamId);

            $acceptNotification = Notification::create([
                'type' => 'team_joined_event',
                'data' => [
                    'message' => 'Team ' . $team->name . ' has accepted your invitation and joined: ' . $event->name,
                    'event_id' => $eventId,
                    'team_id' => $teamId,
                ],
                'created_by' => $userId,
            ]);

            UserNotification::create([
                'notification_id' => $acceptNotification->id,
                'user_id' => $creatorId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);

            // Auto-join all team members to event group chat
            foreach ($enrolledParticipants as $participant) {
                $this->addParticipantToEventChat($eventId, $participant->user_id);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Team invitation accepted successfully.',
                'event_team' => $eventTeam,
                'enrolled_participants' => $enrolledParticipants
            ]);
        } else {
            // Decline invitation
            $team = \App\Models\Team::find($teamId);

            $declineNotification = Notification::create([
                'type' => 'team_invitation_declined',
                'data' => [
                    'message' => 'Team ' . $team->name . ' has declined your invitation to: ' . $event->name,
                    'event_id' => $eventId,
                    'team_id' => $teamId,
                ],
                'created_by' => $userId,
            ]);

            UserNotification::create([
                'notification_id' => $declineNotification->id,
                'user_id' => $event->created_by,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Team invitation declined.'
            ]);
        }
    }

    /**
     * Generate QR code for event check-in
     */
    public function generateQRCode($id)
    {
        $userId = auth()->user()->id;
        $event = Event::find($id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        // Check if user is event creator
        if ($event->created_by !== $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only generate QR codes for events you created.'
            ], 403);
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot generate QR code for a cancelled event.'
            ], 400);
        }

        // Generate QR code data
        $qrData = [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'checkin_code' => $event->checkin_code,
            'venue_name' => $event->venue->name,
            'date' => $event->date,
            'start_time' => $event->start_time,
            'timestamp' => now()->toISOString()
        ];

        // Create QR code
        $qrCode = new QrCode(json_encode($qrData));

        // Generate SVG
        $writer = new SvgWriter();
        $svgString = $writer->write($qrCode)->getString();

        return response()->json([
            'status' => 'success',
            'qr_code_svg' => $svgString,
            'qr_data' => $qrData,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'checkin_code' => $event->checkin_code,
                'date' => $event->date,
                'start_time' => $event->start_time,
                'venue_name' => $event->venue->name,
            ]
        ]);
    }

    /**
     * Generate QR code as PNG image (alternative format)
     */
    public function generateQRCodePNG($id)
    {
        $userId = auth()->user()->id;
        $event = Event::find($id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found.'
            ], 404);
        }

        // Check if user is event creator
        if ($event->created_by !== $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only generate QR codes for events you created.'
            ], 403);
        }

        // Check if event is cancelled
        if ($event->cancelled_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot generate QR code for a cancelled event.'
            ], 400);
        }

        // Generate QR code data
        $qrData = [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'checkin_code' => $event->checkin_code,
            'venue_name' => $event->venue->name,
            'date' => $event->date,
            'start_time' => $event->start_time,
            'timestamp' => now()->toISOString()
        ];

        // Create QR code
        $qrCode = new QrCode(json_encode($qrData));

        // Generate QR code as SVG (doesn't require GD extension)
        $writer = new \Endroid\QrCode\Writer\SvgWriter();
        $svgData = $writer->write($qrCode)->getString();

        // Return SVG data instead of PNG
        return response()->json([
            'status' => 'success',
            'qr_code_svg' => $svgData,
            'qr_data' => $qrData,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'checkin_code' => $event->checkin_code,
                'date' => $event->date,
                'start_time' => $event->start_time,
                'venue_name' => $event->venue->name,
            ]
        ]);
    }

    /**
     * Auto-create group chat for event and notify all participants
     */
    private function createEventGroupChat(Event $event)
    {
        // Check if thread already exists for this event
        $existingThread = MessageThread::where('type', 'game_group')
            ->where('game_id', $event->id)
            ->first();
        
        if ($existingThread) {
            return $existingThread; // Thread already exists
        }

        // Get all current participants (including creator)
        $participantIds = EventParticipant::where('event_id', $event->id)
            ->pluck('user_id')
            ->unique()
            ->all();
        
        // Include event creator if not already a participant
        if (!in_array($event->created_by, $participantIds)) {
            $participantIds[] = $event->created_by;
        }

        if (empty($participantIds)) {
            return null; // No participants yet
        }

        // Create group chat thread
        $thread = MessageThread::create([
            'created_by' => $event->created_by,
            'is_group' => true,
            'title' => $event->name,
            'type' => 'game_group',
            'game_id' => $event->id,
        ]);

        // Add all participants to thread
        $now = now();
        foreach ($participantIds as $userId) {
            ThreadParticipant::create([
                'thread_id' => $thread->id,
                'user_id' => $userId,
                'role' => $userId === $event->created_by ? 'owner' : 'member',
                'joined_at' => $now,
                'notifications' => true,
            ]);
        }

        // Send notification to all participants
        $notification = Notification::create([
            'type' => 'event_groupchat_created',
            'data' => [
                'message' => "Group chat created for event: {$event->name}",
                'event_id' => $event->id,
                'event_name' => $event->name,
                'thread_id' => $thread->id,
            ],
            'created_by' => $event->created_by,
        ]);

        // Notify all participants
        foreach ($participantIds as $userId) {
            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);
        }

        return $thread;
    }

    /**
     * Add participant to event group chat if it exists
     */
    private function addParticipantToEventChat($eventId, $userId)
    {
        // Find event group chat thread
        $thread = MessageThread::where('type', 'game_group')
            ->where('game_id', $eventId)
            ->first();

        if (!$thread) {
            return; // No group chat exists yet
        }

        // Check if user is already a participant
        $existingParticipant = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();

        if ($existingParticipant) {
            return; // Already in chat
        }

        // Add user to thread
        ThreadParticipant::create([
            'thread_id' => $thread->id,
            'user_id' => $userId,
            'role' => 'member',
            'joined_at' => now(),
            'notifications' => true,
        ]);

        // Notify user they were added to group chat
        $event = Event::find($eventId);
        if ($event) {
            $notification = Notification::create([
                'type' => 'event_groupchat_joined',
                'data' => [
                    'message' => "You've been added to the group chat for: {$event->name}",
                    'event_id' => $eventId,
                    'event_name' => $event->name,
                    'thread_id' => $thread->id,
                ],
                'created_by' => $event->created_by,
            ]);

            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);
        }
    }

    /**
     * Notify participants after event completion to rate the venue.
     * Call this when the event is marked completed (or when event end time passes in a scheduler).
     */
    private function notifyParticipantsToRate(Event $event)
    {
        // only notify once
        if ($event->is_rating_notified ?? false) {
            return;
        }

        $participants = EventParticipant::where('event_id', $event->id)->pluck('user_id')->unique()->filter(function($id) use ($event) {
            // ignore event creator
            return $id != $event->created_by;
        })->values();

        if ($participants->isEmpty()) {
            return;
        }

        $notification = Notification::create([
            'type' => 'rate_venue',
            'data' => [
                'message' => "Please rate the venue for your recent game: {$event->name}",
                'event_id' => $event->id,
                'venue_id' => $event->venue_id,
            ],
            'created_by' => $event->created_by,
        ]);

        foreach ($participants as $userId) {
            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'pending'
            ]);
        }

        // If Event model has an 'is_rating_notified' boolean column, set it to avoid duplicate notifications
        if (Schema::hasColumn('events', 'is_rating_notified')) {
            $event->update(['is_rating_notified' => true]);
        }
    }

    /**
     * Get shareable link for an event
     */
    public function getShareableLink(string $id)
    {
        $user = auth()->user();
        $event = Event::find($id);

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found'
            ], 404);
        }

        // Check if user is creator or participant
        $isCreator = $event->created_by === $user->id;
        $isParticipant = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isCreator && !$isParticipant) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to share this event'
            ], 403);
        }

        // Generate share token if it doesn't exist
        if (!$event->share_token) {
            $event->update(['share_token' => $this->generateShareToken()]);
            $event->refresh();
        }

        $baseUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        
        // Determine route based on event type
        if ($event->event_type === 'tune-up' || $event->event_type === 'tune up') {
            $shareUrl = "{$baseUrl}/games/tune-ups/{$event->share_token}";
        } else {
            $shareUrl = "{$baseUrl}/games/friendlygames/{$event->share_token}";
        }

        return response()->json([
            'status' => 'success',
            'share_token' => $event->share_token,
            'share_url' => $shareUrl,
        ], 200);
    }

    /**
     * Public view of event by share token (no authentication required)
     */
    public function viewByShareToken(string $token)
    {
        $event = Event::with(['venue.photos', 'facility', 'participants.user', 'teams.team'])
            ->where('share_token', $token)
            ->first();

        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event not found or invalid share link'
            ], 404);
        }

        // Return public event data (limited information)
        return response()->json([
            'status' => 'success',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'event_type' => $event->event_type,
                'sport' => $event->sport,
                'date' => $event->date,
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
                'slots' => $event->slots,
                'venue' => $event->venue ? [
                    'id' => $event->venue->id,
                    'name' => $event->venue->name,
                    'address' => $event->venue->address,
                    'photos' => $event->venue->photos->map(function($photo) {
                        return Storage::url($photo->image_path);
                    }),
                ] : null,
                'facility' => $event->facility ? [
                    'id' => $event->facility->id,
                    'type' => $event->facility->type,
                ] : null,
                'participants_count' => $event->participants->count(),
                'teams' => $event->teams->map(function($eventTeam) {
                    return [
                        'team_id' => $eventTeam->team_id,
                        'team_name' => $eventTeam->team->name ?? null,
                    ];
                }),
            ],
        ], 200);
    }
}
