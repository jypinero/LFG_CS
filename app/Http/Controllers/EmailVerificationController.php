<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmailVerification\SendVerificationOtpRequest;
use App\Http\Requests\EmailVerification\VerifyEmailRequest;
use App\Http\Requests\EmailVerification\ResendVerificationOtpRequest;
use App\Mail\OtpCodeMail;
use App\Models\UserOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class EmailVerificationController extends Controller
{
    /**
     * Send verification OTP to logged-in user's email
     */
    public function sendVerificationOtp(SendVerificationOtpRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }

        // Check if email is already verified
        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified',
                'email_verified' => true,
            ], 200);
        }

        // Invalidate previous verification OTPs for this user
        UserOtp::where('user_id', $user->id)
            ->where('type', UserOtp::TYPE_VERIFICATION)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);

        // Generate new OTP
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp = UserOtp::create([
            'user_id' => $user->id,
            'type' => UserOtp::TYPE_VERIFICATION,
            'code' => $code,
            'expires_at' => Carbon::now()->addMinutes(5),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Send email with error handling
        try {
            Mail::to($user->email)->send(new OtpCodeMail($code, 5));
            \Log::info("Email verification OTP sent successfully to user {$user->id} ({$user->email})");
        } catch (\Exception $e) {
            \Log::error("Failed to send email verification OTP to user {$user->id}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send verification email. Please try again later.',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Verification OTP sent to your email address',
        ]);
    }

    /**
     * Verify email using OTP code
     */
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }

        // Check if already verified
        if ($user->email_verified_at) {
            return response()->json([
                'status' => 'success',
                'message' => 'Email is already verified',
                'email_verified' => true,
            ], 200);
        }

        // Find active verification OTP
        $otp = UserOtp::where('user_id', $user->id)
            ->where('type', UserOtp::TYPE_VERIFICATION)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (!$otp) {
            return response()->json(['message' => 'No active verification OTP found'], 400);
        }

        // Check expiration
        if ($otp->expires_at->isPast()) {
            return response()->json(['message' => 'OTP expired'], 400);
        }

        // Check attempts
        if ($otp->attempts >= 5) {
            return response()->json(['message' => 'Too many attempts'], 429);
        }

        // Verify code
        if (!hash_equals($otp->code, $request->input('code'))) {
            $otp->increment('attempts');
            return response()->json(['message' => 'Invalid code'], 401);
        }

        // Mark OTP as consumed
        $otp->update(['consumed_at' => Carbon::now()]);

        // Update user's email_verified_at
        $user->email_verified_at = Carbon::now();
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Email verified successfully',
            'email_verified' => true,
        ]);
    }

    /**
     * Resend verification OTP
     */
    public function resendVerificationOtp(ResendVerificationOtpRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }

        // Check if already verified
        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified',
                'email_verified' => true,
            ], 200);
        }

        // Invalidate previous verification OTPs
        UserOtp::where('user_id', $user->id)
            ->where('type', UserOtp::TYPE_VERIFICATION)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);

        // Generate new OTP
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp = UserOtp::create([
            'user_id' => $user->id,
            'type' => UserOtp::TYPE_VERIFICATION,
            'code' => $code,
            'expires_at' => Carbon::now()->addMinutes(5),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Send email with error handling
        try {
            Mail::to($user->email)->send(new OtpCodeMail($code, 5));
            \Log::info("Email verification OTP resent successfully to user {$user->id} ({$user->email})");
        } catch (\Exception $e) {
            \Log::error("Failed to send email verification OTP to user {$user->id}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send verification email. Please try again later.',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Verification OTP resent',
        ]);
    }
}
