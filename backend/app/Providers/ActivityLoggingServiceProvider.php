<?php

namespace App\Providers;

use App\Features\ActivityLogging\Services\ActivityLogService;
use App\Features\ActivityLogging\Contracts\ActivityLoggerInterface;
use Illuminate\Support\ServiceProvider;

class ActivityLoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ActivityLoggerInterface::class, ActivityLogService::class);
        $this->app->alias(ActivityLoggerInterface::class, 'activitylog');
    }
}
