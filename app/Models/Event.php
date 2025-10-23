<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'event_type',
        'sport',
        'venue_id',
        'facility_id',
        'slots',
        'date',
        'start_time',
        'end_time',
        'created_by',
        'checkin_code',
        'cancelled_at',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants()
    {
        return $this->hasMany(\App\Models\EventParticipant::class);
    }

    public function facility()
    {
        return $this->belongsTo(\App\Models\Facilities::class, 'facility_id');
    }

    public function teams()
    {
        return $this->hasMany(EventTeam::class);
    }

    public function isTeamBased()
    {
        return $this->event_type === 'team vs team';
    }

    public function scores()
    {
        return $this->hasMany(EventScore::class);
    }

    public function checkins()
    {
        return $this->hasMany(EventCheckin::class);
    }

    public function getStatusAttribute()
    {
        if ($this->cancelled_at) {
            return 'cancelled';
        }

        $now = now();
        $eventStart = \Carbon\Carbon::parse($this->date . ' ' . $this->start_time);
        $eventEnd = \Carbon\Carbon::parse($this->date . ' ' . $this->end_time);

        if ($now < $eventStart) {
            return 'upcoming';
        }

        if ($now >= $eventStart && $now <= $eventEnd) {
            return 'ongoing';
        }

        return 'completed';
    }

    public function isCompetitive()
    {
        return in_array($this->event_type, ['tournament', 'team vs team']);
    }
}