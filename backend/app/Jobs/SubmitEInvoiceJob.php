<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Accounting\Plugin\CountryPluginRegistry;
use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\ComplianceDocument;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Platform\Exceptions\ExceptionReport;
use App\Platform\Exceptions\ExceptionService;
use App\Platform\Exceptions\ExceptionType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Issue #667 (EI5): auto-submit of a posted document to a country e-invoicing
 * provider, run on the queue instead of inline in the posting request.
 *
 * Posting has already created the compliance document as `queued`. Each
 * attempt submits through ComplianceService (the same path the manual submit
 * and the exception-queue retry use). A failed attempt is retried with the
 * country pack's retry policy backoff; the last failed attempt marks the
 * document `failed` with the reason, notifies the invoice owner and finance,
 * and raises the platform exception (issue #902) with its re-submit retry.
 */
class SubmitEInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Used when the country pack declares no retry policy — the same values
     * as the MY pack's MyInvoiceStatusSync::getRetryPolicy().
     */
    private const DEFAULT_BACKOFF_MINUTES = [1, 5, 15, 30, 60];

    public int $tries;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $documentType,
        public readonly int $documentId,
        public readonly string $countryCode,
    ) {
        // Attempts are counted by handle() itself (release with the pack's
        // backoff); the worker limit is one beyond them so a stuck worker
        // cannot retry forever.
        $this->tries = count($this->backoffMinutes()) + 2;
    }

    public function handle(ComplianceService $compliance): void
    {
        $this->runInTenantContext(function () use ($compliance) {
            try {
                $result = $this->submit($compliance);
                $error = ($result['success'] ?? false) === true
                    ? null
                    : (string) ($result['message'] ?? 'Unknown e-invoice submission failure');
            } catch (\Throwable $e) {
                $result = null;
                $error = $e->getMessage();
            }

            if ($error === null) {
                return;
            }

            Log::error('E-invoice auto-submit attempt failed', [
                'document_type' => $this->documentType,
                'document_id' => $this->documentId,
                'country_code' => $this->countryCode,
                'attempt' => $this->attempts(),
                'error' => $error,
            ]);

            $delayMinutes = $this->nextBackoffMinutes();
            if ($delayMinutes !== null) {
                $this->document()?->update([
                    'compliance_status' => 'queued',
                    'compliance_notes' => "Submission attempt {$this->attempts()} failed: {$error}. Retrying in {$delayMinutes} minute(s).",
                ]);
                $this->release($delayMinutes * 60);

                return;
            }

            $this->markFailed($compliance, $error, is_array($result) ? $result : null);
        });
    }

    /**
     * The worker gave up outside handle()'s own accounting (timeout, worker
     * crash) — the document must still end `failed` and someone be told.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->runInTenantContext(function () use ($exception) {
            $this->markFailed(
                app(ComplianceService::class),
                $exception?->getMessage() ?? 'E-invoice submission job failed',
                null,
            );
        });
    }

    /**
     * Raise the platform exception for a failed auto-submit (issue #902):
     * the affected document, its legal entity, a payload snapshot and the
     * `compliance.einvoice.submit` re-submit retry.
     */
    public static function reportException(
        string $documentType,
        int $documentId,
        string $countryCode,
        string $error,
        ?array $result,
    ): void {
        $source = self::findSource($documentType, $documentId);

        $documentNumber = $source?->credit_note_number
            ?? $source?->invoice_number
            ?? $source?->payment_number
            ?? null;

        app(ExceptionService::class)->report(
            new ExceptionReport(
                type: ExceptionType::SALES_EINVOICE_AUTO_SUBMIT_FAILED,
                message: "Auto-submit of {$documentType} ".($documentNumber ? "#{$documentNumber}" : "#{$documentId}")
                    ." to {$countryCode} failed: {$error}",
                documentId: $documentId,
                documentType: $documentType,
                legalEntityId: $source?->legal_entity_id ? (int) $source->legal_entity_id : null,
                payload: [
                    'country_code' => $countryCode,
                    'document_number' => $documentNumber,
                    'error' => $error,
                    'submission_id' => $result['submission_id'] ?? null,
                    'error_code' => $result['error_code'] ?? null,
                ],
            ),
        );
    }

    private function markFailed(ComplianceService $compliance, string $error, ?array $result): void
    {
        $document = $this->document();
        $document?->update([
            'compliance_status' => 'failed',
            'compliance_notes' => $error,
        ]);

        if ($document) {
            $compliance->notifySubmissionFailed($document, $error);
        }

        self::reportException($this->documentType, $this->documentId, $this->countryCode, $error, $result);
    }

    private function submit(ComplianceService $compliance): array
    {
        $source = self::findSource($this->documentType, $this->documentId);
        if (! $source) {
            return ['success' => false, 'message' => "The posted {$this->documentType} #{$this->documentId} no longer exists."];
        }

        return match ($this->documentType) {
            'credit_note' => $compliance->submitCreditNote($source, $this->countryCode),
            'refund_note' => $compliance->submitRefundNote($source, $this->countryCode),
            default => $compliance->submitInvoice($source, $this->countryCode),
        };
    }

    private static function findSource(string $documentType, int $documentId): Invoice|CustomerCreditNote|Payment|null
    {
        return match ($documentType) {
            'credit_note' => CustomerCreditNote::find($documentId),
            'refund_note' => Payment::find($documentId),
            default => Invoice::find($documentId),
        };
    }

    private function document(): ?ComplianceDocument
    {
        return ComplianceDocument::query()
            ->where('tenant_id', $this->tenantId)
            ->where('country_code', strtoupper($this->countryCode))
            ->where('document_type', $this->documentType)
            ->where('document_id', $this->documentId)
            ->latest('id')
            ->first();
    }

    /**
     * Minutes to wait before the next attempt, or null when this was the
     * last one. The sync queue driver cannot re-run a released job, so an
     * attempt there is always the last.
     */
    private function nextBackoffMinutes(): ?int
    {
        if ($this->job instanceof SyncJob) {
            return null;
        }

        $backoff = $this->backoffMinutes();
        $attempt = max(1, $this->attempts());

        return $backoff[$attempt - 1] ?? null;
    }

    /**
     * @return array<int, int>
     */
    private function backoffMinutes(): array
    {
        $sync = app(CountryPluginRegistry::class)->resolve($this->countryCode, 'sync');
        $policy = $sync && method_exists($sync, 'getRetryPolicy') ? $sync->getRetryPolicy() : [];
        $backoff = $policy['backoff_minutes'] ?? self::DEFAULT_BACKOFF_MINUTES;

        return array_values(array_map('intval', is_array($backoff) ? $backoff : self::DEFAULT_BACKOFF_MINUTES));
    }

    private function runInTenantContext(callable $callback): void
    {
        $tenancy = tenancy();

        if ($tenancy->tenant?->getTenantKey() === $this->tenantId) {
            $callback();

            return;
        }

        // On the request path (sync driver) and in tests the schema is
        // already bound but the Tenancy singleton may be empty: restore only
        // the context object — initialize()/end() would reset the schema and
        // connection of the request still in flight (same object-only restore
        // the auto-submit listener and the #902 retry handler use).
        if (function_exists('tenant') && tenant('id') !== null) {
            $tenant = \App\Models\Tenant::find($this->tenantId);
            if (! $tenant) {
                throw new \RuntimeException("Tenant {$this->tenantId} not found for e-invoice submission.");
            }

            $previousTenant = $tenancy->tenant;
            $previousInitialized = $tenancy->initialized;
            $tenancy->tenant = $tenant;
            $tenancy->initialized = true;

            try {
                $callback();
            } finally {
                $tenancy->tenant = $previousTenant;
                $tenancy->initialized = $previousInitialized;
            }

            return;
        }

        // A bare queue worker has to initialize tenancy itself.
        $tenancy->initialize($this->tenantId);

        try {
            $callback();
        } finally {
            $tenancy->end();
        }
    }
}
