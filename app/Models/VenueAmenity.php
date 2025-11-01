<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VenueAmenity extends Model
{
    protected $fillable = [
        'venue_id',
        'name',
        'available',
        'description',
    ];

    protected $casts = [
        'available' => 'boolean',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }
}
