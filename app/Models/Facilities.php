<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Facilities extends Model
{
    use HasFactory;

    protected $fillable = [
        'venue_id',
        'price_per_hr',
        'type',
        'name',
        'capacity',
        'covered',
    ];

    protected $casts = [
        'covered' => 'boolean',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function photos()
    {
        return $this->hasMany(FacilityPhoto::class, 'facility_id');
    }
}