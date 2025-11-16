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

        $threads = MessageThread::query()
            ->select('message_threads.*')
            ->join('thread_participants as tp', 'tp.thread_id', '=', 'message_threads.id')
            ->where('tp.user_id', $userId)
            ->whereNull('tp.left_at')
            ->orderBy('message_threads.updated_at', 'desc')
            ->with(['participants.user:id,username,first_name,last_name,profile_photo', 'messages' => function ($q) {
                $q->latest('sent_at')->limit(1);
            }])
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

        $notif = Notification::create([
            'type' => 'message_received',
            'data' => [
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


