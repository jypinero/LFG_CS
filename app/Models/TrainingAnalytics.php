<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrainingAnalytics extends Model
{
    use HasFactory;

    protected $table = 'training_analytics';

    protected $fillable = [
        'user_id',
        'user_type',
        'total_sessions',
        'completed_sessions',
        'cancelled_sessions',
        'total_hours_trained',
        'average_session_duration',
        'consistency_score',
        'completion_rate',
        'average_rating_received',
        'average_rating_given',
        'total_revenue',
        'total_spent',
        'current_streak_days',
        'longest_streak_days',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'total_hours_trained' => 'decimal:2',
        'average_session_duration' => 'decimal:2',
        'consistency_score' => 'decimal:2',
        'completion_rate' => 'decimal:2',
        'average_rating_received' => 'decimal:2',
        'average_rating_given' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'total_spent' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
