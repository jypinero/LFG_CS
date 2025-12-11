<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamMatchup extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id','event_id','round_number','match_number','match_stage',
        'team_a_id','team_b_id','winner_team_id','status',
        'team_a_score','team_b_score','scheduled_at','started_at','completed_at',
        'notes','penalties','meta','next_match_id','loser_next_match_id'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'penalties' => 'array',
        'meta' => 'array',
        'team_a_score' => 'integer',
        'team_b_score' => 'integer',
    ];

    public function tournament() { return $this->belongsTo(\App\Models\Tournament::class, 'tournament_id'); }
    public function event() { return $this->belongsTo(\App\Models\Event::class, 'event_id'); }
    public function teamA() { return $this->belongsTo(\App\Models\Team::class, 'team_a_id'); }
    public function teamB() { return $this->belongsTo(\App\Models\Team::class, 'team_b_id'); }
    public function winner() { return $this->belongsTo(\App\Models\Team::class, 'winner_team_id'); }
    public function nextMatch() { return $this->belongsTo(self::class, 'next_match_id'); }
    public function loserNextMatch() { return $this->belongsTo(self::class, 'loser_next_match_id'); }
}