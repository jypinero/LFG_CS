<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'main_sport_id', 'main_sport_level', 'bio', 'occupation', 'is_certified_pro'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mainSport()
    {
        return $this->belongsTo(Sport::class, 'main_sport_id');
    }
} 