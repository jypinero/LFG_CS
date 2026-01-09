<?php


namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ChallongeOauthService
{
    public function requestWithUser($user, $method, $url, $options = [])
    {
        // refresh token if expired
        if ($user->challonge_token_expires_at && Carbon::now()->greaterThan($user->challonge_token_expires_at)) {
            $this->refreshToken($user);
            $user->refresh();
        }

        $token = $user->challonge_access_token;
        $client = Http::withToken($token)->acceptJson();

        $resp = $client->send($method, $url, $options);
        if ($resp->status() === 401 && $user->challonge_refresh_token) {
            // try refresh then retry once
            $this->refreshToken($user);
            $user->refresh();
            $client = Http::withToken($user->challonge_access_token)->acceptJson();
            $resp = $client->send($method, $url, $options);
        }

        return $resp;
    }

    public function refreshToken($user)
    {
        try {
            $res = Http::asForm()->post('https://api.challonge.com/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $user->challonge_refresh_token,
                'client_id' => config('services.challonge.client_id'),
                'client_secret' => config('services.challonge.client_secret'),
            ]);

            if (! $res->successful()) {
                Log::error('Challonge refresh failed', ['status' => $res->status(), 'body' => $res->body()]);
                return false;
            }

            $body = $res->json();
            $user->update([
                'challonge_access_token' => $body['access_token'] ?? $user->challonge_access_token,
                'challonge_refresh_token' => $body['refresh_token'] ?? $user->challonge_refresh_token,
                'challonge_token_expires_at' => isset($body['expires_in']) ? Carbon::now()->addSeconds($body['expires_in']) : null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Challonge refresh exception', ['err' => $e->getMessage()]);
            return false;
        }
    }
}