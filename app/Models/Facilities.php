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
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }
}