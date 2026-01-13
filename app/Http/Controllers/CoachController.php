<?php


namespace App\Http\Controllers;

use App\Models\CoachProfile;
use App\Models\CoachMatch;
use App\Models\TrainingSession;
use App\Models\CoachReview;
use App\Models\TrainingAnalytics;
use App\Models\User;
use App\Models\Notification;
use App\Models\UserNotification;
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
        $authId = Auth::id();
        $authIsCoach = $authId && CoachProfile::where('user_id', $authId)->exists();

        $q = CoachProfile::query()->where('is_active', true);

        // if requester is a coach, exclude their own profile from listings
        if ($authIsCoach) {
            $q->where('user_id', '!=', $authId);
        } else {
            // if requester is a student, exclude coaches they've already matched with (active matches)
            if ($authId) {
                $matchedCoachIds = CoachMatch::where('student_id', $authId)
                    ->where('match_status', 'matched')
                    ->where(function ($sub) {
                        $sub->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->pluck('coach_id')
                    ->toArray();

                if (!empty($matchedCoachIds)) {
                    $q->whereNotIn('user_id', $matchedCoachIds);
                }
            }
        }

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
            $q->whereJsonContains("location->{$request->input('location_key')}", $request->input('location_value'));
        }

        $perPage = $request->input('per_page', 15);
        $results = $q->orderByDesc('rating')->paginate($perPage);

        // Add profile photo URLs to user objects
        $results->getCollection()->transform(function($coach) {
            if ($coach->user && $coach->user->profile_photo) {
                $coach->user->profile_photo_url = \Storage::url($coach->user->profile_photo);
            }
            return $coach;
        });

        return response()->json($results);
    }

    public function getSwipeCard()
    {
        $studentId = Auth::id();

        // Exclude expired matches from seen list (they can be shown again)
        $seenCoachIds = CoachMatch::where('student_id', $studentId)
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->pluck('coach_id')
            ->toArray();

        $q = CoachProfile::where('is_active', true)
            ->whereNotIn('user_id', $seenCoachIds);

        // if requester is a coach, ensure they don't see their own profile
        if ($studentId && CoachProfile::where('user_id', $studentId)->exists()) {
            $q->where('user_id', '!=', $studentId);
        }

        $coach = $q->orderByDesc('rating')
            ->with('user')
            ->first();

        if (! $coach) {
            return response()->json(['status' => 'success', 'card' => null]);
        }

        // Add profile photo URL to user object
        if ($coach->user) {
            $coach->user->profile_photo_url = $coach->user->profile_photo 
                ? \Storage::url($coach->user->profile_photo) 
                : null;
        }

        return response()->json(['status' => 'success', 'card' => $coach]);
    }

    public function show($coachId)
    {
        $coach = CoachProfile::with('user')->where('user_id', $coachId)->first();

        if (! $coach) {
            return response()->json(['status' => 'error', 'message' => 'Coach not found'], 404);
        }

        // Add profile photo URL to user object
        if ($coach->user) {
            $coach->user->profile_photo_url = $coach->user->profile_photo 
                ? \Storage::url($coach->user->profile_photo) 
                : null;
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
        $wasMatched = $match->match_status === 'matched';
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

        // Get student user for notification
        $student = User::find($studentId);
        $coach = User::find($coachId);

        // Notify coach when student likes/swipes (if not a pass)
        if (in_array($match->student_action, ['like', 'super_like'])) {
            $notification = Notification::create([
                'type' => 'coach_liked',
                'data' => [
                    'message' => ($student->username ?? 'Someone') . ' liked your coach profile',
                    'coach_id' => $coachId,
                    'student_id' => $studentId,
                    'match_id' => $match->id,
                    'action' => $match->student_action,
                ],
                'created_by' => $studentId,
            ]);

            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $coachId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);
        }

        // Notify both parties when mutual match occurs
        if ($match->match_status === 'matched' && !$wasMatched) {
            $matchNotification = Notification::create([
                'type' => 'coach_match',
                'data' => [
                    'message' => 'You have a new match! ' . ($student->username ?? 'Student') . ' and ' . ($coach->username ?? 'Coach') . ' matched.',
                    'coach_id' => $coachId,
                    'student_id' => $studentId,
                    'match_id' => $match->id,
                ],
                'created_by' => $studentId,
            ]);

            // Notify student
            UserNotification::create([
                'notification_id' => $matchNotification->id,
                'user_id' => $studentId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);

            // Notify coach
            UserNotification::create([
                'notification_id' => $matchNotification->id,
                'user_id' => $coachId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);
        }

        return response()->json(['status' => 'success', 'match' => $match]);
    }

    public function getMatches()
    {
        $studentId = Auth::id();
        $perPage = min((int) request()->input('per_page', 5), 100);
        $page = (int) request()->input('page', 1);

        $q = CoachMatch::where('student_id', $studentId)
            ->where('match_status', 'matched')
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->with(['coach' => function ($q) {
                $q->select('id', 'first_name', 'last_name', 'username', 'profile_photo');
            }])
            ->orderByDesc('matched_at');

        $matches = $q->paginate($perPage, ['*'], 'page', $page);

        // Add profile photo URLs
        $matches->getCollection()->transform(function($match) {
            if ($match->coach) {
                $match->coach->profile_photo_url = $match->coach->profile_photo
                    ? \Storage::url($match->coach->profile_photo)
                    : null;
            }
            return $match;
        });

        return response()->json(['status' => 'success', 'matches' => $matches]);
    }

    public function getPendingMatches()
    {
        $coachId = Auth::id();

        $matches = CoachMatch::where('coach_id', $coachId)
            ->where('match_status', 'pending')
            ->with(['student' => function ($q) { 
                $q->select('id', 'first_name', 'last_name', 'username', 'profile_photo'); 
            }])
            ->get();

        // Add profile photo URLs
        $matches->each(function($match) {
            if ($match->student) {
                $match->student->profile_photo_url = $match->student->profile_photo 
                    ? \Storage::url($match->student->profile_photo) 
                    : null;
            }
        });

        return response()->json(['status' => 'success', 'pending' => $matches]);
    }

    public function respondToMatch(Request $request, $matchId)
    {
        $coachId = Auth::id();

        // Find the match - must belong to this coach and be pending
        $match = CoachMatch::where('id', $matchId)
            ->where('coach_id', $coachId)
            ->where('match_status', 'pending')
            ->first();

        if (!$match) {
            return response()->json([
                'status' => 'error',
                'message' => 'Match not found or already processed'
            ], 404);
        }

        // Validate action
        $data = $request->validate([
            'action' => 'required|in:like,pass,super_like',
        ]);

        // Update coach action
        $wasMatched = $match->match_status === 'matched';
        $match->coach_action = $data['action'];

        // Determine match status
        if (in_array($match->student_action, ['like', 'super_like']) && 
            in_array($match->coach_action, ['like', 'super_like'])) {
            // Both liked - it's a match!
            $match->match_status = 'matched';
            $match->matched_at = now();
            $match->expires_at = now()->addDays(30); // 30 day expiration
        } elseif ($match->coach_action === 'pass') {
            // Coach passed - reject
            $match->match_status = 'rejected';
        }

        $match->save();

        // Get users for notifications
        $student = User::find($match->student_id);
        $coach = User::find($coachId);

        // Notify student when coach responds
        $responseNotification = Notification::create([
            'type' => 'coach_match_response',
            'data' => [
                'message' => ($coach->username ?? 'Coach') . ' ' . ($match->coach_action === 'pass' ? 'passed' : 'liked') . ' your match request',
                'coach_id' => $coachId,
                'student_id' => $match->student_id,
                'match_id' => $match->id,
                'action' => $match->coach_action,
            ],
            'created_by' => $coachId,
        ]);

        UserNotification::create([
            'notification_id' => $responseNotification->id,
            'user_id' => $match->student_id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);

        // Notify both parties when mutual match occurs
        if ($match->match_status === 'matched' && !$wasMatched) {
            $matchNotification = Notification::create([
                'type' => 'coach_match',
                'data' => [
                    'message' => 'You have a new match! ' . ($student->username ?? 'Student') . ' and ' . ($coach->username ?? 'Coach') . ' matched.',
                    'coach_id' => $coachId,
                    'student_id' => $match->student_id,
                    'match_id' => $match->id,
                ],
                'created_by' => $coachId,
            ]);

            // Notify student
            UserNotification::create([
                'notification_id' => $matchNotification->id,
                'user_id' => $match->student_id,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);

            // Notify coach
            UserNotification::create([
                'notification_id' => $matchNotification->id,
                'user_id' => $coachId,
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'match' => $match
        ]);
    }

    public function getStudents()
    {
        $coachId = Auth::id();

        // Get active (non-expired) matches
        $matches = CoachMatch::where('coach_id', $coachId)
            ->where('match_status', 'matched')
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->with('student:id,first_name,last_name,username,email,profile_photo')
            ->get();

        // Get unique student IDs
        $studentIds = $matches->pluck('student_id')->unique()->toArray();

        $students = User::whereIn('id', $studentIds)
            ->select('id', 'first_name', 'last_name', 'username', 'email', 'profile_photo', 'city', 'province')
            ->with('userProfile:id,user_id,bio,main_sport_id')
            ->get()
            ->map(function($student) use ($coachId) {
                // Add profile photo URL
                $student->profile_photo_url = $student->profile_photo 
                    ? \Storage::url($student->profile_photo) 
                    : null;

                // Get session statistics for this student
                $sessions = TrainingSession::where('coach_id', $coachId)
                    ->where('student_id', $student->id)
                    ->get();

                $student->session_stats = [
                    'total_sessions' => $sessions->count(),
                    'completed_sessions' => $sessions->where('status', 'completed')->count(),
                    'pending_sessions' => $sessions->where('status', 'pending')->count(),
                    'confirmed_sessions' => $sessions->where('status', 'confirmed')->count(),
                    'cancelled_sessions' => $sessions->where('status', 'cancelled')->count(),
                ];

                // Get match info
                $match = CoachMatch::where('coach_id', $coachId)
                    ->where('student_id', $student->id)
                    ->where('match_status', 'matched')
                    ->first();

                $student->matched_at = $match->matched_at ?? null;
                $student->expires_at = $match->expires_at ?? null;
                $student->is_match_active = $match && (!$match->expires_at || $match->expires_at > now());

                return $student;
            });

        return response()->json(['status' => 'success', 'students' => $students]);
    }

    /**
     * Get detailed information about a specific student
     */
    public function getStudentDetail($studentId)
    {
        $coachId = Auth::id();

        // Verify match exists and is active
        $match = CoachMatch::where('coach_id', $coachId)
            ->where('student_id', $studentId)
            ->where('match_status', 'matched')
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$match) {
            return response()->json([
                'status' => 'error',
                'message' => 'Student not found or match expired'
            ], 404);
        }

        $student = User::with([
            'userProfile.mainSport',
            'userAdditionalSports.sport',
            'userProfile:id,user_id,bio,occupation,main_sport_id,main_sport_level'
        ])
        ->find($studentId);

        if (!$student) {
            return response()->json([
                'status' => 'error',
                'message' => 'Student not found'
            ], 404);
        }

        // Add profile photo URL
        $student->profile_photo_url = $student->profile_photo 
            ? \Storage::url($student->profile_photo) 
            : null;

        // Get all sessions with this student
        $sessions = TrainingSession::where('coach_id', $coachId)
            ->where('student_id', $studentId)
            ->orderByDesc('session_date')
            ->get();

        // Calculate session statistics
        $sessionStats = [
            'total_sessions' => $sessions->count(),
            'completed_sessions' => $sessions->where('status', 'completed')->count(),
            'pending_sessions' => $sessions->where('status', 'pending')->count(),
            'confirmed_sessions' => $sessions->where('status', 'confirmed')->count(),
            'cancelled_sessions' => $sessions->where('status', 'cancelled')->count(),
            'total_revenue' => round($sessions->where('status', 'completed')->sum('total_amount'), 2),
        ];

        // Calculate total hours trained
        $totalHours = $sessions->where('status', 'completed')
            ->filter(function($s) {
                return $s->start_time && $s->end_time;
            })
            ->reduce(function($carry, $s) {
                try {
                    $start = Carbon::parse($s->session_date . ' ' . $s->start_time);
                    $end = Carbon::parse($s->session_date . ' ' . $s->end_time);
                    $minutes = max(0, $end->diffInMinutes($start));
                    return $carry + ($minutes / 60);
                } catch (\Exception $e) {
                    return $carry;
                }
            }, 0);

        $sessionStats['total_hours_trained'] = round($totalHours, 2);

        // Get upcoming sessions
        $upcomingSessions = $sessions->filter(function($s) {
            return in_array($s->status, ['pending', 'confirmed']) 
                && Carbon::parse($s->session_date)->isFuture();
        })->values();

        // Get recent sessions (last 5)
        $recentSessions = $sessions->where('status', 'completed')
            ->take(5)
            ->values();

        return response()->json([
            'status' => 'success',
            'student' => $student,
            'match' => [
                'matched_at' => $match->matched_at,
                'expires_at' => $match->expires_at,
                'is_active' => !$match->expires_at || $match->expires_at > now(),
            ],
            'session_stats' => $sessionStats,
            'upcoming_sessions' => $upcomingSessions,
            'recent_sessions' => $recentSessions,
        ]);
    }

    public function getDashboardSessions(Request $request)
    {
        $coachId = Auth::id();

        $q = TrainingSession::where('coach_id', $coachId)
            ->with(['student:id,first_name,last_name,username,profile_photo']);

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

        if ($request->filled('student_id')) {
            $q->where('student_id', $request->input('student_id'));
        }

        $perPage = $request->input('per_page', 15);
        $sessions = $q->orderByDesc('session_date')->paginate($perPage);

        // Add profile photo URLs to student data
        $sessions->getCollection()->transform(function($session) {
            if ($session->student && $session->student->profile_photo) {
                $session->student->profile_photo_url = \Storage::url($session->student->profile_photo);
            }
            return $session;
        });

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