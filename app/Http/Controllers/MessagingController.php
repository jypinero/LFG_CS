<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\ThreadParticipant;
use App\Models\Notification;
use App\Models\UserNotification;

class MessagingController extends Controller
{
    public function threads(Request $request)
    {
        $userId = auth()->id();

        // Get thread IDs for this user
        $threadIds = ThreadParticipant::where('user_id', $userId)
            ->whereNull('left_at')
            ->pluck('thread_id');

        // Get threads with latest message timestamp using subquery
        // Order by the latest message sent_at (regardless of who sent it)
        $threads = MessageThread::query()
            ->whereIn('id', $threadIds)
            ->select('message_threads.*')
            ->selectSub(function ($query) {
                $query->selectRaw('MAX(sent_at)')
                    ->from('messages')
                    ->whereColumn('messages.thread_id', 'message_threads.id')
                    ->whereNull('deleted_at');
            }, 'latest_message_at')
            ->with(['participants.user:id,username,first_name,last_name,profile_photo', 'messages' => function ($q) {
                $q->whereNull('deleted_at')
                  ->orderBy('sent_at', 'desc')
                  ->limit(1);
            }])
            ->orderByRaw('COALESCE(latest_message_at, message_threads.updated_at) DESC')
            ->get();

        return response()->json(['threads' => $threads]);
    }

    public function threadMessages(Request $request, string $threadId)
    {
        $userId = auth()->id();
        $this->assertParticipant($threadId, $userId);

        $messages = Message::where('thread_id', $threadId)
            ->orderBy('sent_at', 'asc')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    public function createOneToOneByUsername(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string'],
        ]);
        $me = auth()->user();
        $target = User::where('username', $request->input('username'))->first();
        if (!$target) {
            return response()->json(['message' => 'User not found'], 404);
        }
        if ($target->id === $me->id) {
            return response()->json(['message' => 'Cannot message yourself'], 422);
        }

        // Find existing one-to-one thread
        $existing = MessageThread::query()
            ->where('is_group', false)
            ->where(function ($q) use ($me, $target) {
                $q->whereHas('participants', function ($q2) use ($me) {
                    $q2->where('user_id', $me->id)->whereNull('left_at');
                })->whereHas('participants', function ($q3) use ($target) {
                    $q3->where('user_id', $target->id)->whereNull('left_at');
                });
            })
            ->first();

        if ($existing) {
            return response()->json(['thread' => $existing, 'status' => 'exists'], 200);
        }

        // For now, directly create 1:1 thread (frontend may implement request gate)
        $thread = new MessageThread([
            'created_by' => $me->id,
            'is_group' => false,
            'title' => null,
            'type' => 'one_to_one',
        ]);
        $thread->save();

        ThreadParticipant::create([
            'thread_id' => $thread->id,
            'user_id' => $me->id,
            'role' => 'owner',
            'joined_at' => now(),
            'notifications' => true,
        ]);
        ThreadParticipant::create([
            'thread_id' => $thread->id,
            'user_id' => $target->id,
            'role' => 'member',
            'joined_at' => now(),
            'notifications' => true,
        ]);

