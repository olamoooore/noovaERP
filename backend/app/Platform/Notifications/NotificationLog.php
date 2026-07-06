<?php

namespace App\Platform\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotificationLog extends Model
{
    protected $table = 'notification_logs';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
