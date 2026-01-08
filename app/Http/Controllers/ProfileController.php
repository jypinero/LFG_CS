<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

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

}
