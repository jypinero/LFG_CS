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
            ->with('notification') // assumes you have a relation set up
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($userNotif) {
                return [
                    'id' => $userNotif->notification->id,
                    'type' => $userNotif->notification->type,
                    'message' => $userNotif->notification->data['message'] ?? '',
                    'created_at' => $userNotif->notification->created_at,
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
