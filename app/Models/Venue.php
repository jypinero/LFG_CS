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
        'verified_by',
        'created_by',
        'phone_number',
        'email',
        'facebook_url',
        'instagram_url',
        'website',
        'house_rules',
        'is_closed',
        'closed_at',
        'closed_reason',
        'verified_by_ai',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'verification_expires_at' => 'datetime',
        'is_closed' => 'boolean',
        'closed_at' => 'datetime',
        'verified_by_ai' => 'boolean',
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

    public function operatingHours()
    {
        return $this->hasMany(VenueOperatingHours::class, 'venue_id');
    }

    public function amenities()
    {
        return $this->hasMany(VenueAmenity::class, 'venue_id');
    }

    public function closureDates()
    {
        return $this->hasMany(VenueClosureDate::class, 'venue_id');
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

    /**
     * Creator (user who created the venue)
     */
    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function entityDocuments()
    {
        return $this->morphMany(EntityDocument::class, 'documentable');
    }

    public function scopeVerifiedByAI($query)
    {
        return $query->where('verified_by_ai', true);
    }

    public function isVerified()
    {
        return !is_null($this->verified_at);
    }

    public function verifier()
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }
}