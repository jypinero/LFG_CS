<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentWaitlist extends Model
{
    use HasFactory;

    protected $table = 'tournament_waitlist';

    protected $fillable = [
        'tournament_id',
        'user_id',
        'team_id',
        'participant_type',
        'position',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
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






