        return response()->json(['thread' => $thread, 'status' => 'created'], 201);
    }

    public function createGroup(Request $request)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'participants' => ['required', 'array', 'min:1'],
            'participants.*' => ['string'], // usernames
            'type' => ['nullable', 'in:team,venue,game_group,coach,group'],
        ]);
        $me = auth()->user();
        $usernames = array_values(array_unique($data['participants']));
        // Load users by username, exclude self duplicates later
        $users = User::whereIn('username', $usernames)->get(['id','username']);
        if ($users->isEmpty()) {
            return response()->json(['message' => 'No valid participants'], 422);
        }
        // Create thread
        $thread = new MessageThread([
            'created_by' => $me->id,
            'is_group' => true,
            'title' => $data['title'] ?? null,
            'type' => $data['type'] ?? 'team',
        ]);
        $thread->save();
        // Add creator
        ThreadParticipant::create([
            'thread_id' => $thread->id,
            'user_id' => $me->id,
            'role' => 'owner',
            'joined_at' => now(),
            'notifications' => true,
        ]);
        // Add participants
        foreach ($users as $u) {
            if ($u->id === $me->id) {
                continue;
            }
            ThreadParticipant::create([
                'thread_id' => $thread->id,
                'user_id' => $u->id,
                'role' => 'member',
                'joined_at' => now(),
                'notifications' => true,
            ]);
        }
        return response()->json(['thread' => $thread, 'status' => 'created'], 201);
    }

    public function createTeamThread(Request $request, int $teamId)
    {
        $me = auth()->user();
        $team = \App\Models\Team::findOrFail($teamId);
        // members
        $memberIds = \App\Models\TeamMember::where('team_id', $teamId)
            ->whereNull('left_at')
            ->pluck('user_id')->unique()->all();
        if (empty($memberIds)) {
            return response()->json(['message' => 'No team members'], 422);
        }
        $thread = new MessageThread([
            'created_by' => $me->id,
            'is_group' => true,
            'title' => $team->name ?? ('Team '.$teamId),
            'type' => 'team',
            'team_id' => $teamId,
        ]);
        $thread->save();
        $now = now();
        foreach (array_unique(array_merge([$me->id], $memberIds)) as $uid) {
            ThreadParticipant::create([
                'thread_id' => $thread->id,
                'user_id' => $uid,
                'role' => 'member',
                'joined_at' => $now,
                'notifications' => true,
            ]);
        }
        return response()->json(['thread' => $thread, 'status' => 'created'], 201);
    }

    public function createVenueThread(Request $request, int $venueId)
    {
        $me = auth()->user();
        $venue = \App\Models\Venue::findOrFail($venueId);
        $staffUserIds = \App\Models\VenueUser::where('venue_id', $venueId)->pluck('user_id')->unique()->all();
        $ownerId = $venue->user_id ?? null;
        $userIds = array_filter(array_unique(array_merge([$me->id], $staffUserIds, [$ownerId])));
        if (empty($userIds)) {
            return response()->json(['message' => 'No venue members'], 422);
        }
        $thread = new MessageThread([
            'created_by' => $me->id,
            'is_group' => true,
            'title' => $venue->name ?? ('Venue '.$venueId),
            'type' => 'venue',
            'venue_id' => $venueId,
        ]);
        $thread->save();
        $now = now();
        foreach ($userIds as $uid) {
            ThreadParticipant::create([
                'thread_id' => $thread->id,
                'user_id' => $uid,
                'role' => 'member',
                'joined_at' => $now,
                'notifications' => true,
            ]);
        }
        return response()->json(['thread' => $thread, 'status' => 'created'], 201);
    }

    public function createGameThread(Request $request, int $eventId)
    {
        $me = auth()->user();
        $event = \App\Models\Event::findOrFail($eventId);
        $participantIds = \App\Models\EventParticipant::where('event_id', $eventId)->pluck('user_id')->unique()->all();
        if (empty($participantIds)) {
            return response()->json(['message' => 'No event participants'], 422);
        }
        $thread = new MessageThread([
            'created_by' => $me->id,
            'is_group' => true,
            'title' => $event->title ?? ('Game '.$eventId),
            'type' => 'game_group',
            'game_id' => $eventId,
        ]);
        $thread->save();
        $now = now();
        foreach (array_unique(array_merge([$me->id], $participantIds)) as $uid) {
            ThreadParticipant::create([
                'thread_id' => $thread->id,
                'user_id' => $uid,
                'role' => 'member',
                'joined_at' => $now,
                'notifications' => true,
            ]);
        }
        return response()->json(['thread' => $thread, 'status' => 'created'], 201);
    }
    public function sendMessage(Request $request, string $threadId)
    {
        $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $userId = auth()->id();
        $thread = MessageThread::findOrFail($threadId);

        $this->assertParticipant($threadId, $userId);

        if ($thread->is_closed) {
            return response()->json(['message' => 'Thread is closed'], 403);
        }

        $message = new Message([
            'id' => (string) Str::uuid(),
            'thread_id' => $threadId,
            'sender_id' => $userId,
            'body' => $request->input('body'),
            'sent_at' => now(),
        ]);
        $message->save();

        // bump thread updated_at
        $thread->touch();

        // notifications for other participants
        $this->notifyParticipantsNewMessage($thread, $userId, $message);

        return response()->json(['message' => $message], 201);
    }

    public function archive(Request $request, string $threadId)
    {
        $userId = auth()->id();
        // Use direct update to avoid composite key save issues
        ThreadParticipant::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->update(['archived' => true]);
        return response()->json(['status' => 'archived']);
    }

    public function unarchive(Request $request, string $threadId)
    {
        $userId = auth()->id();
        ThreadParticipant::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->update(['archived' => false]);
        return response()->json(['status' => 'unarchived']);
    }

    public function leave(Request $request, string $threadId)
    {
        $userId = auth()->id();
        ThreadParticipant::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->update(['left_at' => now()]);
        return response()->json(['status' => 'left']);
    }

    public function markRead(Request $request, string $threadId)
    {
        $request->validate([
            'last_read_message_id' => ['required', 'string'],
        ]);
        $userId = auth()->id();
        ThreadParticipant::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->update(['last_read_message_id' => $request->input('last_read_message_id')]);
        return response()->json(['status' => 'ok']);
    }

    /**
     * Get thread details
     */
    public function show(string $threadId)
    {
        $userId = auth()->id();
        $this->assertParticipant($threadId, $userId);

        $thread = MessageThread::with([
            'participants.user:id,username,first_name,last_name,profile_photo',
            'creator:id,username,first_name,last_name,profile_photo'
        ])->findOrFail($threadId);

        $currentParticipant = ThreadParticipant::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->first();

        return response()->json([
            'thread' => $thread,
            'current_user_role' => $currentParticipant->role ?? null,
            'current_user_archived' => $currentParticipant->archived ?? false,
            'current_user_notifications' => $currentParticipant->notifications ?? true,
            'current_user_muted_until' => $currentParticipant->mute_until ?? null,
        ]);
    }

    /**
     * Update thread title (group chats only)
     */
    public function updateTitle(Request $request, string $threadId)
    {
        $request->validate([
            'title' => ['required', 'string', 'max:120'],
        ]);

        $userId = auth()->id();
        $thread = MessageThread::findOrFail($threadId);
        
        $this->assertParticipant($threadId, $userId);
        $this->assertCanManageThread($threadId, $userId);

        if (!$thread->is_group) {
            return response()->json(['message' => 'Can only change title for group chats'], 422);
        }

        $thread->update(['title' => $request->input('title')]);

        return response()->json([
            'status' => 'success',
            'message' => 'Thread title updated',
            'thread' => $thread->fresh()
        ]);
    }

    /**
     * Add participant to thread (converts 1:1 to group if needed)
     */
    public function addParticipant(Request $request, string $threadId)
    {
        $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'username' => ['nullable', 'string', 'exists:users,username'],
        ]);

        $userId = auth()->id();
        $thread = MessageThread::findOrFail($threadId);
        
        $this->assertParticipant($threadId, $userId);
        
        // Only group chats or 1:1 chats can add participants
        if ($thread->is_closed) {
            return response()->json(['message' => 'Thread is closed'], 403);
        }

        // Find user to add
        $userToAdd = null;
        if ($request->filled('user_id')) {
            $userToAdd = User::find($request->input('user_id'));
        } elseif ($request->filled('username')) {
            $userToAdd = User::where('username', $request->input('username'))->first();
        }

        if (!$userToAdd) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Check if user is already a participant
        $existingParticipant = ThreadParticipant::where('thread_id', $threadId)
            ->where('user_id', $userToAdd->id)
            ->whereNull('left_at')
            ->exists();

        if ($existingParticipant) {
            return response()->json(['message' => 'User is already a participant'], 422);
        }

        // If 1:1 chat, convert to group
        if (!$thread->is_group) {
            $thread->update([
                'is_group' => true,
                'title' => null, // Can be set later
            ]);
        }

        // Add participant
        ThreadParticipant::create([
            'thread_id' => $threadId,
            'user_id' => $userToAdd->id,
            'role' => 'member',
            'joined_at' => now(),
            'notifications' => true,
        ]);

        // Notify the added user
        $notification = Notification::create([
            'type' => 'message_received',
            'data' => [
                'message' => "You've been added to the conversation: " . ($thread->title ?? 'Group Chat'),
                'thread_id' => $threadId,
                'thread_type' => 'group',
            ],
            'created_by' => $userId,
        ]);

        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $userToAdd->id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Participant added successfully',
            'thread' => $thread->fresh(['participants.user:id,username,first_name,last_name,profile_photo'])
        ]);
    }

    /**
     * Remove participant from thread
     */
    public function removeParticipant(Request $request, string $threadId, int $participantUserId)
    {
        $userId = auth()->id();
        $thread = MessageThread::findOrFail($threadId);
        
        $this->assertParticipant($threadId, $userId);
        $this->assertCanManageThread($threadId, $userId);

        if (!$thread->is_group) {
            return response()->json(['message' => 'Cannot remove participants from 1:1 chats'], 422);
        }

        // Can't remove yourself (use leave instead)
        if ($participantUserId === $userId) {
            return response()->json(['message' => 'Use leave endpoint to leave the thread'], 422);
        }

        // Can't remove thread owner
        if ($thread->created_by === $participantUserId) {
            return response()->json(['message' => 'Cannot remove thread owner'], 403);
        }

        $participant = ThreadParticipant::where('thread_id', $threadId)
            ->where('user_id', $participantUserId)
            ->whereNull('left_at')
            ->firstOrFail();

        $participant->update(['left_at' => now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'Participant removed successfully'
        ]);
    }

    /**
     * Edit a message
     */
    public function editMessage(Request $request, string $threadId, string $messageId)
    {
        $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $userId = auth()->id();
        $thread = MessageThread::findOrFail($threadId);
        $message = Message::where('thread_id', $threadId)
            ->where('id', $messageId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Only sender can edit
        if ($message->sender_id !== $userId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($thread->is_closed) {
            return response()->json(['message' => 'Thread is closed'], 403);
        }

        $message->update([
            'body' => $request->input('body'),
            'edited_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $message->fresh()
        ]);
    }

    /**
     * Delete a message
     */
    public function deleteMessage(Request $request, string $threadId, string $messageId)
    {
        $userId = auth()->id();
        $thread = MessageThread::findOrFail($threadId);
        $message = Message::where('thread_id', $threadId)
            ->where('id', $messageId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Only sender can delete
        if ($message->sender_id !== $userId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $message->update(['deleted_at' => now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'Message deleted successfully'
        ]);
    }

    /**
     * Check if user can manage thread (owner or admin)
     */
    private function assertCanManageThread(string $threadId, int $userId): void
    {
        $participant = ThreadParticipant::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();

        if (!$participant) {
            abort(403, 'Not a participant');
        }

        $thread = MessageThread::findOrFail($threadId);
        
        // Owner can always manage
        if ($thread->created_by === $userId) {
            return;
        }

        // Admins can manage
        if (in_array($participant->role, ['owner', 'admin'])) {
            return;
        }

        abort(403, 'Insufficient permissions');
    }

    private function assertParticipant(string $threadId, int $userId): void
    {
        $member = ThreadParticipant::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
        abort_unless($member, 403, 'Not a participant');
    }

    private function notifyParticipantsNewMessage(MessageThread $thread, int $senderId, Message $message): void
    {
        $recipients = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', '!=', $senderId)
            ->whereNull('left_at')
            ->where('notifications', true)
            ->pluck('user_id')
            ->all();

        if (empty($recipients)) {
            return;
        }

        // Get sender info for message
        $sender = \App\Models\User::find($senderId);
        $senderName = $sender ? ($sender->username ?? trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? '')) ?? 'Someone') : 'Someone';
        
        // Create message preview (truncate if too long)
        $messagePreview = mb_strlen($message->body) > 100 
            ? mb_substr($message->body, 0, 100) . '...' 
            : $message->body;
        
        // Build notification message
        $notificationMessage = $thread->is_group 
            ? "{$senderName} sent a message in {$thread->title}: {$messagePreview}"
            : "{$senderName} sent you a message: {$messagePreview}";

        $notif = Notification::create([
            'type' => 'message_received',
            'data' => [
                'message' => $notificationMessage,
                'thread_id' => $thread->id,
                'thread_type' => $thread->type ?? ($thread->is_group ? 'group' : 'one_to_one'),
                'message_id' => $message->id,
                'sender_id' => $senderId,
            ],
            'created_by' => $senderId,
            'created_at' => now(),
        ]);

        foreach ($recipients as $uid) {
            UserNotification::create([
                'notification_id' => $notif->id,
                'user_id' => $uid,
                'created_at' => now(),
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);
        }
    }
}


