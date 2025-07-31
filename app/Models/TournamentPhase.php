<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentPhase extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'phase_name', 'order'
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
} 