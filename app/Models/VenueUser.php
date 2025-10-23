<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VenueUser extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $table = 'venue_users';

    protected $fillable = [
        'venue_id',
        'user_id',
        'role',
        'is_primary_owner',
    ];

    protected $casts = [
        'is_primary_owner' => 'boolean',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}