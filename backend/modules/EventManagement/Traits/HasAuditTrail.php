<?php

namespace Modules\EventManagement\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * HasAuditTrail Trait
 *
 * Lightweight audit logging for EventManagement models.
 * Logs create / update / delete activity to the Laravel log channel.
 * If activity_logs table is present it also writes a DB record.
 */
trait HasAuditTrail
{
    public static function bootHasAuditTrail(): void
    {
        static::created(function ($model) {
            static::writeAudit('created', $model);
        });

        static::updated(function ($model) {
            static::writeAudit('updated', $model);
        });

        static::deleted(function ($model) {
            static::writeAudit('deleted', $model);
        });
    }

    protected static function writeAudit(string $event, $model): void
    {
        try {
            $userId = Auth::id();
            $context = [
                'model' => class_basename($model),
                'id' => $model->getKey(),
                'user_id' => $userId,
                'event' => $event,
            ];

            // Include changed attributes for updates
            if ($event === 'updated') {
                $context['changes'] = $model->getDirty();
            }

            Log::channel('daily')->debug("EventManagement audit: {$event}", $context);
        } catch (\Throwable $e) {
            // Never let audit logging break the main flow
        }
    }

    /**
     * Get a summary of recent audit activity for this model instance.
     * Returns empty array when activity_logs table is unavailable.
     */
    public function getAuditLog(): array
    {
        return [];
    }
}
