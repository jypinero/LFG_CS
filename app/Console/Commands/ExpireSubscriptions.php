<?php 

// app/Console/Commands/ExpireSubscriptions.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VenueSubscription;
use Carbon\Carbon;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Expire subscriptions that have ended';

    public function handle()
    {
        $now = Carbon::now();

        $expiredSubscriptions = VenueSubscription::where('ends_at', '<', $now)
            ->where('status', 'active')
            ->get();

        foreach ($expiredSubscriptions as $sub) {
            $sub->status = 'expired';
            $sub->save();

            // Optionally notify user here
        }

        $this->info('Expired subscriptions updated.');
    }
}

