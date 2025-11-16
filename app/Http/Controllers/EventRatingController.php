<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventPlayerRating;
use Illuminate\Http\Request;
use App\Http\Requests\Events\SubmitRatingRequest;
use Illuminate\Support\Carbon;

class EventRatingController extends Controller
{
	public function pending(int $eventId, Request $request)
	{
		$user = $request->user();
		$event = Event::findOrFail($eventId);
		// 48h window from event end
		$eventEnd = Carbon::parse($event->date.' '.$event->end_time);
		if (now()->lessThan($eventEnd) || now()->greaterThan($eventEnd->copy()->addHours(48))) {
			return response()->json(['items' => []]);
		}

		// ensure rater is a participant
		$isParticipant = EventParticipant::where('event_id', $eventId)->where('user_id', $user->id)->exists();
		if (!$isParticipant) {
			return response()->json(['message' => 'Forbidden'], 403);
		}

		$participants = EventParticipant::where('event_id', $eventId)
			->where('user_id', '!=', $user->id)
			->pluck('user_id')
			->all();

		$alreadyRated = EventPlayerRating::where('event_id', $eventId)
			->where('rater_id', $user->id)
			->pluck('ratee_id')
			->all();

		$pending = array_values(array_diff($participants, $alreadyRated));
		return response()->json(['items' => $pending]);
	}

	public function submit(int $eventId, SubmitRatingRequest $request)
	{
		$user = $request->user();
		$event = Event::findOrFail($eventId);
		$eventEnd = \Carbon\Carbon::parse($event->date.' '.$event->end_time);
		if (now()->lessThan($eventEnd) || now()->greaterThan($eventEnd->copy()->addHours(48))) {
			return response()->json(['message' => 'Rating window closed'], 400);
		}
		if ((int)$request->input('ratee_id') === (int)$user->id) {
			return response()->json(['message' => 'Cannot rate self'], 422);
		}
		// participant validation
		$bothParticipants = EventParticipant::where('event_id', $eventId)
			->whereIn('user_id', [$user->id, (int)$request->input('ratee_id')])
			->count() === 2;
		if (!$bothParticipants) {
			return response()->json(['message' => 'Forbidden'], 403);
		}
		$rating = EventPlayerRating::updateOrCreate(
			[
				'event_id' => $eventId,
				'rater_id' => $user->id,
				'ratee_id' => (int)$request->input('ratee_id'),
			],
			[
				'stars' => (int)$request->input('stars'),
				'comment' => $request->input('comment'),
			]
		);
		return response()->json($rating);
	}

	public function summary(int $eventId, Request $request)
	{
		// anonymized summary per player
		$rows = EventPlayerRating::selectRaw('ratee_id, AVG(stars) as avg_stars, COUNT(*) as count')
			->where('event_id', $eventId)
			->groupBy('ratee_id')
			->get();
		return response()->json($rows);
	}
}


