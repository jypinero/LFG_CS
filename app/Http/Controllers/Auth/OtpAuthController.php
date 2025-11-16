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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;

class OtpAuthController extends Controller
{
	public function login(LoginRequest $request)
	{
		$user = User::where('email', $request->input('email'))->first();
		if (!$user || !Hash::check($request->input('password'), $user->password)) {
			return response()->json(['message' => 'Invalid credentials'], 401);
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

		return response()->json([
			'token' => $token,
			'user' => $user->only(['id', 'first_name', 'last_name', 'email', 'role_id']),
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


