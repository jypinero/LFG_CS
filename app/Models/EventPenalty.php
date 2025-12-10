<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventPenalty extends Model
{
    use HasFactory;

    protected $table = 'event_penalties';

    protected $fillable = [
        'event_id',
        'issued_by', // user id who issued
        'target_user_id', // optional (player)
        'target_team_id', // optional
        'penalty_data', // json
        'note',
    ];

    protected $casts = [
        'penalty_data' => 'array',
    ];

    public function event() { return $this->belongsTo(Event::class); }
    public function issuer() { return $this->belongsTo(\App\Models\User::class, 'issued_by'); }
}
