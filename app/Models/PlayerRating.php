<?php

// app/Models/PlayerRating.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerRating extends Model
{
    protected $table = 'player_ratings';

     protected $fillable = [
        'event_id',
        'rater_user_id',
        'rated_user_id',
        'rating',
        'comment',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function rater()
    {
        return $this->belongsTo(User::class, 'rater_user_id');
    }

    public function rated()
    {
        return $this->belongsTo(User::class, 'rated_user_id');
    }
}
