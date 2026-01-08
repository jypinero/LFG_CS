<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class TrainingSession extends Model
{
    use HasFactory;

    protected $table = 'training_sessions';

    protected $fillable = [
        'coach_id',
        'student_id',
        'event_id',
        'venue_id',
        'facility_id',
        'booking_id',
        'sport',
        'session_date',
        'start_time',
        'end_time',
        'status',
        'hourly_rate',
        'total_amount',
        'notes',
        'cancellation_reason',
        'confirmed_at',
        'completed_at',
    ];

    protected $casts = [
        'session_date' => 'date',
        // fix typo and avoid invalid cast - use string (or 'time' if you use a custom cast)
        'start_time' => 'string',
        'end_time' => 'string',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'hourly_rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    // add this so $session->scheduled_at is available (not a DB column)
    protected $appends = ['scheduled_at'];

    public function getScheduledAtAttribute()
    {
        if (! $this->session_date) {
            return null;
        }

        $time = $this->start_time ?: '00:00:00';
        // ensure H:i:s format
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time .= ':00';
        }

        try {
            return Carbon::parse($this->session_date->toDateString() . ' ' . $time);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function facility()
    {
        return $this->belongsTo(Facilities::class, 'facility_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}