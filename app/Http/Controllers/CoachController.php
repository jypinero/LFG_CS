<?php


namespace App\Http\Controllers;

use App\Models\CoachProfile;
use App\Models\CoachMatch;
use App\Models\TrainingSession;
use App\Models\CoachReview;
use App\Models\TrainingAnalytics;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CoachController extends Controller
{
    public function createProfile(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'bio' => 'nullable|string',
            'specializations' => 'nullable|array',
            'specializations.*' => 'string',
            'hourly_rate' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'availability' => 'nullable|array',
            'location' => 'nullable|array',
            'years_experience' => 'nullable|integer|min:0',
            'certifications' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $data['specializations'] = $data['specializations'] ?? null;
        $data['availability'] = $data['availability'] ?? null;
        $data['location'] = $data['location'] ?? null;
        $data['is_active'] = $data['is_active'] ?? true;

        $profile = CoachProfile::updateOrCreate(
            ['user_id' => $user->id],
            $data
        );

        return response()->json(['status' => 'success', 'profile' => $profile], 201);
    }

    public function getMyProfile()
    {
        $user = Auth::user();
        $profile = CoachProfile::with('user')->where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json(['status' => 'error', 'message' => 'Profile not found'], 404);
        }

        return response()->json(['status' => 'success', 'profile' => $profile]);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        $profile = CoachProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json(['status' => 'error', 'message' => 'Profile not found'], 404);
        }

        $data = $request->validate([
            'bio' => 'nullable|string',
            'specializations' => 'nullable|array',
            'specializations.*' => 'string',
            'hourly_rate' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'availability' => 'nullable|array',
            'location' => 'nullable|array',
            'years_experience' => 'nullable|integer|min:0',
            'certifications' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $profile->update($data);

        return response()->json(['status' => 'success', 'profile' => $profile]);
    }

    public function discover(Request $request)
    {
        $q = CoachProfile::query()->where('is_active', true);

        if ($request->filled('specialization')) {
            $q->whereJsonContains('specializations', $request->input('specialization'));
        }

        if ($request->filled('min_rate')) {
            $q->where('hourly_rate', '>=', (float) $request->input('min_rate'));
        }
        if ($request->filled('max_rate')) {
            $q->where('hourly_rate', '<=', (float) $request->input('max_rate'));
        }

        if ($request->filled('min_rating')) {
            $q->where('rating', '>=', (float) $request->input('min_rating'));
        }

        if ($request->filled('location_key') && $request->filled('location_value')) {
            // simple JSON key filtering: e.g. location_key=city location_value=London
            $q->whereJsonContains("location->{$request->input('location_key')}", $request->input('location_value'));
        }

        $perPage = $request->input('per_page', 15);
        $results = $q->orderByDesc('rating')->paginate($perPage);

        return response()->json($results);
    }

    public function getSwipeCard()
    {
        $studentId = Auth::id();

        $seenCoachIds = CoachMatch::where('student_id', $studentId)->pluck('coach_id')->toArray();

        $coach = CoachProfile::where('is_active', true)
            ->where('user_id', '!=', $studentId)
            ->whereNotIn('user_id', $seenCoachIds)
            ->orderByDesc('rating')
            ->with('user')
            ->first();

        if (! $coach) {
            return response()->json(['status' => 'success', 'card' => null]);
        }

        return response()->json(['status' => 'success', 'card' => $coach]);
    }

    public function show($coachId)
    {
        $coach = CoachProfile::with('user')->where('user_id', $coachId)->first();

        if (! $coach) {
            return response()->json(['status' => 'error', 'message' => 'Coach not found'], 404);
        }

        $stats = [
            'total_sessions' => TrainingSession::where('coach_id', $coachId)->count(),
            'completed_sessions' => TrainingSession::where('coach_id', $coachId)->where('status', 'completed')->count(),
            'average_rating' => round((float) CoachReview::where('coach_id', $coachId)->avg('rating'), 2),
            'total_reviews' => CoachReview::where('coach_id', $coachId)->count(),
        ];

        return response()->json(['status' => 'success', 'coach' => $coach, 'stats' => $stats]);
    }

    public function swipe(Request $request, $coachId)
    {
        $studentId = Auth::id();

        $data = $request->validate([
            'action' => 'required|in:like,pass,super_like',
        ]);

        if ($studentId == $coachId) {
            return response()->json(['status' => 'error', 'message' => 'Cannot swipe yourself'], 400);
        }

        $match = CoachMatch::firstOrNew(['student_id' => $studentId, 'coach_id' => $coachId]);
        $match->student_action = $data['action'];

        // decide resulting match_status
        if (in_array($match->student_action, ['like', 'super_like']) && in_array($match->coach_action, ['like', 'super_like'])) {
            $match->match_status = 'matched';
            $match->matched_at = $match->matched_at ?? now();
            $match->expires_at = $match->expires_at ?? now()->addDays(30);
        } elseif ($match->student_action === 'pass') {
            $match->match_status = 'rejected';
        } else {
            // leave pending unless symmetric like happens
            $match->match_status = $match->match_status ?? 'pending';
        }

        $match->save();

        return response()->json(['status' => 'success', 'match' => $match]);
    }

    public function getMatches()
    {
        $studentId = Auth::id();

        $matches = CoachMatch::where('student_id', $studentId)
            ->where('match_status', 'matched')
            ->with(['coach' => function ($q) { $q->select('id', 'first_name', 'last_name'); }])
            ->get();

        return response()->json(['status' => 'success', 'matches' => $matches]);
    }

    public function getPendingMatches()
    {
        $coachId = Auth::id();

        $matches = CoachMatch::where('coach_id', $coachId)
            ->where('match_status', 'pending')
            ->with(['student' => function ($q) { $q->select('id', 'first_name', 'last_name'); }])
            ->get();

        return response()->json(['status' => 'success', 'pending' => $matches]);
    }

    public function getStudents()
    {
        $coachId = Auth::id();

        $studentIds = CoachMatch::where('coach_id', $coachId)
            ->where('match_status', 'matched')
            ->pluck('student_id')
            ->unique()
            ->toArray();

        $students = User::whereIn('id', $studentIds)->select('id', 'first_name', 'last_name', 'email')->get();

        return response()->json(['status' => 'success', 'students' => $students]);
    }

    public function getDashboardSessions(Request $request)
    {
        $coachId = Auth::id();

        $q = TrainingSession::where('coach_id', $coachId);

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        if ($request->filled('start_date')) {
            $q->whereDate('session_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $q->whereDate('session_date', '<=', $request->input('end_date'));
        }

        if ($request->filled('sport')) {
            $q->where('sport', $request->input('sport'));
        }

        $perPage = $request->input('per_page', 15);
        $sessions = $q->orderByDesc('session_date')->paginate($perPage);

        return response()->json($sessions);
    }

    public function getAnalytics(Request $request)
    {
        $coachId = Auth::id();

        $baseQuery = TrainingSession::where('coach_id', $coachId);

        if ($request->filled('period_start')) {
            $baseQuery->whereDate('session_date', '>=', $request->input('period_start'));
        }
        if ($request->filled('period_end')) {
            $baseQuery->whereDate('session_date', '<=', $request->input('period_end'));
        }

        $totalSessions = (clone $baseQuery)->count();
        $completedSessions = (clone $baseQuery)->where('status', 'completed')->count();
        $cancelledSessions = (clone $baseQuery)->where('status', 'cancelled')->count();

        // total_hours_trained - sum duration in hours where end_time present
        $hours = (clone $baseQuery)
            ->whereNotNull('end_time')
            ->get()
            ->reduce(function ($carry, $s) {
                if ($s->end_time && $s->start_time) {
                    $start = Carbon::parse($s->session_date . ' ' . $s->start_time);
                    $end = Carbon::parse($s->session_date . ' ' . $s->end_time);
                    $minutes = max(0, $end->diffInMinutes($start));
                    return $carry + ($minutes / 60);
                }
                return $carry;
            }, 0);

        $avgDuration = $completedSessions > 0 ? round($hours / $completedSessions, 2) : 0;

        $totalRevenue = (clone $baseQuery)->whereNotNull('total_amount')->sum('total_amount');

        $averageRating = CoachReview::where('coach_id', $coachId)
            ->when($request->filled('period_start'), fn($q) => $q->whereDate('created_at', '>=', $request->input('period_start')))
            ->when($request->filled('period_end'), fn($q) => $q->whereDate('created_at', '<=', $request->input('period_end')))
            ->avg('rating');

        $analytics = [
            'total_sessions' => $totalSessions,
            'completed_sessions' => $completedSessions,
            'cancelled_sessions' => $cancelledSessions,
            'total_hours_trained' => round($hours, 2),
            'average_session_duration' => round($avgDuration, 2),
            'total_revenue' => round((float)$totalRevenue, 2),
            'average_rating_received' => $averageRating ? round((float)$averageRating, 2) : null,
        ];

        // Persist or update training_analytics for this user/period if requested
        if ($request->boolean('persist', false) && $request->filled('period_start') && $request->filled('period_end')) {
            TrainingAnalytics::updateOrCreate(
                ['user_id' => $coachId, 'user_type' => 'coach', 'period_start' => $request->input('period_start')],
                array_merge($analytics, [
                    'period_end' => $request->input('period_end'),
                    'current_streak_days' => 0,
                    'longest_streak_days' => 0,
                ])
            );
        }

        return response()->json(['status' => 'success', 'analytics' => $analytics]);
    }
}