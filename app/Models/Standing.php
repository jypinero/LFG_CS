<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Standing extends Model
{
    use HasFactory;

    protected $table = 'standings';
    protected $fillable = [
        'tournament_id',
        'team_id',
        'user_id',
        'wins',
        'losses',
        'draws',
        'points',
        'win_rate',
        'rank',
    ];

    protected $casts = [
        'win_rate' => 'decimal:2',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
