<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventResult extends Model
{
    use HasFactory;

    protected $table = 'event_results';

    protected $fillable = [
        'event_id',
        'uploaded_by',
        'file_path',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function event() { return $this->belongsTo(Event::class); }
    public function uploader() { return $this->belongsTo(\App\Models\User::class, 'uploaded_by'); }
}
