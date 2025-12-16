<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'tournament_type',
        'settings',
        'default_phases',
        'created_by',
        'is_public',
    ];

    protected $casts = [
        'settings' => 'array',
        'default_phases' => 'array',
        'is_public' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}





