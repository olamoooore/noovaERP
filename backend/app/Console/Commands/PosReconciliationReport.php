<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\PosPaymentGLReconciliationService;
use Illuminate\Console\Command;

class PosReconciliationReport extends Command
{
    protected $signature = 'pos:reconciliation-report
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}
                            {--format=table : Output format (table|json|csv)}
                            {--output= : Write report to file}
                            {--fail-on-exceptions : Exit with code 1 if exceptions found}';

    protected $description = 'Generate POS payment/GL reconciliation report';

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

        $this->info('Running POS reconciliation...');

        $result = $this->reconciliationService->reconcile($from, $to);

        // Display summary
        $this->newLine();
        $this->line($result['summary']);
        $this->newLine();

        // Display stats
        $this->info('Stats:');
        $this->table(
            ['Metric', 'Count'],
            collect($result['stats'])->map(fn($v, $k) => [
                str_replace('_', ' ', ucfirst($k)),
                $v,
            ])->values()->toArray()
        );

        // Display exceptions
        if (!empty($result['exceptions'])) {
            $this->newLine();
            $this->warn('Exceptions Found:');

            if ($format === 'table') {
                $this->table(
                    ['Type', 'Severity', 'Entity', 'ID', 'Message'],
                    collect($result['exceptions'])->map(fn($e) => [
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

        // Write to file if requested
        if ($output) {
            $content = $format === 'json'
                ? json_encode($result, JSON_PRETTY_PRINT)
                : $this->buildCsvReport($result);

            file_put_contents($output, $content);
            $this->info("Report written to: {$output}");
        }

        if ($failOnExceptions && !empty($result['exceptions'])) {
            return 1;
        }

        return 0;
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
