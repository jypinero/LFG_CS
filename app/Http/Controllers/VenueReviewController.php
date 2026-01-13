<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\VenueReview;

class VenueReviewController extends Controller
{
    /**
     * Store a venue review — only allowed if the authenticated user completed an event at the venue.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        $validator = \Validator::make($request->all(), [
            'venue_id' => 'required|exists:venues,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
            'event_id' => 'nullable|exists:events,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $venueId = $request->venue_id;
        $eventId = $request->event_id;

        // If event_id provided: ensure event belongs to venue, has ended, and user participated
        if ($eventId) {
            $event = Event::find($eventId);
            if (! $event || $event->venue_id != $venueId) {
                return response()->json(['status'=>'error','message'=>'Invalid event for this venue'], 400);
            }
            $ended = Carbon::parse($event->date . ' ' . $event->end_time)->lessThan(now());
            if (! $ended) {
                return response()->json(['status'=>'error','message'=>'You can only review after the event has completed'], 403);
            }
            $participant = EventParticipant::where('event_id', $eventId)->where('user_id', $user->id)->first();
            if (! $participant) {
                return response()->json(['status'=>'error','message'=>'You did not participate in this event'], 403);
            }

            // Prevent duplicate review for same event by same user
            $existing = VenueReview::where('venue_id', $venueId)
                ->where('user_id', $user->id)
                ->where('event_id', $eventId)
                ->first();
            if ($existing) {
                return response()->json(['status'=>'error','message'=>'You have already reviewed this event/venue'], 409);
            }

            // Create review
            $review = VenueReview::create([
                'venue_id' => $venueId,
                'event_id' => $eventId,
                'user_id' => $user->id,
                'rating' => $request->rating,
                'comment' => $request->comment,
            ]);

            // Mark participant as rated if column exists
            if (Schema::hasColumn('event_participants', 'is_rated')) {
                $participant->update(['is_rated' => true]);
            }

            return response()->json(['status'=>'success','review'=>$review], 201);
        }

        // If no event_id: find any past event at this venue where user participated and not yet reviewed
        $pastParticipant = EventParticipant::where('user_id', $user->id)
            ->whereHas('event', function($q) use ($venueId) {
                $q->where('venue_id', $venueId)
                  ->whereRaw("STR_TO_DATE(CONCAT(date,' ',end_time),'%Y-%m-%d %H:%i:%s') < ?", [now()]);
            })
            ->get();

        if ($pastParticipant->isEmpty()) {
            return response()->json(['status'=>'error','message'=>'No completed event found at this venue for you to review'], 403);
        }

        // Ensure user has not already reviewed the same event(s)
        $eligible = null;
        foreach ($pastParticipant as $p) {
            $exists = VenueReview::where('venue_id', $venueId)
                ->where('user_id', $user->id)
                ->where('event_id', $p->event_id)
                ->exists();
            if (! $exists) {
                $eligible = $p;
                break;
            }
        }

        if (! $eligible) {
            return response()->json(['status'=>'error','message'=>'You have already submitted a review for your completed event(s) at this venue'], 409);
        }

        $review = VenueReview::create([
            'venue_id' => $venueId,
            'event_id' => $eligible->event_id,
            'user_id' => $user->id,
            'rating' => $request->rating,
            'comment' => $request->comment,
        ]);

        if (Schema::hasColumn('event_participants', 'is_rated')) {
            $eligible->update(['is_rated' => true]);
        }

        return response()->json(['status'=>'success','review'=>$review], 201);
    }
}