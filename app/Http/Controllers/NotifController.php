<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\PostLike;
use App\Models\PostComment;
use App\Models\Notification;
use App\Models\UserNotification;

class NotifController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $notifications = $user->notifications()->get();
        return response()->json(['notifications' => $notifications], 200);
    }

    /**
     * Send welcome notification when user visits /home
     * GET /api/home
     */
    public function sendHomeWelcome()
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            $welcomeService = app(\App\Services\WelcomeNotificationService::class);
            $welcomeService->sendWelcomeNotification($user->id);

            return response()->json([
                'status' => 'success',
                'message' => 'Welcome notification sent'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send welcome notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function userNotifications()
    {
        try {
            $userId = auth()->id();
            
            // Check if authentication provided a user ID
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            // Verify user actually exists in database
            $user = User::find($userId);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User account not found. Please log in again.',
                    'code' => 'USER_NOT_FOUND',
                    'user_id' => $userId
                ], 404);
            }

            $userNotifications = UserNotification::where('user_id', $userId)
                ->with('notification')
                ->whereHas('notification') // Filter out orphaned records
                ->orderBy('created_at', 'desc')
                ->get()
                ->filter(function ($userNotif) {
                    return $userNotif->notification !== null; // Additional safety check
                })
                ->map(function ($userNotif) {
                    return [
                        'id' => $userNotif->notification->id,
                        'type' => $userNotif->notification->type,
                        'message' => $userNotif->notification->data['message'] ?? '',
                        'notification_created_at' => optional($userNotif->notification->created_at)->toDateTimeString(),
                        'created_at' => optional($userNotif->created_at)->toDateTimeString(),
                        'read_at' => optional($userNotif->read_at)->toDateTimeString(),
                        'is_read' => (bool) $userNotif->is_read, // Use actual field
                        
                        // Extract all relevant data fields for redirects
                        'event_id' => $userNotif->notification->data['event_id'] ?? null,
                        'venue_id' => $userNotif->notification->data['venue_id'] ?? null,
                        'team_id' => $userNotif->notification->data['team_id'] ?? null,
                        'tournament_id' => $userNotif->notification->data['tournament_id'] ?? null,
                        'post_id' => $userNotif->notification->data['post_id'] ?? null,
                        'thread_id' => $userNotif->notification->data['thread_id'] ?? null,
                        'message_id' => $userNotif->notification->data['message_id'] ?? null,
                        'booking_id' => $userNotif->notification->data['booking_id'] ?? null,
                        'document_id' => $userNotif->notification->data['document_id'] ?? null,
                        'participant_id' => $userNotif->notification->data['participant_id'] ?? null,
                        'announcement_id' => $userNotif->notification->data['announcement_id'] ?? null,
                        'match_id' => $userNotif->notification->data['match_id'] ?? null,
                        'coach_id' => $userNotif->notification->data['coach_id'] ?? null,
                        'student_id' => $userNotif->notification->data['student_id'] ?? null,
                        'status' => $userNotif->notification->data['status'] ?? null,
                        'user_id' => $userNotif->notification->data['user_id'] ?? null,
                        'created_by' => $userNotif->notification->created_by,
                        'pinned' => $userNotif->pinned,
                        'action_state' => $userNotif->action_state,
                        'redirect_path' => $this->getRedirectPath($userNotif->notification),
                    ];
                });

            return response()->json([
                'status' => 'success',
                'notifications' => $userNotifications
            ]);
            
        } catch (\Tymon\JWTAuth\Exceptions\UserNotDefinedException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found. Please log in again.',
                'code' => 'USER_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error fetching notifications: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch notifications',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function markAsRead($notificationId)
    {
        $userId = auth()->id();

        $userNotif = UserNotification::where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if (! $userNotif) {
            return response()->json(['status'=>'error','message'=>'Notification not found'], 404);
        }

        $userNotif->read_at = now();
        $userNotif->is_read = true; // ensure boolean flag updated too
        $userNotif->save();

        return response()->json(['status'=>'success','is_read' => true, 'read_at' => $userNotif->read_at], 200);
    }

    public function markAsUnread($notificationId)
    {
        $userId = auth()->id();

        $userNotif = UserNotification::where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if (! $userNotif) {
            return response()->json(['status'=>'error','message'=>'Notification not found'], 404);
        }

        $userNotif->read_at = null;
        $userNotif->is_read = false;
        $userNotif->save();

        return response()->json(['status'=>'success','is_read' => false], 200);
    }

    public function markAllRead()
    {
        $userId = auth()->id();

        UserNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'is_read' => true]);

        return response()->json(['status'=>'success','message'=>'All notifications marked read'], 200);
    }

    /**
     * Get redirect path for a notification based on its type and data
     */
    private function getRedirectPath($notification)
    {
        if (!$notification) {
            return null;
        }

        $type = $notification->type;
        $data = $notification->data ?? [];
        
        $routes = [
            // Tournament notifications
            'participant_no_show' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'document_verified' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : (isset($data['document_id']) ? "/profile/documents" : null),
            'participant_approved' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'participant_rejected' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'participant_banned' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'waitlist_promoted' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'participant_withdrawn' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'match_disputed' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}/matches" : null,
            'tournament_registration_closed' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'tournament_started' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'tournament_completed' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'tournament_cancelled' => isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null,
            'tournament_announcement' => (isset($data['tournament_id']) && isset($data['announcement_id'])) ? "/tournaments/{$data['tournament_id']}/announcements/{$data['announcement_id']}" : (isset($data['tournament_id']) ? "/tournaments/{$data['tournament_id']}" : null),
            
            // Event notifications
            'event_joined' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'team_joined_event' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'team_invitation' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'event_updated' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'event_cancelled' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'event_left' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            'removed_from_event' => "/events",
            'team_invitation_declined' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
            
            // Venue notifications
            'booking_approved' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            'booking_rejected' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            'booking_pending' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            'booking_cancelled' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            'booking_rescheduled' => isset($data['booking_id']) ? "/bookings/{$data['booking_id']}" : null,
            'rate_venue' => (isset($data['event_id']) && isset($data['venue_id'])) ? "/rate-venue?event_id={$data['event_id']}&venue_id={$data['venue_id']}" : null,
            
            // Team notifications
            'team_join_request' => isset($data['team_id']) ? "/teams/{$data['team_id']}/requests" : null,
            'team_member_re_added' => isset($data['team_id']) ? "/teams/{$data['team_id']}" : null,
            
            // Post notifications
            'post_liked' => isset($data['post_id']) ? "/posts/{$data['post_id']}" : null,
            'post_commented' => isset($data['post_id']) ? "/posts/{$data['post_id']}" : null,
            
            // Messaging notifications
            'message_received' => isset($data['thread_id']) ? "/messages/{$data['thread_id']}" : null,
            
            // Document notifications
            'document_rejected' => "/profile/documents",
            'document_processing_complete' => "/profile/documents",
            'document_manual_review_needed' => "/profile/documents",
            
            // Coach notifications
            'coach_liked' => isset($data['coach_id']) ? "/coaches/{$data['coach_id']}" : null,
            'coach_match' => isset($data['match_id']) ? "/coaches/matches/{$data['match_id']}" : null,
            'coach_match_response' => isset($data['match_id']) ? "/coaches/matches/{$data['match_id']}" : null,
            
            // Event group chat notifications
            'event_groupchat_created' => isset($data['thread_id']) ? "/messages/{$data['thread_id']}" : (isset($data['event_id']) ? "/events/{$data['event_id']}" : null),
            'event_groupchat_joined' => isset($data['thread_id']) ? "/messages/{$data['thread_id']}" : (isset($data['event_id']) ? "/events/{$data['event_id']}" : null),
            'event_groupchat_closed' => isset($data['event_id']) ? "/events/{$data['event_id']}" : null,
        ];
        
        return $routes[$type] ?? null;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
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
