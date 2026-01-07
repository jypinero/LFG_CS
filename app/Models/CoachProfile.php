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
        'verified_at',
        'verified_by',
        'verification_notes',
        'verified_by_ai',
    ];

    protected $casts = [
        'specializations' => 'array',
        'availability' => 'array',
        'location' => 'array',
        'certifications' => 'array',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'rating' => 'decimal:2',
        'verified_at' => 'datetime',
        'verified_by_ai' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function entityDocuments()
    {
        return $this->morphMany(EntityDocument::class, 'documentable');
    }

    public function isVerified()
    {
        return $this->is_verified && !is_null($this->verified_at);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}