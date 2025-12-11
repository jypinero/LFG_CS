<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Leaderboard extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'user_id',
        'team_id',
        'rank',
        'wins',
        'losses',
        'draws',
        'points',
        'win_rate',
        'matches_played',
        'match_history',
        'stats',
    ];

    protected $casts = [
        'match_history' => 'array',
        'stats' => 'array',
        'win_rate' => 'decimal:2',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}