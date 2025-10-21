<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Venue;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventCheckin;
use App\Models\EventScore;
use App\Models\EventTeam;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\TeamMember; // ADDED

class EventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $events = Event::with(['venue', 'facility', 'teams.team'])
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

                $divide = $totalCost / $event->participants_count;
                $dividedpay = round($divide, 2);

                $eventData = [
                    'id' => $event->id,
                    'name' => $event->name,
                    'description' => $event->description,
                    'date' => $event->date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'participants_count' => $event->participants_count,
                    'total_slots' => $event->slots,
                    'venue' => $event->venue->name,
                    'facility' => $event->facility->type ?? null,
                    'hours' => $hours,
                    'total_cost' => $totalCost,
                    'cost_per_slot' => $dividedpay,
                    'host' => User::find($event->created_by)->username,
                    'event_type' => $event->event_type,
                ];

                // Add team information for team vs team events
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
            });

        return response()->json([
            'status' => 'success',
            'events' => $events
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
                        return $member->user ? $member->user->username : 'Unknown User';
                    })
                ];
            });
        } else {
            $eventData['slots'] = $event->participants_count . '/' . $event->slots;
            $eventData['participants'] = [
                'name' => $participants->map(function($participant) {
                    return $participant->user ? $participant->user->username : 'Unknown User';
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
        $events = Event::with(['venue', 'facility'])
            ->where(function($q) use ($user) {
                $q->where('created_by', $user->id)
                ->orWhereHas('participants', function($q2) use ($user) {
                    $q2->where('user_id', $user->id);
                });
            })
            ->orderBy('date')
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
                    'end_time' => $event->end_time,
                ];
            });

        return response()->json([
            'status' => 'success',
            'schedule' => $events
        ]);
    }

    public function allusercreated()
    {
        $user = auth()->user();

        // Get all events where user is the creator only
        $events = Event::with(['venue', 'facility'])
            ->where('created_by', $user->id)
            ->orderBy('date')
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
                    'end_time' => $event->end_time,
                ];
            });

        return response()->json([
            'status' => 'success',
            'schedule' => $events
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

        $events = Event::with(['venue', 'facility'])
            ->where('venue_id', $request->venue_id)
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(function($event) {
                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'description' => $event->description,
                    'date' => $event->date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'host' => User::find($event->created_by)->username ?? null,
                    'venue' => $event->venue->name ?? null,
                    'facility' => $event->facility->type ?? null,
                    'slots' => $event->slots,
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
                  ->orWhereHas('participants', function($q2) use ($user) {
                      $q2->where('user_id', $user->id);
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
                ];
            });

        return response()->json([
            'status' => 'success',
            'schedule' => $events
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

        // Check for conflicting events
        $conflict = Event::whereHas('participants', function($q) use ($userId) {
                $q->where('user_id', $userId);
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

        // Prevent double booking: same venue + facility, same date, overlapping times
        $conflict = Event::where('venue_id', $request->venue_id)
            ->where('facility_id', $request->facility_id)
            ->where('date', $request->date)
            // overlap check: existing.start < new.end AND existing.end > new.start
            ->where(function($q) use ($request) {
                $q->where('start_time', '<', $request->end_time)
                ->where('end_time', '>', $request->start_time);
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'status' => 'error',
                'message' => 'Venue and facility are already booked for the selected date and time.'
            ], 409);
        }

        $user = auth()->user();
        $userProfile = $user->userProfile;

        if (!$userProfile) {
            return response()->json([
                'status' => 'error',
                'message' => 'User profile not found'
            ], 404);
        }

        // Get main sport name from user's profile
        $mainSport = \App\Models\Sport::find($userProfile->main_sport_id);
        if (!$mainSport) {
            return response()->json([
                'status' => 'error',
                'message' => 'User main sport not found'
            ], 404);
        }

        $event = Event::create([
            'name' => $request->name,
            'description' => $request->description,
            'event_type' => $request->event_type,
            'sport' => $mainSport->name,
            'venue_id' => $request->venue_id,
            'facility_id' => $request->facility_id,
            'slots' => $request->slots,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'created_by' => $user->id,
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
                
                // Get all team members and auto-enroll them
                $teamMembers = TeamMember::where('team_id', $teamId)->get();
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
            
            return response()->json([
                'status' => 'success',
                'message' => 'Team vs team event created successfully',
                'event' => $event,
                'teams' => $teamIds,
                'enrolled_participants' => $enrolledParticipants
            ], 201);
        } else {
            // Handle free for all events (existing logic)
            $participant = EventParticipant::create([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'status' => 'confirmed',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Event created successfully',
                'event' => $event,
                'creator_participant' => $participant
            ], 201);
        }
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
