<?php

namespace App\Platform\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotificationPreference extends Model
{
    protected $table = 'platform_notification_preferences';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'mail_enabled' => 'boolean',
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
}
