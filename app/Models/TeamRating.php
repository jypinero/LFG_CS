<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'rater_team_id',
        'rated_team_id',
        'rating',
        'comment',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function raterTeam()
    {
        return $this->belongsTo(Team::class, 'rater_team_id');
    }

    public function ratedTeam()
    {
        return $this->belongsTo(Team::class, 'rated_team_id');
    }
}
