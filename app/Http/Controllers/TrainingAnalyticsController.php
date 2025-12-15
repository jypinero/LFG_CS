<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TrainingAnalyticsService;

class TrainingAnalyticsController extends Controller
{
    protected TrainingAnalyticsService $analytics;

    public function __construct(TrainingAnalyticsService $analytics)
    {
        $this->analytics = $analytics;
    }

    // Get analytics for a student
    public function getStudentAnalytics(Request $request, $studentId)
    {
        $period = $request->query('period', 30); // days
        $data = $this->analytics->calculateStudentMetrics((int)$studentId, (int)$period);
        return response()->json(['status' => 'success', 'analytics' => $data]);
    }

    // Get analytics for a coach
    public function getCoachAnalytics(Request $request, $coachId)
    {
        $period = $request->query('period', 30); // days
        $data = $this->analytics->calculateCoachMetrics((int)$coachId, (int)$period);
        return response()->json(['status' => 'success', 'analytics' => $data]);
    }

    // Force calculate / update analytics for a user
    public function calculateAnalytics($userId, $userType, $period = 30)
    {
        $userId = (int)$userId;
        $period = (int)$period;

        if ($userType === 'student') {
            $metrics = $this->analytics->calculateStudentMetrics($userId, $period);
        } else {
            $metrics = $this->analytics->calculateCoachMetrics($userId, $period);
        }

        $this->analytics->updateConsistencyScore($userId);
        $this->analytics->updateCompletionRate($userId);

        return response()->json(['status' => 'success', 'metrics' => $metrics]);
    }
}
