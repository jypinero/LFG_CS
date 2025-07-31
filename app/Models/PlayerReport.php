<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayerReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'reported_user_id', 'reported_by_user_id', 'event_id', 'reason', 'details', 'reported_at', 'status'
    ];

    public function reportedUser()
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
} 