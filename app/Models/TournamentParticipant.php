<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'event_id',
        'team_id',
        'user_id',
        'registered_at',
        'type',
        'status',
        'rejection_reason',
        'approved_by',
        'approved_at',
        'withdrawn_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

     public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function documents()
    {
        return $this->hasMany(TournamentDocument::class, 'participant_id');
    }

    public function approve($userId)
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);
    }

    public function reject($reason, $userId)
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_by' => $userId,
        ]);
    }
}
