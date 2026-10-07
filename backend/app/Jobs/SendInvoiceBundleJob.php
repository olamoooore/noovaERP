<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceSendBundleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Issue #329: one queued Send for one invoice selected in Bulk Send.
 *
 * The request already authorized the invoice (permission + legal entity) and
 * checked it can be delivered. The job sends the same bundle as the single
 * Send button (InvoiceSendBundleService). Its outcome lands on the invoice's
 * own delivery record / delivery status; a failure is logged and re-thrown so
 * the job is recorded as failed — never reported as sent.
 *
 * One attempt only: a retried send could email the customer twice.
 */
class SendInvoiceBundleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $invoiceId,
        public readonly int $userId,
    ) {}

    public function handle(): void
    {
        $this->runInTenantContext(function () {
            $invoice = Invoice::with('customer')->find($this->invoiceId);
            $user = User::find($this->userId);

            if ($invoice === null || $user === null) {
                throw new \RuntimeException(sprintf(
                    'Bulk send: invoice %d or user %d no longer exists.',
                    $this->invoiceId,
                    $this->userId,
                ));
            }

            $bundle = app(InvoiceSendBundleService::class);

            // The invoice may have changed since it was queued (cancelled,
            // customer email removed): re-check before emailing.
            $reason = $bundle->unsendableReason($invoice);
            if ($reason !== null) {
                throw new \RuntimeException("Bulk send skipped invoice {$invoice->invoice_number}: {$reason}");
            }

            try {
                $bundle->send($invoice, $user);
            } catch (\Throwable $e) {
                Log::error('Bulk send: invoice delivery failed', [
                    'invoice_id' => $this->invoiceId,
                    'user_id' => $this->userId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    private function runInTenantContext(callable $callback): void
    {
        // Same as GenerateCustomerStatementBatchJob: on the request path and
        // in tests a tenant context is already bound; only a bare queue
        // worker initializes tenancy itself.
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
