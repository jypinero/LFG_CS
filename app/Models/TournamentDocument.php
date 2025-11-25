<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'participant_id',
        'document_type',
        'document_name',
        'file_path',
        'file_type',
        'file_size',
        'verification_status',
        'rejection_reason',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function participant()
    {
        return $this->belongsTo(TournamentParticipant::class, 'participant_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function approve($userId)
    {
        $this->update([
            'verification_status' => 'approved',
            'verified_by' => $userId,
            'verified_at' => now(),
        ]);
    }

    public function reject($reason, $userId)
    {
        $this->update([
            'verification_status' => 'rejected',
            'rejection_reason' => $reason,
            'verified_by' => $userId,
            'verified_at' => now(),
        ]);
    }
}
