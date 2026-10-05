<?php

declare(strict_types=1);

namespace Modules\Sales\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Sales\Notifications\SalesNotificationCatalogue;
use Modules\Sales\Notifications\SalesNotificationDispatcher;

/**
 * Issue #903 (PC9) — daily overdue-invoice notification scan.
 *
 * The #901 catalogue's `invoice.overdue.v1` only fires on a settlement
 * transition (`sync_overdue`), which nothing schedules when an invoice simply
 * passes its due date. This command is the time-based half: for every tenant,
 * invoices past their due date with an outstanding balance are notified once
 * to the account owner (customer salesperson) and the accounts role through
 * the same SalesNotificationDispatcher as the bus events.
 *
 * The dispatcher's document-level idempotency makes reruns safe: a user is
 * notified once per invoice, so the command can also be run manually as a
 * backfill after downtime. Thresholds are operational constants, not policy.
 */
class ScanOverdueInvoices extends Command
{
    use IteratesTenants;

    protected $signature = 'sales:scan-overdue-invoices
        {--tenant= : Run for a single tenant id instead of iterating every tenant}';

    protected $description = 'Notify account owners/accounts of invoices past their due date (#903)';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->scanTenant($tenantId));
            }

            // Tests and single-schema CLI contexts have no per-tenant schema to
            // switch to: run against the current search_path (same fallback as
            // SendWeeklySalesDigest).
            return $this->scanTenant((string) ($tenant?->getTenantKey() ?? $tenantId));
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->scanTenant((string) $tenant->getTenantKey()));
    }

    private function scanTenant(string $tenantId): int
    {
        $invoices = Invoice::query()
            ->whereDate('due_date', '<', now()->toDateString())
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid', 'written_off'])
            ->orderBy('id')
            ->get();

        $dispatcher = app(SalesNotificationDispatcher::class);
        $notified = 0;

        foreach ($invoices as $invoice) {
            $notified += $dispatcher->dispatch(SalesNotificationCatalogue::INVOICE_OVERDUE, [
                'document_type' => 'invoice',
                'document_id' => (int) $invoice->id,
                'document_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id !== null ? (int) $invoice->customer_id : null,
                'total' => (float) $invoice->total,
                'balance' => (float) $invoice->balance,
                'currency_code' => $invoice->currency_code,
                'due_date' => $invoice->due_date?->toDateString(),
                'actor_user_id' => null,
            ]);
        }

        $this->info("[tenant:{$tenantId}] Overdue scan: {$invoices->count()} invoice(s) checked, {$notified} notification(s) written.");

        return 0;
    }
}
