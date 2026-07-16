<?php

namespace App\Modules\FinancialClose\Jobs;

use App\Features\ActivityLogging\Contracts\ActivityLoggerInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordFinancialCloseActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $userId,
        private readonly string $action,
        private readonly array $details,
    ) {}

    public function handle(ActivityLoggerInterface $activityLogger): void
    {
        $activityLogger
            ->for(\App\Models\User::class, $this->userId)
            ->withProperties([
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId,
                'action' => $this->action,
                'details' => $this->details,
                'timestamp' => now()->toIso8601String(),
            ])
            ->useEvent($this->action);

        // Create a simple model stub for logging
        $subject = new \App\Models\Tenant;
        $subject->id = $this->tenantId;

        $activityLogger->log($subject);
    }
}
