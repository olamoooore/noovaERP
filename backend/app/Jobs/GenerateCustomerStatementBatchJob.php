<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CustomerStatementBatch;
use App\Services\CustomerStatementBatchService;
use App\Services\CustomerStatementDeliveryService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Issue #607 (RPT-F1) — generate and send one customer statement per customer
 * matching the batch filters. Progress is written to the batch row after
 * every customer so the Sales statements tab can poll it; a failure on one
 * customer never stops the rest and is counted (never hidden).
 */
class GenerateCustomerStatementBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $batchId,
        public readonly string $tenantId,
        public readonly array $filters,
        public readonly array $options,
        public readonly ?int $userId = null,
    ) {}

    public function handle(
        CustomerStatementBatchService $batches,
        CustomerStatementDeliveryService $delivery,
    ): void {
        $this->runInTenantContext(function () use ($batches, $delivery) {
            $batch = CustomerStatementBatch::find($this->batchId);

            if (! $batch) {
                Log::error('Customer statement batch missing', ['batch_id' => $this->batchId]);

                return;
            }

            $batch->forceFill([
                'status' => CustomerStatementBatch::STATUS_PROCESSING,
                'started_at' => now(),
            ])->save();

            try {
                $customers = $batches->eligibleCustomers($this->userId ?? 0, $this->filters);
                $batch->forceFill(['total_customers' => $customers->count()])->save();

                [$start, $end] = $this->period();

                foreach ($customers as $customer) {
                    try {
                        $statement = $delivery->generate(
                            $customer,
                            $start,
                            $end,
                            (bool) ($this->options['include_paid'] ?? false),
                            $this->options['period_type'] ?? null,
                            $this->userId,
                        );

                        // Every batch customer gets its own persisted PDF.
                        $delivery->storePdf($statement);

                        $batch->increment('generated_count');

                        $channel = $this->options['channel'] ?? null;
                        if ($channel !== null && $channel !== '' && $channel !== 'none') {
                            $delivery->deliver($statement, $channel, null, $batch->id, $this->userId);
                            $batch->increment('sent_count');
                        }
                    } catch (\Throwable $e) {
                        $batch->increment('failed_count');

                        // No hidden errors: the failure is counted, logged and
                        // visible through the batch progress endpoint.
                        Log::error('Customer statement batch item failed', [
                            'batch_id' => $batch->id,
                            'customer_id' => $customer->id,
                            'error' => $e->getMessage(),
                        ]);
                    } finally {
                        $batch->increment('processed_customers');
                    }
                }

                $batch->forceFill([
                    'status' => CustomerStatementBatch::STATUS_COMPLETED,
                    'completed_at' => now(),
                ])->save();
            } catch (\Throwable $e) {
                $batch->forceFill([
                    'status' => CustomerStatementBatch::STATUS_FAILED,
                    'completed_at' => now(),
                ])->save();

                throw $e;
            }
        });
    }

    /**
     * @return array{0: ?Carbon, 1: Carbon}
     */
    private function period(): array
    {
        if (! empty($this->options['start_date'])) {
            return [
                Carbon::parse($this->options['start_date'])->startOfDay(),
                Carbon::parse($this->options['end_date'] ?? $this->options['start_date'])->startOfDay(),
            ];
        }

        return [null, Carbon::parse($this->options['as_of'] ?? now())->startOfDay()];
    }

    private function runInTenantContext(callable $callback): void
    {
        // A bound tenant context already exists on the request path and in
        // tests (TenantTestCase binds it without flipping the Tenancy
        // singleton's initialized flag), so only a bare queue worker — where
        // tenant('id') is null — needs to initialize tenancy itself.
        $hasContext = function_exists('tenant') && tenant('id') !== null;

        if ($hasContext) {
            $callback();

            return;
        }

        tenancy()->initialize($this->tenantId);

        try {
            $callback();
        } finally {
            tenancy()->end();
        }
    }
}
