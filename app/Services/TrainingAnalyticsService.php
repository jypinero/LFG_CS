<?php


namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TrainingAnalyticsService
{
    public function calculateStudentMetrics(int $userId, int $periodDays = 30): array
    {
        $since = Carbon::now()->subDays($periodDays)->startOfDay();

        $totalSessions = DB::table('training_sessions')
            ->where('student_id', $userId)
            ->whereDate('session_date', '>=', $since->toDateString())
            ->count();

        $completedSessions = DB::table('training_sessions')
            ->where('student_id', $userId)
            ->where('status', 'completed')
            ->whereDate('session_date', '>=', $since->toDateString())
            ->count();

        $avgCoachRating = DB::table('coach_reviews')
            ->where('student_id', $userId)
            ->where('created_at', '>=', $since)
            ->avg('rating') ?? 0;

        $completionRate = $totalSessions ? round($completedSessions / $totalSessions * 100, 2) : 0;

        return [
            'total_sessions' => $totalSessions,
            'completed_sessions' => $completedSessions,
            'avg_coach_rating' => round((float)$avgCoachRating, 2),
            'completion_rate_percent' => $completionRate,
        ];
    }

    public function calculateCoachMetrics(int $userId, int $periodDays = 30): array
    {
        $since = Carbon::now()->subDays($periodDays)->startOfDay();

        $totalSessions = DB::table('training_sessions')
            ->where('coach_id', $userId)
            ->whereDate('session_date', '>=', $since->toDateString())
            ->count();

        $deliveredSessions = DB::table('training_sessions')
            ->where('coach_id', $userId)
            ->where('status', 'completed')
            ->whereDate('session_date', '>=', $since->toDateString())
            ->count();

        $avgRating = DB::table('coach_reviews')
            ->where('coach_id', $userId)
            ->where('created_at', '>=', $since)
            ->avg('rating') ?? 0;

        $completionRate = $totalSessions ? round($deliveredSessions / $totalSessions * 100, 2) : 0;

        return [
            'total_sessions' => $totalSessions,
            'delivered_sessions' => $deliveredSessions,
            'avg_rating' => round((float)$avgRating, 2),
            'completion_rate_percent' => $completionRate,
        ];
    }

    public function updateConsistencyScore(int $userId): int
    {
        $dates = DB::table('training_sessions')
            ->where(function ($q) use ($userId) {
                $q->where('student_id', $userId)->orWhere('coach_id', $userId);
            })
            ->whereNotNull('session_date')
            ->orderBy('session_date', 'desc')
            ->pluck('session_date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->values()
            ->toArray();

        $streak = 0;
        $current = Carbon::today();

        foreach ($dates as $date) {
            if ($date === $current->toDateString()) {
                $streak++;
                $current = $current->subDay();
            } elseif (Carbon::parse($date)->lt($current)) {
                break;
            }
        }

        DB::table('user_analytics')->updateOrInsert(
            ['user_id' => $userId],
            ['consistency_streak' => $streak, 'updated_at' => Carbon::now()]
        );

        return $streak;
    }

    public function updateCompletionRate(int $userId): float
    {
        $total = DB::table('training_sessions')
            ->where(function ($q) use ($userId) {
                $q->where('student_id', $userId)->orWhere('coach_id', $userId);
            })
            ->whereNotNull('session_date')
            ->count();

        $completed = DB::table('training_sessions')
            ->where(function ($q) use ($userId) {
                $q->where('student_id', $userId)->orWhere('coach_id', $userId);
            })
            ->where('status', 'completed')
            ->whereNotNull('session_date')
            ->count();

        $rate = $total ? round($completed / $total * 100, 2) : 0.0;

        DB::table('user_analytics')->updateOrInsert(
            ['user_id' => $userId],
            ['completion_rate_percent' => $rate, 'updated_at' => Carbon::now()]
        );

        return $rate;
    }
}