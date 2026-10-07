<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\Chatter\AssignedActivityService;
use Illuminate\Console\Command;

/**
 * Issue #410 — follow-up reminders for scheduled activities on sales
 * documents (quotations, orders, deliveries, invoices, credit notes).
 *
 * Runs hourly (routes/console.php) over every tenant. Each planned activity
 * whose due date has been reached notifies its assignee once, provided the
 * assignee may still open the document (see AssignedActivityService).
 */
class SendActivityReminders extends Command
{
    use IteratesTenants;

    protected $signature = 'sales:send-activity-reminders
        {--tenant= : Run for a single tenant id instead of iterating every tenant}';

    protected $description = 'Notify assignees of scheduled activities that have fallen due (#410)';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->remindTenant($tenantId));
            }

            // Tests and single-schema CLI contexts have no per-tenant schema
            // to switch to: run against the current search_path (same
            // fallback as sales:send-followup-reminders).
            return $this->remindTenant($tenantId);
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->remindTenant((string) $tenant->getTenantKey()));
    }

    private function remindTenant(string $tenantKey): int
    {
        $result = app(AssignedActivityService::class)->sendDueReminders($tenantKey);

        foreach ($result['failed'] as $failure) {
            $this->error(sprintf('[tenant:%s] activity %d: reminder failed (%s)', $tenantKey, $failure['activity_id'], $failure['error']));
        }

        $this->info(sprintf(
            '[tenant:%s] Activity reminders — sent: %d, skipped (no access): %d, failed: %d',
            $tenantKey,
            $result['sent'],
            $result['skipped'],
            count($result['failed']),
        ));

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
