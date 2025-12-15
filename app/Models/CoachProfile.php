<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoachProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bio',
        'specializations',
        'hourly_rate',
        'currency',
        'availability',
        'location',
        'is_verified',
        'years_experience',
        'certifications',
        'rating',
        'total_reviews',
        'is_active',
    ];

    protected $casts = [
        'specializations' => 'array',
        'availability' => 'array',
        'location' => 'array',
        'certifications' => 'array',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'rating' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}