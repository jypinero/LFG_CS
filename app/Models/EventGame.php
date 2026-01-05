<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventGame extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'tournament_id',
        'round_number',
        'match_number',
        'team_a_id',
        'team_b_id',
        'user_a_id',
        'user_b_id',
        'score_a',
        'score_b',
        'winner_team_id',
        'winner_user_id',
        'game_date',
        'start_time',
        'end_time',
        'status',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }
}
