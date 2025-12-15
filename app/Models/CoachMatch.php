<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoachMatch extends Model
{
    use HasFactory;

    protected $table = 'coach_matches';

    protected $fillable = [
        'student_id',
        'coach_id',
        'student_action',
        'coach_action',
        'match_status',
        'matched_at',
        'expires_at',
    ];

    protected $casts = [
        'matched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }
}