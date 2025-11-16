<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class MessageThread extends Model
{
    use HasFactory;
    
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'created_by',
        'is_group',
        'title',
        'type',
        'game_id',
        'team_id',
        'venue_id',
        'is_closed',
        'closed_at',
    ];

    protected $casts = [
        'is_group' => 'boolean',
        'is_closed' => 'boolean',
        'closed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages()
    {
        return $this->hasMany(Message::class, 'thread_id')->orderBy('sent_at', 'asc');
    }

    public function participants()
    {
        return $this->hasMany(ThreadParticipant::class, 'thread_id');
    }
}


