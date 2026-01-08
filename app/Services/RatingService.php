<?php
// app/Services/RatingService.php

namespace App\Services;

use App\Models\User;

class RatingService
{
    public static function updateUserRating(int $userId, int $newRating): void
    {
        $user = User::findOrFail($userId);

        $oldCount = $user->rating_count;
        $oldScore = $user->rating_score;

        $newCount = $oldCount + 1;
        $newScore = (($oldScore * $oldCount) + $newRating) / $newCount;
        $newStar = (int) round($newScore);

        $user->update([
            'rating_score' => round($newScore, 2),
            'rating_count' => $newCount,
            'rating_star' => $newStar,
        ]);
    }
}
