<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\PlayerRating;

class ProfileController extends Controller
{

    public function show($userId)
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $user->id,
                'username' => $user->username,
                'rating_score' => $user->rating_score,
                'rating_star' => $user->rating_star,
                'rating_count' => $user->rating_count,
                // other user profile fields
            ]
        ]);
    }

    /**
     * Get player ratings for a user profile
     * Shows all ratings received by the user with rater information
     * Works for both own profile and viewing others' profiles
     * Accepts username as parameter (matches /profile/{username} route)
     */
    public function getRatings($username, Request $request)
    {
        $user = User::where('username', $username)->first();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 1);

        // Get ratings where this user was rated
        $ratings = PlayerRating::where('rated_user_id', $user->id)
            ->with(['rater', 'event'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $ratingsData = $ratings->map(function ($rating) {
            $rater = $rating->rater;
            $event = $rating->event;

            return [
                'id' => $rating->id,
                'rating' => $rating->rating,
                'comment' => $rating->comment,
                'created_at' => $rating->created_at,
                'rater' => $rater ? [
                    'id' => $rater->id,
                    'username' => $rater->username,
                    'first_name' => $rater->first_name,
                    'last_name' => $rater->last_name,
                    'profile_photo' => $rater->profile_photo ? \Storage::url($rater->profile_photo) : null,
                ] : null,
                'event' => $event ? [
                    'id' => $event->id,
                    'name' => $event->name,
                    'date' => $event->date,
                    'sport' => $event->sport,
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'ratings' => $ratingsData,
                'pagination' => [
                    'current_page' => $ratings->currentPage(),
                    'last_page' => $ratings->lastPage(),
                    'per_page' => $ratings->perPage(),
                    'total' => $ratings->total(),
                    'from' => $ratings->firstItem(),
                    'to' => $ratings->lastItem(),
                ],
                'summary' => [
                    'total_ratings' => $user->rating_count ?? 0,
                    'average_rating' => $user->rating_score ?? null,
                    'rating_star' => $user->rating_star ?? null,
                ],
            ],
        ]);
    }

}
