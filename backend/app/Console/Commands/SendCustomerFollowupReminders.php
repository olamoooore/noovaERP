<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Services\CustomerFollowupService;
use Illuminate\Console\Command;

/**
 * Issue #328 — automatic customer follow-up reminders.
 *
 * Runs daily (scheduled in routes/console.php) over every tenant. Inside a
 * tenant it does nothing unless automatic follow-ups are on in Sales
 * Settings; then each overdue invoice that reached a follow-up level not yet
 * handled gets that level's action (see CustomerFollowupService).
 */
class SendCustomerFollowupReminders extends Command
{
    use IteratesTenants;

    protected $signature = 'sales:send-followup-reminders
        {--tenant= : Run for a single tenant id instead of iterating every tenant}';

    protected $description = 'Send automatic follow-up reminders for overdue customer invoices (#328)';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->followUpTenant($tenantId));
            }

            // Tests and single-schema CLI contexts have no per-tenant schema
            // to switch to: run against the current search_path (same
            // fallback as sales:send-weekly-digest).
            return $this->followUpTenant($tenantId);
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->followUpTenant((string) $tenant->getTenantKey()));
    }

    private function followUpTenant(string $tenantKey): int
    {
        $results = app(CustomerFollowupService::class)->runAutomatic();

        $counts = array_count_values(array_column($results, 'outcome'));

        foreach ($results as $result) {
            if ($result['outcome'] !== CustomerFollowupService::OUTCOME_SENT) {
                $this->line(sprintf(
                    '[tenant:%s] %s: %s%s',
                    $tenantKey,
                    $result['invoice_number'],
                    $result['outcome'],
                    isset($result['reason']) ? " ({$result['reason']})" : '',
                ));
            }
        }

        $this->info(sprintf(
            '[tenant:%s] Follow-up reminders — sent: %d, manual actions due: %d, skipped: %d, failed: %d',
            $tenantKey,
            $counts[CustomerFollowupService::OUTCOME_SENT] ?? 0,
            $counts[CustomerFollowupService::OUTCOME_ACTION_DUE] ?? 0,
            $counts[CustomerFollowupService::OUTCOME_SKIPPED] ?? 0,
            $counts[CustomerFollowupService::OUTCOME_FAILED] ?? 0,
        ));

        return ($counts[CustomerFollowupService::OUTCOME_FAILED] ?? 0) > 0 ? 1 : 0;
    }
}
