<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Models\UserOtp;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Venue;
use App\Models\Team;
use App\Models\TeamMember;

class OtpAuthController extends Controller
{
	public function login(LoginRequest $request)
	{
		$user = User::where('email', $request->input('email'))->first();
		if (!$user) {
			return response()->json(['message' => 'User not found'], 404);
		}

		$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
		$otp = UserOtp::create([
			'user_id' => $user->id,
			'code' => $code,
			'expires_at' => Carbon::now()->addMinutes(10),
			'ip' => $request->ip(),
			'user_agent' => $request->userAgent(),
		]);

		Mail::to($user->email)->send(new OtpCodeMail($code, 10));

		return response()->json([
			'next' => 'otp',
			'message' => 'OTP sent to your email address',
		]);
	}

	public function verify(VerifyOtpRequest $request)
	{
		$user = User::where('email', $request->input('email'))->first();
		if (!$user) {
			return response()->json(['message' => 'Invalid request'], 400);
		}

		$otp = UserOtp::where('user_id', $user->id)
			->whereNull('consumed_at')
			->orderByDesc('id')
			->first();

		if (!$otp) {
			return response()->json(['message' => 'No active OTP found'], 400);
		}

		if ($otp->expires_at->isPast()) {
			return response()->json(['message' => 'OTP expired'], 400);
		}

		if ($otp->attempts >= 5) {
			return response()->json(['message' => 'Too many attempts'], 429);
		}

		if (!hash_equals($otp->code, $request->input('code'))) {
			$otp->increment('attempts');
			return response()->json(['message' => 'Invalid code'], 401);
		}

		$otp->update(['consumed_at' => Carbon::now()]);

		$token = JWTAuth::fromUser($user);

		// Load user relations (same as AuthController login)
		$user->load('role', 'userProfile', 'userAdditionalSports.sport');

		// Send welcome notification (async, won't block login)
		try {
			$welcomeService = app(\App\Services\WelcomeNotificationService::class);
			$welcomeService->sendWelcomeNotification($user->id);
		} catch (\Exception $e) {
			// Log but don't fail login
			\Log::error('Failed to send welcome notification on OTP login', ['error' => $e->getMessage()]);
		}

		return response()->json([
			'status' => 'success',
			'message' => 'Login successful',
			'authorization' => [
				'token' => $token,
				'type' => 'bearer',
			],
			'user' => $user->only(['id', 'first_name', 'last_name', 'email', 'username', 'role_id']),
			'has_team' => TeamMember::where('user_id', $user->id)->exists(),
			'teams' => Team::whereIn('id', TeamMember::where('user_id', $user->id)->pluck('team_id'))
				->get(['id', 'name'])
				->map(function ($t) { return ['id' => $t->id, 'name' => $t->name]; }),
			'has_venue' => Venue::where('created_by', $user->id)->exists(),
			'venues' => Venue::where('created_by', $user->id)
				->get(['id', 'name'])
				->map(function ($v) { return ['id' => $v->id, 'name' => $v->name]; }),
		]);
	}

	public function resend(ResendOtpRequest $request)
	{
		$user = User::where('email', $request->input('email'))->first();
		if (!$user) {
			return response()->json(['message' => 'Invalid request'], 400);
		}

		$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
		$otp = UserOtp::create([
			'user_id' => $user->id,
			'code' => $code,
			'expires_at' => Carbon::now()->addMinutes(10),
		]);

		Mail::to($user->email)->send(new OtpCodeMail($code, 10));

		return response()->json(['message' => 'OTP resent']);
	}
}


