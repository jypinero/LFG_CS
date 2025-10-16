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

    public function userNotifications()
    {
        $userId = auth()->id();

        $userNotifications = UserNotification::where('user_id', $userId)
            ->with('notification')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($userNotif) {
                return [
                    'id' => $userNotif->notification->id,
                    'type' => $userNotif->notification->type,
                    'message' => $userNotif->notification->data['message'] ?? '',
                    'notification_created_at' => optional($userNotif->notification->created_at)->toDateTimeString(),
                    'created_at' => optional($userNotif->created_at)->toDateTimeString(),
                    'read_at' => optional($userNotif->read_at)->toDateTimeString(), // ADDED
                    'is_read' => $userNotif->read_at ? true : false,               // ADDED
                    'event_id' => $userNotif->notification->data['event_id'] ?? null,
                    'user_id' => $userNotif->notification->data['user_id'] ?? null,
                    'created_by' => $userNotif->notification->created_by,
                    'pinned' => $userNotif->pinned,
                    'action_state' => $userNotif->action_state,
                ];
            });

        return response()->json([
            'status' => 'success',
            'notifications' => $userNotifications
        ]);
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
