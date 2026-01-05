<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatchNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'tournament_id',
        'created_by',
        'content',
        'type',
        'timestamp',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
