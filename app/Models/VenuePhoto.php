<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VenuePhoto extends Model
{
    use HasFactory;

    // you store uploaded_at manually, not Eloquent timestamps
    public $timestamps = false;

    protected $table = 'venue_photos';

    protected $fillable = [
        'venue_id',
        'image_path',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }

    public function photos()
    {
        return $this->hasMany(VenuePhoto::class, 'venue_id');
    }
}