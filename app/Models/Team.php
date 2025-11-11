<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'created_by',
        'team_photo',
        'certification',
        'certified',
        'team_type',
        'address_line',   // ADDED
        'latitude',       // ADDED
        'longitude',      // ADDED
        'sport_id',       // NEW
        'bio',            // NEW
        'roster_size_limit', // NEW
        'certification_document', // NEW
        'certification_verified_at', // NEW
        'certification_verified_by', // NEW
        'certification_status', // NEW
        'certification_ai_confidence', // NEW
        'certification_ai_notes', // NEW
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sport()
    {
        return $this->belongsTo(Sport::class);
    }

    public function members()
    {
        return $this->hasMany(TeamMember::class);
    }

    public function eventTeams()
    {
        return $this->hasMany(EventTeam::class);
    }

    public function matchupsAsTeamA()
    {
        return $this->hasMany(TeamMatchup::class, 'team_a_id');
    }

    public function matchupsAsTeamB()
    {
        return $this->hasMany(TeamMatchup::class, 'team_b_id');
    }

    public function scores()
    {
        return $this->hasMany(EventScore::class);
    }

    public function invites()
    {
        return $this->hasMany(TeamInvite::class);
    }

    public function certificationVerifier()
    {
        return $this->belongsTo(User::class, 'certification_verified_by');
    }
} 