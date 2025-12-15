<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'location',
        'type',
        'tournament_type',  // ADD THIS
        'created_by',
        'start_date',
        'end_date',
        'registration_deadline',
        'status',
        'requires_documents',
        'required_documents',
        'settings',
        'max_teams',
        'min_teams',
        'registration_fee',
        'rules',
        'prizes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'registration_deadline' => 'date',
        'requires_documents' => 'boolean',
        'required_documents' => 'array',
        'settings' => 'array',
    ];

    /**
     * Tournament has many events
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants()
    {
        return $this->hasMany(TournamentParticipant::class);
    }

    public function organizers()
    {
        return $this->hasMany(TournamentOrganizer::class);
    }

    public function matchups()
    {
        return $this->hasMany(TeamMatchup::class);
    }

    public function documents()
    {
        return $this->hasMany(TournamentDocument::class);
    }

    public function announcements()
    {
        return $this->hasMany(TournamentAnnouncement::class);
    }

    public function analytics()
    {
        return $this->hasOne(\App\Models\TournamentAnalytics::class);
    }

    public function approvedParticipants()
    {
        return $this->participants()->where('status', 'approved');
    }

    public function pendingParticipants()
    {
        return $this->participants()->where('status', 'pending');
    }
}