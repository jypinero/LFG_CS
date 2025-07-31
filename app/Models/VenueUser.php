<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VenueUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'venue_id', 'user_id', 'role', 'is_primary_owner'
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
} 