<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use App\Models\MessageThread;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\ThreadParticipant;
use Carbon\Carbon;

class CloseEventGroupChats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:close-groupchats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close group chats for ended events';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();
        
        // Find events that have ended (date + end_time has passed)
        $endedEvents = Event::where('date', '<=', $now->toDateString())
            ->whereRaw("CONCAT(date, ' ', end_time) < ?", [$now->toDateTimeString()])
            ->get();

        $closed = 0;
        foreach ($endedEvents as $event) {
            $thread = MessageThread::where('type', 'game_group')
                ->where('game_id', $event->id)
                ->where('is_closed', false)
                ->first();

            if ($thread) {
                $thread->update([
                    'is_closed' => true,
                    'closed_at' => now(),
                ]);

                // Notify all participants
                $participants = ThreadParticipant::where('thread_id', $thread->id)
                    ->whereNull('left_at')
                    ->pluck('user_id');

                $notification = Notification::create([
                    'type' => 'event_groupchat_closed',
                    'data' => [
                        'message' => "Group chat for event '{$event->name}' has been closed (event ended)",
                        'event_id' => $event->id,
                        'event_name' => $event->name,
                        'thread_id' => $thread->id,
                    ],
                    'created_by' => 1, // System
                ]);

                foreach ($participants as $userId) {
                    UserNotification::create([
                        'notification_id' => $notification->id,
                        'user_id' => $userId,
                        'pinned' => false,
                        'is_read' => false,
                        'action_state' => 'none',
                    ]);
                }

                $closed++;
                $this->info("Closed group chat for event: {$event->name}");
            }
        }

        $this->info("✅ Closed {$closed} event group chat(s)");
        return Command::SUCCESS;
    }
}
