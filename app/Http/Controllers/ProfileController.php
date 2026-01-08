<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    
    public function show($userId)
    {
        $user = User::findOrFail($userId);

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'rating_score' => $user->rating_score,
            'rating_star' => $user->rating_star,
            'rating_count' => $user->rating_count,
            // other user profile fields
        ]);
    }

}
