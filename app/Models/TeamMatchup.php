<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamMatchup extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'team_a_id', 'team_b_id', 'match_stage', 'scheduled_at', 'winner_team_id'
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function teamA()
    {
        return $this->belongsTo(Team::class, 'team_a_id');
    }

    public function teamB()
    {
        return $this->belongsTo(Team::class, 'team_b_id');
    }

    public function winner()
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }
} 