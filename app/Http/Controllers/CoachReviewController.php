<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\TrainingSession;
use App\Models\CoachReview;

class CoachReviewController extends Controller
{
    /**
     * Create a review for a completed session.
     */
    public function create(Request $request, $sessionId)
    {
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $session = TrainingSession::find($sessionId);
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found'], 404);
        }

        if ($session->status !== 'completed') {
            return response()->json(['status' => 'error', 'message' => 'Can only review completed sessions'], 400);
        }

        $userId = Auth::id();
        if ($session->student_id !== $userId) {
            return response()->json(['status' => 'error', 'message' => 'Only the student who attended can leave a review'], 403);
        }

        // prevent duplicate review for same session
        if (CoachReview::where('training_session_id', $sessionId)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Review already exists for this session'], 409);
        }

        $review = CoachReview::create([
            'coach_id' => $session->coach_id,
            'student_id' => $userId,
            'training_session_id' => $sessionId,
            'rating' => $data['rating'],
            'review' => $data['comment'] ?? null,
        ]);

        return response()->json(['status' => 'success', 'review' => $review], 201);
    }

    /**
     * Get all reviews for a coach.
     */
    public function getCoachReviews($coachId)
    {
        // avoid selecting a non-existent `name` column — load full student relation instead
        $reviews = CoachReview::where('coach_id', $coachId)
            ->with('student') 
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['status' => 'success', 'reviews' => $reviews]);
    }
}
