<?php

// app/Http/Controllers/RatingController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PlayerRating;
use App\Models\Event;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Services\RatingService;
use Illuminate\Support\Facades\DB;

class RatingController extends Controller
{
    public function submit(Request $request, Event $event)
    {
        // normalize incoming payload: accept "rated_user_id" or "user_id"
        $payload = $request->all();
        if (! empty($payload['ratings']) && is_array($payload['ratings'])) {
            foreach ($payload['ratings'] as $i => $r) {
                if (isset($r['rated_user_id']) && ! isset($r['user_id'])) {
                    $payload['ratings'][$i]['user_id'] = $r['rated_user_id'];
                }
            }
            $request->replace($payload);
        }

        \Log::info('RatingController@submit called', ['event_id' => $event->id, 'user_id' => auth()->id(), 'payload' => $request->all()]);

        $request->validate([
            'ratings' => 'required|array',
            'ratings.*.user_id' => 'required|exists:users,id',
            'ratings.*.rating' => 'required|integer|min:1|max:5',
            'ratings.*.comment' => 'nullable|string|max:500',
        ]);

        $user = auth()->user();

        if (strtotime($event->date.' '.$event->end_time) > time()) {
            return response()->json(['error' => 'Event not finished yet'], 403);
        }

        $event->load('participants');
        $participantIds = $event->participants->pluck('user_id')->toArray();

        if (! in_array($user->id, $participantIds)) {
            return response()->json(['error' => 'You are not a participant of this event'], 403);
        }

        $created = [];
        DB::transaction(function () use ($request, $user, $event, $participantIds, &$created) {
            foreach ($request->ratings as $rate) {
                if ($rate['user_id'] === $user->id || ! in_array($rate['user_id'], $participantIds)) {
                    abort(422, 'Invalid rating target.');
                }

                $pr = PlayerRating::updateOrCreate(
                    [
                        'event_id' => $event->id,
                        'rater_user_id' => $user->id,
                        'rated_user_id' => $rate['user_id'],
                    ],
                    [
                        'rating' => $rate['rating'],
                        'comment' => $rate['comment'] ?? null,
                    ]
                );

                $created[] = $pr->fresh()->toArray();

                // keep original behavior but avoid static call errors if service not found
                try {
                    \App\Services\RatingService::updateUserRating($rate['user_id'], $rate['rating']);
                } catch (\Throwable $e) {
                    \Log::warning('RatingService::updateUserRating failed', ['err'=>$e->getMessage()]);
                }

                $notif = Notification::create([
                    'type' => 'player_rated',
                    'data' => [
                        'rater' => $user->username,
                        'rating' => $rate['rating'],
                        'event_id' => $event->id,
                    ],
                    'created_by' => $user->id,
                ]);

                UserNotification::create([
                    'notification_id' => $notif->id,
                    'user_id' => $rate['user_id'],
                    'pinned' => false,
                    'is_read' => false,
                    'action_state' => 'completed',
                    'created_at' => now(),
                ]);
            }
        });

        \Log::info('Ratings created', ['created_count' => count($created ?? []), 'created' => $created ?? []]);

        return response()->json(['message'=>'Ratings submitted','created'=>$created], 201);
    }
}
