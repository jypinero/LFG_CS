<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VenueClosureDate extends Model
{
    protected $fillable = [
        'venue_id',
        'closure_date',
        'reason',
        'all_day',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'closure_date' => 'date',
        'all_day' => 'boolean',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }
}
