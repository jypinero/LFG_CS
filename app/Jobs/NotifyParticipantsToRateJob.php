<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\VenueReview;
use App\Models\User;
use App\Mail\RateVenueNotificationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyParticipantsToRateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $now = now();

        $query = Event::query()
            ->where('is_approved', true)
            ->whereNull('cancelled_at')
            // events already ended
            ->whereRaw("CONCAT(date,' ',end_time) < ?", [$now->format('Y-m-d H:i:s')]);

        if (Schema::hasColumn('events', 'is_rating_notified')) {
            $query->where('is_rating_notified', false);
        }

        $query->chunk(50, function($events) {
            foreach ($events as $event) {
                DB::transaction(function() use ($event) {
                    try {
                        // Load venue relationship for email sending
                        $event->load('venue');
                        
                        if (!$event->venue_id || !$event->venue) {
                            Log::warning('Event missing venue, skipping venue rating notification', [
                                'event_id' => $event->id,
                            ]);
                            if (Schema::hasColumn('events', 'is_rating_notified')) {
                                try {
                                    $event->update(['is_rating_notified' => true]);
                                } catch (\Exception $e) {
                                    Log::error('Failed to update is_rating_notified for event without venue', [
                                        'event_id' => $event->id,
                                        'error' => $e->getMessage()
                                    ]);
                                }
                            }
                            return;
                        }

                        // Get all participants (including event creator)
                        $participantIds = EventParticipant::where('event_id', $event->id)
                            ->pluck('user_id')
                            ->unique()
                            ->filter(function($id) {
                                return $id; // Include all participants, including event creator
                            })->values();

                        if ($participantIds->isEmpty()) {
                            Log::info('No participants found for event, marking as notified', [
                                'event_id' => $event->id,
                            ]);
                            if (Schema::hasColumn('events', 'is_rating_notified')) {
                                try {
                                    $event->update(['is_rating_notified' => true]);
                                } catch (\Exception $e) {
                                    Log::error('Failed to update is_rating_notified for event with no participants', [
                                        'event_id' => $event->id,
                                        'error' => $e->getMessage()
                                    ]);
                                }
                            }
                            return;
                        }

                        // Get users who have already rated this venue
                        $usersWhoRated = VenueReview::where('venue_id', $event->venue_id)
                            ->whereIn('user_id', $participantIds->toArray())
                            ->pluck('user_id')
                            ->toArray();

                        // Filter out users who already rated the venue
                        $usersToNotify = $participantIds->filter(function($userId) use ($usersWhoRated) {
                            return !in_array($userId, $usersWhoRated);
                        })->values();

                        if ($usersToNotify->isEmpty()) {
                            Log::info('All participants have already rated the venue, marking event as notified', [
                                'event_id' => $event->id,
                                'venue_id' => $event->venue_id,
                            ]);
                            if (Schema::hasColumn('events', 'is_rating_notified')) {
                                try {
                                    $event->update(['is_rating_notified' => true]);
                                } catch (\Exception $e) {
                                    Log::error('Failed to update is_rating_notified when all users rated', [
                                        'event_id' => $event->id,
                                        'error' => $e->getMessage()
                                    ]);
                                }
                            }
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

                        foreach ($usersToNotify as $userId) {
                            UserNotification::create([
                                'notification_id' => $notification->id,
                                'user_id' => $userId,
                                'pinned' => false,
                                'is_read' => false,
                                'action_state' => 'pending'
                            ]);

                            // Send email notification
                            try {
                                $user = User::find($userId);
                                if ($user && $user->email) {
                                    Mail::to($user->email)->send(new RateVenueNotificationMail($event, $user, $event->venue));
                                    Log::info('Venue rating notification email sent', [
                                        'user_id' => $userId,
                                        'event_id' => $event->id,
                                        'venue_id' => $event->venue_id,
                                        'email' => $user->email,
                                    ]);
                                } else {
                                    Log::warning('Skipping email notification - user not found or no email address', [
                                        'user_id' => $userId,
                                        'event_id' => $event->id,
                                    ]);
                                }
                            } catch (\Exception $e) {
                                Log::error('Failed to send venue rating notification email', [
                                    'user_id' => $userId,
                                    'event_id' => $event->id,
                                    'venue_id' => $event->venue_id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        if (Schema::hasColumn('events', 'is_rating_notified')) {
                            try {
                                $event->update(['is_rating_notified' => true]);
                                Log::info('Successfully notified participants to rate venue', [
                                    'event_id' => $event->id,
                                    'venue_id' => $event->venue_id,
                                    'users_notified' => $usersToNotify->count(),
                                    'users_already_rated' => count($usersWhoRated),
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Failed to update is_rating_notified after creating notifications', [
                                    'event_id' => $event->id,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('Error processing venue rating notification for event', [
                            'event_id' => $event->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                });
            }
        });
    }
}