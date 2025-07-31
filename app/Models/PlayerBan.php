<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayerBan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'banned_by', 'event_id', 'reason', 'ban_type', 'start_date', 'end_date'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function banner()
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
} 