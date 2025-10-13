<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sport extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category', 'is_active'];


     public function users()
    {
        return $this->hasMany(User::class);
    }
    
    public function userProfiles()
    {
        return $this->hasMany(UserProfile::class, 'main_sport_id');
    }
} 