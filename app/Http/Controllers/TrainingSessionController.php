<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use App\Models\User;
use App\Models\CoachProfile;
use App\Models\CoachMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TrainingSessionController extends Controller
{
   public function requestSession(Request $request, $coachId)
    {
        Log::info('requestSession entered', [
            'coachId' => $coachId,
            'studentId' => Auth::id()
        ]);

        $studentId = Auth::id();

        /** Prevent self booking */
        if ($studentId === (int) $coachId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot request a session with yourself'
            ], 400);
        }

        /** Validate coach */
        $coach = User::find($coachId);
        if (! $coach) {
            return response()->json([
                'status' => 'error',
                'message' => 'Coach not found'
            ], 404);
        }

        if (! CoachProfile::where('user_id', $coachId)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'User is not a coach'
            ], 400);
        }

        /** Validate match */
        $matched = CoachMatch::where('student_id', $studentId)
            ->where('coach_id', $coachId)
            ->where('match_status', 'matched')
            ->exists();

        if (! $matched) {
            return response()->json([
                'status' => 'error',
                'message' => 'You must be matched with this coach to request a session'
            ], 403);
        }

        /** Validate request data */
        $data = $request->validate([
            'sport'        => 'required|string|max:100',
            'session_date' => 'required|date',
            'start_time'   => 'required|date_format:H:i',
            'end_time'     => 'required|date_format:H:i|after:start_time',
            'hourly_rate'  => 'required|numeric|min:0',
            'venue_id'     => 'nullable|integer|exists:venues,id',
            'event_id'     => 'nullable|integer|exists:events,id',
            'notes'        => 'nullable|string',
        ]);

        /** Time parsing */
        $startTime = Carbon::createFromFormat('H:i', $data['start_time']);
        $endTime   = Carbon::createFromFormat('H:i', $data['end_time']);

        $durationMinutes = $startTime->diffInMinutes($endTime);
        $hours = $durationMinutes / 60;

        if ($hours <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid session duration'
            ], 422);
        }

        $totalAmount = round($hours * $data['hourly_rate'], 2);

        /** Overlap check */
        $conflict = TrainingSession::where('coach_id', $coachId)
            ->where('session_date', $data['session_date'])
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($data) {
                $q->whereBetween('start_time', [$data['start_time'], $data['end_time']])
                  ->orWhereBetween('end_time', [$data['start_time'], $data['end_time']])
                  ->orWhere(function ($q2) use ($data) {
                      $q2->where('start_time', '<=', $data['start_time'])
                         ->where('end_time', '>=', $data['end_time']);
                  });
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'status' => 'error',
                'message' => 'Coach already has a session during this time'
            ], 409);
        }

        try {
            $session = DB::transaction(function () use (
                $coachId,
                $studentId,
                $data,
                $totalAmount
            ) {
                return TrainingSession::create([
                    'coach_id'     => $coachId,
                    'student_id'   => $studentId,
                    'event_id'     => $data['event_id'] ?? null,
                    'venue_id'     => $data['venue_id'] ?? null,
                    'sport'        => $data['sport'],
                    'session_date' => $data['session_date'],
                    'start_time'   => $data['start_time'],
                    'end_time'     => $data['end_time'],
                    'status'       => 'pending',
                    'hourly_rate'  => $data['hourly_rate'],
                    'total_amount' => $totalAmount,
                    'notes'        => $data['notes'] ?? null,
                ]);
            });

            Log::info('Training session created', [
                'session_id' => $session->id
            ]);

            return response()->json([
                'status' => 'success',
                'session' => $session
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Training session creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Could not create training session'
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $userId = Auth::id();

        $q = TrainingSession::where(function ($q) use ($userId) {
            $q->where('student_id', $userId)->orWhere('coach_id', $userId);
        })->with(['coach:id,first_name,last_name', 'student:id,first_name,last_name']);

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('from')) {
            $q->whereDate('session_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('session_date', '<=', $request->input('to'));
        }

        $sessions = $q->orderByDesc('session_date')->paginate($request->input('per_page', 20));
        return response()->json($sessions);
    }

    public function getUpcoming()
    {
        $userId = Auth::id();
        $today = Carbon::today()->toDateString();

        $sessions = TrainingSession::with(['coach:id,first_name,last_name', 'student:id,first_name,last_name'])
            ->where(function ($q) use ($userId) {
                $q->where('student_id', $userId)->orWhere('coach_id', $userId);
            })
            ->whereDate('session_date', '>=', $today)
            ->whereIn('status', ['pending', 'confirmed'])
            ->orderBy('session_date')
            ->get();

        return response()->json(['status' => 'success', 'upcoming' => $sessions]);
    }

    public function getPending()
    {
        $coachId = Auth::id();

        if (! CoachProfile::where('user_id', $coachId)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $sessions = TrainingSession::with('student:id,first_name,last_name')
            ->where('coach_id', $coachId)
            ->where('status', 'pending')
            ->orderBy('session_date')
            ->get();

        return response()->json(['status' => 'success', 'pending' => $sessions]);
    }

    public function accept(Request $request, $sessionId)
    {
        $coachId = Auth::id();

        $session = TrainingSession::where('id', $sessionId)->where('coach_id', $coachId)->first();
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found or unauthorized'], 404);
        }
        if ($session->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Only pending sessions can be accepted'], 400);
        }

        $session->status = 'confirmed';
        $session->confirmed_at = now();

        if ($request->filled('hourly_rate')) {
            $rateInput = (float) $request->input('hourly_rate');
            if ($rateInput < 0) {
                return response()->json(['status' => 'error', 'message' => 'hourly_rate must be >= 0'], 422);
            }
            $session->hourly_rate = $rateInput;
        }

        // ensure stored hourly_rate is numeric and non-negative
        $session->hourly_rate = max(0, (float) $session->hourly_rate);

        if ($session->hourly_rate && $session->start_time && $session->end_time) {
            Log::info('Calculating total_amount', [
                'session_date' => $session->session_date,
                'start_time_raw' => $session->start_time,
                'end_time_raw' => $session->end_time,
                'hourly_rate' => $session->hourly_rate,
            ]);

            // normalize date (Y-m-d) using Carbon to support ISO/datetime strings
            try {
                $date = Carbon::parse($session->session_date)->toDateString();
            } catch (\Throwable $e) {
                Log::error('Failed to parse session_date', ['session_date' => $session->session_date, 'error' => $e->getMessage()]);
                $date = date('Y-m-d', strtotime((string) $session->session_date));
            }

            // extract time part (H:i or H:i:s)
            $startTime = trim((string) $session->start_time);
            $endTime = trim((string) $session->end_time);
            if (preg_match('/^\d{2}:\d{2}$/', $startTime)) { $startTime .= ':00'; }
            if (preg_match('/^\d{2}:\d{2}$/', $endTime)) { $endTime .= ':00'; }

            try {
                $dateCarbon = Carbon::parse($session->session_date);
            } catch (\Throwable $e) {
                $dateCarbon = Carbon::createFromFormat('Y-m-d', date('Y-m-d', strtotime((string)$session->session_date)));
            }

            $startTime = trim((string) $session->start_time);
            $endTime = trim((string) $session->end_time);
            if (preg_match('/^\d{2}:\d{2}$/', $startTime)) { $startTime .= ':00'; }
            if (preg_match('/^\d{2}:\d{2}$/', $endTime)) { $endTime .= ':00'; }

            try {
                $start = $dateCarbon->copy()->setTimeFromTimeString($startTime);
                $end = $dateCarbon->copy()->setTimeFromTimeString($endTime);

                if ($end->lessThanOrEqualTo($start)) {
                    Log::warning('End time is not after start time', ['start' => $start->toDateTimeString(), 'end' => $end->toDateTimeString()]);
                    $minutes = 0;
                } else {
                    // compute minutes with start->diffInMinutes(end) to get positive value
                    $minutes = $start->diffInMinutes($end);
                }

                $hours = ($minutes ?? 0) / 60;
                $rate = max(0, (float) $session->hourly_rate);
                $session->total_amount = max(0, round($rate * $hours, 2));

                Log::info('Total amount computed', ['minutes' => $minutes, 'hours' => $hours, 'rate' => $rate, 'total' => $session->total_amount]);
            } catch (\Throwable $ex) {
                Log::error('Failed to parse session times for total_amount', ['error' => $ex->getMessage()]);
                $session->total_amount = 0;
            }
        }

        $session->save();
        return response()->json(['status' => 'success', 'session' => $session]);
    }

    public function reject(Request $request, $sessionId)
    {
        $coachId = Auth::id();

        $session = TrainingSession::where('id', $sessionId)->where('coach_id', $coachId)->first();
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found or unauthorized'], 404);
        }
        if ($session->status !== 'pending' && $session->status !== 'confirmed') {
            return response()->json(['status' => 'error', 'message' => 'Only pending or confirmed sessions can be rejected'], 400);
        }

        $session->status = 'cancelled';
        $session->cancellation_reason = $request->input('cancellation_reason', 'Rejected by coach');
        $session->save();

        return response()->json(['status' => 'success', 'session' => $session]);
    }

    public function cancel(Request $request, $sessionId)
    {
        $userId = Auth::id();

        $session = TrainingSession::find($sessionId);
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found'], 404);
        }
        if ($session->student_id !== $userId && $session->coach_id !== $userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }
        if (in_array($session->status, ['completed', 'no_show', 'cancelled'])) {
            return response()->json(['status' => 'error', 'message' => 'Cannot cancel this session'], 400);
        }

        $session->status = 'cancelled';
        $session->cancellation_reason = $request->input('cancellation_reason', 'Cancelled by user');
        $session->save();

        return response()->json(['status' => 'success', 'session' => $session]);
    }

    public function complete(Request $request, $sessionId)
    {
        $coachId = Auth::id();

        $session = TrainingSession::where('id', $sessionId)->where('coach_id', $coachId)->first();
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found or unauthorized'], 404);
        }
        if (! in_array($session->status, ['confirmed', 'pending'])) {
            return response()->json(['status' => 'error', 'message' => 'Only confirmed sessions can be completed'], 400);
        }

        $session->status = 'completed';
        $session->completed_at = now();

        if ((! $session->total_amount || $session->total_amount == 0) && $session->hourly_rate && $session->start_time && $session->end_time) {
            // ensure we only combine date (Y-m-d) + time
            $date = $session->session_date instanceof \Carbon\Carbon
                ? $session->session_date->toDateString()
                : date('Y-m-d', strtotime($session->session_date));

            $start = Carbon::parse($date . ' ' . $session->start_time);
            $end = Carbon::parse($date . ' ' . $session->end_time);
            $hours = max(0, $end->diffInMinutes($start) / 60);
            $session->total_amount = round($session->hourly_rate * $hours, 2);
        }

        $session->save();
        return response()->json(['status' => 'success', 'session' => $session]);
    }

    public function reschedule(Request $request, $sessionId)
    {
        $userId = Auth::id();

        $session = TrainingSession::find($sessionId);
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found'], 404);
        }
        if ($session->student_id !== $userId && $session->coach_id !== $userId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'session_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'notes' => 'nullable|string',
        ]);

        $session->session_date = $data['session_date'];
        $session->start_time = $data['start_time'];
        $session->end_time = $data['end_time'] ?? null;
        $session->notes = $data['notes'] ?? $session->notes;

        $session->status = 'pending';
        $session->confirmed_at = null;
        $session->completed_at = null;
        $session->save();

        return response()->json(['status' => 'success', 'session' => $session]);
    }
}
