<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Venue extends Model
{
    use HasFactory;

    // allow mass assignment for update() / fill()
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

    // relations
    public function photos()
    {
        return $this->hasMany(VenuePhoto::class, 'venue_id');
    }

    public function facilities()
    {
        return $this->hasMany(Facilities::class, 'venue_id');
    }

    // relation used by your controller (camelCase)
    public function venueUsers()
    {
        return $this->hasMany(VenueUser::class, 'venue_id');
    }

    // also expose snake_case alias if some code uses 'venue_users'
    public function events()
    {
        return $this->hasMany(\App\Models\Event::class, 'venue_id');
    }

    // if not present, ensure relation name matches code that uses venue_users
    public function venue_users()
    {
        return $this->hasMany(\App\Models\VenueUser::class, 'venue_id');
    }
    
    // optional: clean up files when deleting a venue
    protected static function booted()
    {
        static::deleting(function ($venue) {
            foreach ($venue->photos as $p) {
                Storage::disk('public')->delete($p->image_path);
            }
            foreach ($venue->facilities as $f) {
                if ($f->photos) {
                    foreach ($f->photos as $fp) {
                        Storage::disk('public')->delete($fp->image_path);
                    }
                }
            }
        });
    }
}