<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'venue_id',
        'user_id',
        'event_id',
        'date',
        'end_date',
        'start_time',
        'end_time',
        'status',
        'sport',
        'purpose',
        'cancelled_by',
        'end_date_start_time',
        'end_date_end_time',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function trainingSession()
    {
        return $this->hasOne(TrainingSession::class, 'booking_id');
    }
}