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
}