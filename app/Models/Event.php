<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

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
        'is_approved',
        'approved_at',
        // tournament-related fields
        'tournament_id',
        'game_number',
        'game_status',
        'is_tournament_game',
        // add new audit fields:
        'approved_by',
        'cancelled_by',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
        'date' => 'date',
        'start_time' => 'string',
        'end_time' => 'string',
        'is_tournament_game' => 'boolean',
    ];

    // include computed status in arrays / JSON
    protected $appends = ['status'];

    /**
     * Accessor for computed status.
     * Accepts the raw $value (if column 'status' exists) to avoid recursion.
     */
    public function getStatusAttribute($value)
    {
        // If DB has explicit status column value, return it
        if (! is_null($value)) {
            return $value;
        }

        // Fallback: check raw attributes array (safe, avoids accessor)
        if (array_key_exists('status', $this->attributes) && ! is_null($this->attributes['status'])) {
            return $this->attributes['status'];
        }

        // Cancelled override
        if (! empty($this->cancelled_at)) {
            return 'cancelled';
        }

        // If scheduled_at present, use it to determine state
        if (! empty($this->scheduled_at)) {
            $now = now();
            $start = \Carbon\Carbon::parse($this->scheduled_at);
            $end = $start->copy()->addHours(3);

            if ($now->lt($start)) {
                return 'upcoming';
            }
            if ($now->between($start, $end)) {
                return 'ongoing';
            }
            return 'completed';
        }

        // Fallback to date + start_time / end_time logic
        if (! empty($this->date) && ! empty($this->start_time) && ! empty($this->end_time)) {
            $now = now();

            // normalize date to YYYY-MM-DD to avoid double time strings
            try {
                $dateOnly = \Carbon\Carbon::parse($this->date)->toDateString();
            } catch (\Throwable $e) {
                $dateOnly = (string) $this->date;
            }

            $eventStart = \Carbon\Carbon::parse($dateOnly . ' ' . $this->start_time);
            $eventEnd = \Carbon\Carbon::parse($dateOnly . ' ' . $this->end_time);

            if ($now->lt($eventStart)) {
                return 'upcoming';
            }
            if ($now->between($eventStart, $eventEnd)) {
                return 'ongoing';
            }
            return 'completed';
        }

        return 'scheduled';
    }

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

    /**
     * Event belongs to a Tournament
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function isCompetitive()
    {
        return in_array($this->event_type, ['tournament', 'team vs team']);
    }

    /**
     * Get all bookings for this event.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Get the group chat thread for this event
     */
    public function thread()
    {
        return $this->hasOne(\App\Models\MessageThread::class, 'game_id', 'id')
            ->where('type', 'game_group');
    }
}