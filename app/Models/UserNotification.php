<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserNotification extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'notification_id',
        'user_id',
        'read_at',
        'pinned',
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
}
