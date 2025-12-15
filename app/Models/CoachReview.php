<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoachReview extends Model
{
    use HasFactory;

    protected $table = 'coach_reviews';

    protected $fillable = [
        'coach_id',
        'student_id',
        'training_session_id',
        'rating',
        'review',
        'is_verified',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified' => 'boolean',
    ];

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function trainingSession()
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }
}