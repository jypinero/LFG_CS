<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\User;

class ChallongeAuthController extends Controller
{
    public function redirect()
    {
        $clientId = config('services.challonge.client_id');
        $redirect  = config('services.challonge.redirect');
        $state = null;
        if (auth()->check()) {
            // include user id so callback can persist tokens to correct user
            $state = base64_encode((string) auth()->id());
        }
        $url = 'https://api.challonge.com/oauth/authorize'
            . '?client_id=' . urlencode($clientId)
            . '&redirect_uri=' . urlencode($redirect)
            . '&response_type=code'
            . ($state ? '&state=' . urlencode($state) : '');

        return redirect($url);
    }

    public function callback(Request $request)
    {
        $code = $request->input('code');
        if (! $code) {
            return response()->json(['error'=>'authorization code missing'], 400);
        }

        $res = Http::asForm()->post('https://api.challonge.com/oauth/token', [
            'client_id' => config('services.challonge.client_id'),
            'client_secret' => config('services.challonge.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.challonge.redirect'),
        ]);

        if (! $res->successful()) {
            Log::error('Challonge token exchange failed', ['status'=>$res->status(),'body'=>$res->body()]);
            return response()->json(['error'=>'token exchange failed','body'=>$res->body()], 500);
        }

        $body = $res->json();

        // prefer state (base64 user id) if provided
        $saved = false;
        $state = $request->input('state');
        if ($state) {
            $decoded = @base64_decode($state, true);
            if ($decoded && is_numeric($decoded)) {
                $u = User::find((int)$decoded);
                if ($u) {
                    $u->update([
                        'challonge_uid' => $body['user_id'] ?? $u->challonge_uid,
                        'challonge_access_token' => $body['access_token'] ?? null,
                        'challonge_refresh_token' => $body['refresh_token'] ?? null,
                        'challonge_token_expires_at' => isset($body['expires_in']) ? Carbon::now()->addSeconds($body['expires_in']) : $u->challonge_token_expires_at,
                    ]);
                    $saved = true;
                }
            }
        }

        // fallback: if session authenticated (rare on redirect) save to auth user
        if (! $saved && auth()->check()) {
            $user = auth()->user();
            $user->update([
                'challonge_uid' => $body['user_id'] ?? $user->challonge_uid,
                'challonge_access_token' => $body['access_token'] ?? null,
                'challonge_refresh_token' => $body['refresh_token'] ?? null,
                'challonge_token_expires_at' => isset($body['expires_in']) ? Carbon::now()->addSeconds($body['expires_in']) : $user->challonge_token_expires_at,
            ]);
            $saved = true;
        }

        if ($saved) {
            $frontend = rtrim(env('FRONTEND_URL', '/'), '/');
            return redirect($frontend . '/?challonge_connected=1');
        }

        // No mapping to user -> refuse to expose tokens
        return response()->json(['error' => 'No mapping to user.'], 403);
    }

    public function saveTokens(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'access_token' => 'required|string',
            'refresh_token' => 'nullable|string',
            'expires_in' => 'nullable|integer',
            'user_id' => 'nullable|string',
        ]);

        $user->update([
            'challonge_access_token' => $data['access_token'],
            'challonge_refresh_token' => $data['refresh_token'] ?? null,
            'challonge_uid' => $data['user_id'] ?? $user->challonge_uid,
            'challonge_token_expires_at' => isset($data['expires_in']) ? Carbon::now()->addSeconds($data['expires_in']) : $user->challonge_token_expires_at,
        ]);

        return response()->json(['status' => 'success']);
    }
}