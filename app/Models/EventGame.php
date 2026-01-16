<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventGame extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_uuid',
        'event_id',
        'tournament_id',
        'round_number',
        'match_number',
        'match_stage',
        'team_a_id',
        'team_b_id',
        'user_a_id',
        'user_b_id',
        'score_a',
        'score_b',
        'winner_team_id',
        'winner_user_id',
        'challonge_match_id',
        'challonge_match_url',
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

    public function team_a()
    {
        return $this->belongsTo(Team::class, 'team_a_id');
    }

    public function team_b()
    {
        return $this->belongsTo(Team::class, 'team_b_id');
    }

    public function user_a()
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    public function user_b()
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }
}
