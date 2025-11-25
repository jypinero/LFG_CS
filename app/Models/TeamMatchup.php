<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamMatchup extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'team_a_id',
        'team_b_id',
        'match_stage',
        'scheduled_at',
        'winner_team_id',

        // tournament-related fields (added)
        'tournament_id',
        'round_number',
        'match_number',
        'team_a_score',
        'team_b_score',
        'status',
        'started_at',
        'completed_at',
        'notes',
        'penalties',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'team_a_score' => 'integer',
        'team_b_score' => 'integer',
        'penalties' => 'array',
    ];

    // relationships
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

    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }
}