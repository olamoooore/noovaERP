<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\PaymentGateway\Services\SettlementReconciliationService;

/**
 * CLI counterpart of the settlement ingest API (FR-PAY-06 / P-04a) for
 * scheduled/manual runs. Tenant-scoped: run inside a tenant context
 * (php artisan tenants:run payments:settlements:ingest ...) — it books the
 * current schema's transactions and never crosses tenants.
 *
 * CSV headers: transaction_id,gross,fee,net,currency,settled_at
 * (fee/net/currency/settled_at optional; net defaults to gross - fee).
 */
class IngestPaymentSettlementsCommand extends Command
{
    protected $signature = 'payments:settlements:ingest
                            {file : Path to the provider settlement CSV}
                            {--batch= : Settlement batch reference stored on matched rows}';

    protected $description = 'Reconcile a gateway settlement file: matched rows post the clearing->cash GL entry with fees; variance/orphan/unsettled rows are reported only';

    public function handle(SettlementReconciliationService $reconciliation): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Settlement file not readable: {$path}");

            return self::FAILURE;
        }

        $rows = $reconciliation->parseCsv($path);
        if ($rows === []) {
            $this->error('The settlement file has no data rows.');

            return self::FAILURE;
        }

        $report = $reconciliation->ingestRows($rows, $this->option('batch') ?: null);

        foreach ($report as $bucket => $items) {
            $this->line(sprintf('%-24s %d', $bucket, count($items)));
        }

        foreach (array_merge($report['variance'], $report['orphan'], $report['invalid'], $report['clearing_unconfigured']) as $problem) {
            $this->warn("row {$problem['row']} {$problem['transaction_id']}: {$problem['reason']}");
        }

        return $report['variance'] === [] && $report['invalid'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
