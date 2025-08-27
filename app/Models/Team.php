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
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
} 