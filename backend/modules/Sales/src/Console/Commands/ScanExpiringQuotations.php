<?php

declare(strict_types=1);

namespace Modules\Sales\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Quotation;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Sales\Notifications\SalesNotificationCatalogue;
use Modules\Sales\Notifications\SalesNotificationDispatcher;

/**
 * Issue #903 (PC9) — daily quotation-expiry reminder scan.
 *
 * The #901 catalogue's `quotation.expired.v1` is a transition; no schedule
 * transitions quotations on validity expiry, and #335's expiry behaviour is
 * explicitly out of scope here. This command only *warns*: draft/sent
 * quotations whose `valid_until` falls within the next
 * EXPIRING_WINDOW_DAYS days are notified once to the quotation owner and
 * salesperson. The window is an operational constant (disclosed in the #903
 * report), not a statutory rule.
 *
 * The dispatcher's document-level idempotency keeps daily reruns from
 * re-notifying the same quotation.
 */
class ScanExpiringQuotations extends Command
{
    use IteratesTenants;

    /** Days before `valid_until` at which the owner is warned. */
    public const EXPIRING_WINDOW_DAYS = 3;

    protected $signature = 'sales:scan-expiring-quotations
        {--tenant= : Run for a single tenant id instead of iterating every tenant}';

    protected $description = 'Warn quotation owners of quotations expiring within '.self::EXPIRING_WINDOW_DAYS.' days (#903)';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->scanTenant($tenantId));
            }

            return $this->scanTenant((string) ($tenant?->getTenantKey() ?? $tenantId));
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->scanTenant((string) $tenant->getTenantKey()));
    }

    private function scanTenant(string $tenantId): int
    {
        $today = now()->startOfDay();

        $quotations = Quotation::query()
            ->whereIn('status', ['draft', 'sent'])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '>=', $today->toDateString())
            ->whereDate('valid_until', '<=', $today->copy()->addDays(self::EXPIRING_WINDOW_DAYS)->toDateString())
            ->orderBy('id')
            ->get();

        $dispatcher = app(SalesNotificationDispatcher::class);
        $notified = 0;

        foreach ($quotations as $quotation) {
            $notified += $dispatcher->dispatch(SalesNotificationCatalogue::QUOTATION_EXPIRING, [
                'document_type' => 'quotation',
                'document_id' => (int) $quotation->id,
                'document_number' => $quotation->quotation_number,
                'customer_id' => $quotation->customer_id !== null ? (int) $quotation->customer_id : null,
                'total' => (float) $quotation->total,
                'currency_code' => $quotation->currency_code,
                'valid_until' => $quotation->valid_until?->toDateString(),
                'actor_user_id' => null,
            ]);
        }

        $this->info("[tenant:{$tenantId}] Expiring quotations scan: {$quotations->count()} quotation(s) checked, {$notified} notification(s) written.");

        return 0;
    }
}
