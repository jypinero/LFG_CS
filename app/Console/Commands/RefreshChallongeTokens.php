<?php


namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\ChallongeOauthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RefreshChallongeTokens extends Command
{
    protected $signature = 'challonge:refresh-tokens {--days=1}';
    protected $description = 'Refresh Challonge OAuth tokens for users expiring within the configured window.';

    public function handle(ChallongeOauthService $oauth)
    {
        $days = (int) $this->option('days');
        $threshold = Carbon::now()->addDays($days);

        $users = User::whereNotNull('challonge_refresh_token')
            ->where(function ($q) use ($threshold) {
                $q->whereNull('challonge_token_expires_at')
                  ->orWhere('challonge_token_expires_at', '<=', $threshold);
            })->get();

        $this->info('Found ' . $users->count() . ' users to refresh.');
        foreach ($users as $user) {
            try {
                $ok = $oauth->refreshToken($user);
                $this->info("User {$user->id} refreshed: " . ($ok ? 'ok' : 'failed'));
            } catch (\Throwable $e) {
                Log::error('RefreshChallongeTokens error', ['user' => $user->id, 'err' => $e->getMessage()]);
                $this->error("User {$user->id} refresh exception");
            }
        }

        return 0;
    }
}