<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class UserNotification extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'notification_id',
        'user_id',
        'read_at',
        'pinned',
        'is_read',
        'action_state',
        'action_taken_at',
        'created_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'read_at' => 'datetime',
        'action_taken_at' => 'datetime',
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

    public function actionEvents()
    {
        return $this->hasMany(UserNotificationActionEvent::class);
    }

    public function notification()
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }
}
