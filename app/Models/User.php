<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'username',
        'email',
        'password',
        'birthday',
        'sex',
        'contact_number',
        'barangay',
        'city',
        'province',
        'zip_code',
        'profile_photo',
        'role_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the profile photo URL.
     *
     * @return string|null
     */
    public function getProfilePhotoUrlAttribute()
    {
        if ($this->profile_photo) {
            return \Storage::url($this->profile_photo);
        }
        return null;
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }



    /**
     * Relationships
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function userProfile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function userCertifications()
    {
        return $this->hasMany(UserCertification::class);
    }

    public function userAdditionalSports()
    {
        return $this->hasMany(UserAdditionalSport::class);
    }

    public function venueUsers()
    {
        return $this->hasMany(VenueUser::class);
    }

    public function teamMembers()
    {
        return $this->hasMany(TeamMember::class);
    }

    public function eventParticipants()
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function eventCheckins()
    {
        return $this->hasMany(EventCheckin::class);
    }

    public function playerCredits()
    {
        return $this->hasMany(PlayerCredit::class);
    }

    public function playerReportsMade()
    {
        return $this->hasMany(PlayerReport::class, 'reported_by_user_id');
    }

    public function playerReportsReceived()
    {
        return $this->hasMany(PlayerReport::class, 'reported_user_id');
    }

    public function playerBans()
    {
        return $this->hasMany(PlayerBan::class);
    }

    public function supportTicketsSubmitted()
    {
        return $this->hasMany(SupportTicket::class, 'submitted_by');
    }

    public function supportTicketsAssigned()
    {
        return $this->hasMany(SupportTicket::class, 'assigned_to');
    }

    public function messagesSent()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function messagesReceived()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }
}
