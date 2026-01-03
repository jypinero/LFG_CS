<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingPost extends Model
{
    protected $table = 'marketingposts';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id','post_id','event_id','booking_id','author_id','venue_id',
        'image_url','caption','create_event'
    ];

    protected $casts = [
        'create_event' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($m) {
            if (empty($m->id)) $m->id = (string) Str::uuid();
        });
    }

    public function post(): BelongsTo { return $this->belongsTo(Post::class, 'post_id'); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class, 'event_id'); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class, 'booking_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
    public function venue(): BelongsTo { return $this->belongsTo(Venue::class, 'venue_id'); }
}