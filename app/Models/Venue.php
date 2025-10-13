<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    use HasFactory;

     protected $fillable = [
        'name',
        'description',
        'address',
        'latitude',
        'longitude',
        'verified_at',
        'verification_expires_at',
        'created_by',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function photos()
    {
        return $this->hasMany(VenuePhoto::class);
    }

    public function users()
    {
        return $this->hasMany(VenueUser::class);
    }

    public function reviews()
    {
        return $this->hasMany(VenueReview::class);
    }

    public function facilities()
    {
        return $this->hasMany(Facilities::class, 'venue_id');
    }
} 