<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;
use App\Models\Event;
use App\Jobs\NotifyParticipantsToRateJob;
use Illuminate\Support\Facades\Schema;

class CompletePastEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $now = now()->format('Y-m-d H:i:s');

        Event::whereNull('cancelled_at')
            ->where(function($q) {
                $q->whereNull('game_status')->orWhere('game_status', '!=', 'completed');
            })
            ->whereRaw("CONCAT(date,' ',end_time) < ?", [$now])
            ->chunkById(100, function($events) {
                foreach ($events as $event) {
                    $event->update([
                        'game_status' => 'completed',
                        'completed_at' => now(),
                    ]);
                }
            });

        // Dispatch notification job (it will only notify events not already marked notified)
        NotifyParticipantsToRateJob::dispatch();
    }
}