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


class EventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $events = Event::with(['venue', 'facility'])
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

                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'start_time' => $event->start_time,
                    'participants_count' => $event->participants_count,
                    'total_slots' => $event->slots,
                    'venue' => $event->venue->name,
                    'hours' => $hours,
                    'total_cost' => $totalCost,
                    'cost_per_slot' => $dividedpay,
                    'host' => User::find($event->created_by)->username,
                    // add more fields as needed
                ];
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
        $event = Event::with(['venue', 'facility'])
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

        return response()->json([
            'status' => 'success',
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'date' => $event->date,
                'time' => $event->start_time . ' - ' . $event->end_time,
                'slots' => $event->participants_count . '/' . $event->slots,
                'creator' => User::find($event->created_by)->username,
                'venue' => [
                    'name' => $event->venue->name,
                    'address' => $event->venue->address,
                ],
                'Price/hour' => $event->facility->price_per_hr ?? 0,
                'Chip-in approx' => $dividedpay,
                'participants' => [
                    'name' => $participants->map(function($participant) {
                        return $participant->user ? $participant->user->username : 'Unknown User';
                    })
                ],
                // add more fields as needed
            ],
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
                    'facility' => $event->facility->name ?? null,
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

        $alreadyJoined = EventParticipant::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->exists();

            
        

        if ($alreadyJoined) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already joined this event.'
            ], 409);
        }

        $participant = EventParticipant::create([
            'event_id' => $event->id,
            'user_id' => $userId,
            'status' => 'confirmed',
        ]);

        //send notif to event creator

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
            'action_state' => 'none',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully joined the event.',
            'participant' => $participant
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
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

        $start_at = $request->date . ' ' . $request->start_time;
        $end_at = $request->date . ' ' . $request->end_time;

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
