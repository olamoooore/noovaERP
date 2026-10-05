<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Platform\Exceptions\ExceptionQueue;
use App\Platform\Exceptions\ExceptionReport;
use App\Platform\Exceptions\ExceptionService;
use App\Platform\Exceptions\ExceptionType;
use App\Services\StockCogsReconciliationService;
use Illuminate\Console\Command;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * Issue #696 (INV-D3): the nightly stock & COGS mismatch report.
 *
 * Scans every tenant's open + recently-touched sales orders, delivery orders
 * and invoices through the same {@see StockCogsReconciliationService} the
 * badge endpoint serves, and records each mismatch once in #902's platform
 * exception queue (owner_role=accountant, so #914's operations console shows
 * it to accountants behind accounting.view). Deduplicated: a mismatch that is
 * still open is not raised again by the next night's run.
 *
 * The queue is deliberately the report mechanism — it already carries the
 * document, payload and per-role triage the fix needs; a second report table
 * would be a parallel mechanism (SOP §4). There is no retry handler: the fix
 * is the document's existing correction path (reverse the duplicate delivery
 * issue, reset-to-draft the invoice), which the payload points at.
 */
class StockCogsReconciliationReportCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'inventory:stock-cogs-reconciliation-report
        {--tenant= : Run for a single tenant id}
        {--days=30 : Also scan documents updated within this many days}
        {--format=table : Output format (table|json)}
        {--fail-on-mismatch : Exit with code 1 when any mismatch was found}';

    protected $description = 'Nightly stock & COGS reconciliation badge sweep across sales documents (#696)';

    /** Candidate documents the current scan has badged. */
    private int $scannedCount = 0;

    public function __construct(
        private readonly StockCogsReconciliationService $reconciliation,
        private readonly ExceptionService $exceptions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $days = (int) $this->option('days');
        $failOnMismatch = (bool) $this->option('fail-on-mismatch');
        $format = (string) $this->option('format');

        $run = function (Tenant $tenant) use ($days, $failOnMismatch, $format): int {
            $result = $this->reconcileTenant($tenant, $days);
            $this->render($tenant, $result, $format);

            return $failOnMismatch && $result['mismatches'] !== [] ? self::FAILURE : self::SUCCESS;
        };

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);

            if (! $tenant) {
                $this->error("Tenant not found: {$tenantId}");

                return self::FAILURE;
            }

            return (int) $tenant->run(fn () => $run($tenant));
        }

        return $this->forEachTenant($run);
    }

    /**
     * The per-tenant body the IteratesTenants closure runs: compute every
     * candidate's badge, record the mismatches and return the summary. Public
     * so tests can drive the tenant pass inside a bound tenant context (the
     * trait skips schema-less test tenants). Rendering is separate so the
     * logic never depends on a console output being attached.
     *
     * @return array{scanned: int, mismatches: array<int, array<string, mixed>>, recorded: int, already_open: int}
     */
    public function reconcileTenant(Tenant $tenant, int $days = 30): array
    {
        $mismatches = $this->scan($days);
        $recorded = 0;
        $alreadyOpen = 0;

        foreach ($mismatches as $badge) {
            if ($this->recordMismatch($badge)) {
                $recorded++;
            } else {
                $alreadyOpen++;
            }
        }

        return [
            'scanned' => $this->scannedCount,
            'mismatches' => $mismatches,
            'recorded' => $recorded,
            'already_open' => $alreadyOpen,
        ];
    }

    /**
     * @param  array{scanned: int, mismatches: array<int, array<string, mixed>>, recorded: int, already_open: int}  $result
     */
    private function render(Tenant $tenant, array $result, string $format): void
    {
        $mismatches = $result['mismatches'];

        $this->newLine();
        $this->info("Stock/COGS reconciliation — tenant {$tenant->id}");

        if ($format === 'json') {
            $this->line(json_encode($mismatches, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($mismatches === []) {
            $this->info("No stock/COGS mismatches found in the {$result['scanned']} document(s) scanned.");
        } else {
            $this->table(
                ['Document', 'ID', 'Number', 'Stock', 'COGS', 'Details'],
                array_map(fn (array $badge) => [
                    $badge['document']['label'],
                    $badge['document']['id'],
                    (string) $badge['document']['number'],
                    $badge['stock']['ok'] ? 'OK' : 'MISMATCH',
                    $badge['cogs']['ok'] ? 'OK' : 'MISMATCH',
                    $this->summary($badge),
                ], $mismatches),
            );

            $this->warn(sprintf(
                '%d mismatch(es) in %d scanned document(s): %d queued for accountants, %d already open.',
                count($mismatches),
                $result['scanned'],
                $result['recorded'],
                $result['already_open'],
            ));
        }
    }

    /**
     * Badge every candidate document and keep the ones with a mismatch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scan(int $days): array
    {
        $since = now()->subDays(max($days, 0));
        $mismatches = [];
        $this->scannedCount = 0;

        foreach ($this->candidateIds($since) as $documentType => $documentIds) {
            foreach ($documentIds as $documentId) {
                $this->scannedCount++;
                $badge = $this->reconciliation->badge($documentType, (int) $documentId);

                if ($badge['has_mismatch']) {
                    $mismatches[] = $badge;
                }
            }
        }

        return $mismatches;
    }

    /**
     * Open + recent documents per subject. "Open" is the in-flight statuses of
     * each document type; "recent" is anything touched inside the window, so a
     * completed delivery or posted invoice that just changed is still swept.
     *
     * @return array<string, array<int, int>>
     */
    private function candidateIds(\Carbon\CarbonInterface $since): array
    {
        return [
            'sales_order' => SalesOrder::query()
                ->where('status', '!=', 'cancelled')
                ->where(function ($query) use ($since) {
                    $query->where('updated_at', '>=', $since)
                        ->orWhereIn('status', ['draft', 'confirmed', 'allocated']);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),

            'delivery_order' => DeliveryOrder::query()
                ->where('status', '!=', 'cancelled')
                ->where(function ($query) use ($since) {
                    $query->where('updated_at', '>=', $since)
                        ->orWhereIn('status', ['draft', 'confirmed']);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),

            'invoice' => Invoice::query()
                ->whereNotIn('status', ['cancelled', 'voided', 'reversed'])
                ->where(function ($query) use ($since) {
                    $query->where('updated_at', '>=', $since)
                        ->orWhere('status', 'draft');
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];
    }

    /**
     * Queue one mismatch unless an open row already tracks the same document.
     *
     * @param  array<string, mixed>  $badge
     */
    private function recordMismatch(array $badge): bool
    {
        $document = $badge['document'];

        $alreadyOpen = ExceptionQueue::query()
            ->where('tenant_id', tenant()->id)
            ->where('exception_type', ExceptionType::INVENTORY_STOCK_COGS_MISMATCH->value)
            ->where('document_type', $document['type'])
            ->where('document_id', (string) $document['id'])
            ->whereIn('status', ['open', 'in_progress', 'retrying'])
            ->exists();

        if ($alreadyOpen) {
            return false;
        }

        $this->exceptions->report(new ExceptionReport(
            type: ExceptionType::INVENTORY_STOCK_COGS_MISMATCH,
            message: $this->summary($badge),
            documentId: $document['id'],
            documentType: $document['type'],
            payload: [
                'document_number' => $document['number'],
                'stock' => $badge['stock'],
                'cogs' => $badge['cogs'],
                'fix' => $this->fixAction(),
            ],
        ));

        return true;
    }

    /**
     * The existing correction path, not a new one: the accountant opens the
     * document from the queue and uses the buttons it already has.
     *
     * @return array<string, string>
     */
    private function fixAction(): array
    {
        return [
            'kind' => 'document_correction',
            'label' => 'Open the offending document',
            'instructions' => 'Reverse a duplicate delivery issue with the delivery order\'s '
                .'documented reverse action, or reset a mis-posted invoice to draft (which reverses '
                .'its COGS through PostingEngine and restocks the goods). Both are the existing, '
                .'audited correction paths — this report creates no new correction.',
        ];
    }

    /**
     * @param  array<string, mixed>  $badge
     */
    private function summary(array $badge): string
    {
        $parts = [];

        foreach ($badge['stock']['offenders'] as $offender) {
            $parts[] = sprintf(
                'stock over-issued: %s by %s (%s issued / %s expected)',
                $offender['product']['name'] ?? ('product #'.$offender['product_id']),
                $this->quantity($offender['excess']),
                $this->quantity($offender['issued']),
                $this->quantity($offender['expected']),
            );
        }

        foreach ($badge['cogs']['offenders'] as $offender) {
            $parts[] = sprintf(
                'COGS %s on %s',
                str_replace('_', ' ', $offender['kind']),
                $offender['source_document']['number']
                    ?? ($offender['source_document']['type'].' #'.$offender['source_document']['id']),
            );
        }

        return sprintf(
            '%s %s — %s',
            $badge['document']['label'],
            $badge['document']['number'],
            $parts === [] ? 'stock & COGS disagree' : implode('; ', $parts),
        );
    }

    private function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
