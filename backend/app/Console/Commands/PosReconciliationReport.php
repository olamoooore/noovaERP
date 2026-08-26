<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Services\Reconciliation\PosPaymentGLReconciliationService;
use Illuminate\Console\Command;

class PosReconciliationReport extends Command
{
    use IteratesTenants;

    protected $signature = 'pos:reconciliation-report
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}
                            {--format=table : Output format (table|json|csv)}
                            {--output= : Write report to file}
                            {--fail-on-exceptions : Exit with code 1 if exceptions found}';

    protected $description = 'Generate POS payment/GL reconciliation report (per-tenant)';

    public function __construct(
        private readonly PosPaymentGLReconciliationService $reconciliationService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $format = $this->option('format');
        $output = $this->option('output');
        $failOnExceptions = $this->option('fail-on-exceptions');

        $this->info('Running POS reconciliation across all tenants...');

        return $this->forEachTenant(function ($tenant) use ($from, $to, $format, $output, $failOnExceptions) {
            $this->info("[tenant:{$tenant->id}] Running POS reconciliation...");

            $result = $this->reconciliationService->reconcile($from, $to);

            $this->newLine();
            $this->line($result['summary']);
            $this->newLine();

            $this->info("[tenant:{$tenant->id}] Stats:");
            $this->table(
                ['Metric', 'Count'],
                collect($result['stats'])->map(fn ($v, $k) => [
                    str_replace('_', ' ', ucfirst($k)),
                    $v,
                ])->values()->toArray()
            );

            if (! empty($result['exceptions'])) {
                $this->newLine();
                $this->warn("[tenant:{$tenant->id}] Exceptions Found:");

                if ($format === 'table') {
                    $this->table(
                        ['Type', 'Severity', 'Entity', 'ID', 'Message'],
                        collect($result['exceptions'])->map(fn ($e) => [
                            $e['type'],
                            $e['severity'],
                            $e['entity'],
                            $e['entity_id'],
                            substr($e['message'], 0, 80),
                        ])->toArray()
                    );
                } elseif ($format === 'json') {
                    $this->line(json_encode($result['exceptions'], JSON_PRETTY_PRINT));
                }
            }

            if ($output) {
                $content = $format === 'json'
                    ? json_encode($result, JSON_PRETTY_PRINT)
                    : $this->buildCsvReport($result);

                // Per-tenant output filename to avoid clobbering across tenants
                $tenantOutput = preg_replace('/(\.\w+)?$/', '_'.$tenant->id.'$1', $output);
                file_put_contents($tenantOutput, $content);
                $this->info("[tenant:{$tenant->id}] Report written to: {$tenantOutput}");
            }

            if ($failOnExceptions && ! empty($result['exceptions'])) {
                return 1;
            }

            return 0;
        });
    }

    protected function buildCsvReport(array $result): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Type', 'Severity', 'Entity', 'Entity ID', 'Order ID', 'Amount', 'Message']);

        foreach ($result['exceptions'] as $e) {
            fputcsv($handle, [
                $e['type'],
                $e['severity'],
                $e['entity'],
                $e['entity_id'],
                $e['order_id'] ?? '',
                $e['amount'] ?? '',
                $e['message'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
