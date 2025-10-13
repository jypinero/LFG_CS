<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Post extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    // 👇 Add this since posts table has no updated_at
    public $timestamps = false;

    protected $fillable = [
        'id',
        'author_id',
        'location',
        'image_url',
        'caption',
        'created_at',
        'is_archived', // new field
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function likes()
    {
        return $this->hasMany(PostLike::class, 'post_id');
    }

    public function comments()
    {
        return $this->hasMany(PostComment::class, 'post_id');
    }
}
