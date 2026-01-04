<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        // updated to match new schema
        'subject',
        'email',
        'message',
        'file_path',
        'status'
    ];

    // optional relations kept or removed depending on your app
    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}