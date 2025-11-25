<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentAnalytics extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'total_participants',
        'total_teams',
        'total_games',
        'completed_games',
        'no_shows',
        'average_rating',
        'total_ratings',
    ];

    protected $casts = [
        'average_rating' => 'decimal:2',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }
}
