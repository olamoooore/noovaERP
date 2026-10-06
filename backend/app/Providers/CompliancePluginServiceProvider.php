<?php

namespace App\Providers;

use App\Accounting\Events\CreditNotePosted;
use App\Accounting\Events\InvoicePosted;
use App\Accounting\Events\RefundPosted;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Accounting\Services\ComplianceService;
use App\Jobs\SubmitEInvoiceJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
// Singapore plugins
use Modules\AccountingCountry\Malaysia\Services\MFRSReportExporter;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceStatusSync;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceSubmissionProvider;
// Malaysia plugins
use Modules\AccountingCountry\Singapore\Services\PeppolStatusSync;
use Modules\AccountingCountry\Singapore\Services\PeppolSubmissionProvider;
use Modules\AccountingCountry\Singapore\Services\SFRSInvoiceMapper;
use Modules\AccountingCountry\Singapore\Services\SFRSValidationService;
use Modules\AccountingCountry\Singapore\Services\XBRLReportExporter;

class CompliancePluginServiceProvider extends ServiceProvider
{
    /**
     * Register compliance services
     */
    public function register(): void
    {
        // Compliance Service singleton
        $this->app->singleton(ComplianceService::class, function ($app) {
            return new ComplianceService($app->make(CountryPluginRegistry::class));
        });

        // Plugin Registry singleton
        $this->app->singleton(CountryPluginRegistry::class, function ($app) {
            $registry = new CountryPluginRegistry;

            // Register Singapore plugins
            $this->registerSingaporePlugins($registry);

            // Register Malaysia plugins
            $this->registerMalaysiaPlugins($registry);

            return $registry;
        });

        // Bind compliance service for dependency injection
        $this->app->bind('compliance', fn ($app) => $app->make(ComplianceService::class));
    }

    /**
     * Bootstrap compliance services
     */
    public function boot(): void
    {
        // Register event listeners for accounting events
        $this->registerEventListeners();

        // Issue #902: re-submit a queued e-invoice auto-submit failure.
        $this->registerRetryHandlers();
    }

    /**
     * Issue #902: the retry path behind the queue's
     * `compliance.einvoice.submit` handler key. A failed logical result is
     * raised as an exception so the queue records the retry as failed.
     */
    private function registerRetryHandlers(): void
    {
        app(\App\Platform\Exceptions\ExceptionRetryRegistry::class)->register(
            'compliance.einvoice.submit',
            function (\App\Platform\Exceptions\ExceptionQueue $exception): void {
                $documentType = (string) $exception->document_type;
                $documentId = (int) $exception->document_id;
                $countryCode = (string) ($exception->context['country_code'] ?? '');

                if ($documentId === 0 || $countryCode === '') {
                    throw new \RuntimeException('Queued e-invoice exception is missing its document or country code.');
                }

                // A retry may run outside the original request (ops console
                // retry / queued work). Restore the tenant context the row
                // carries so every compliance write stays tenant-scoped —
                // same object-only restore the auto-submit path uses.
                $tenancy = app(\Stancl\Tenancy\Tenancy::class);
                $previousTenant = $tenancy->tenant;
                $previousInitialized = $tenancy->initialized;
                $restoredTenant = false;

                if (! $tenancy->tenant && $exception->tenant_id) {
                    $tenant = \App\Models\Tenant::find($exception->tenant_id);

                    if ($tenant) {
                        $tenancy->tenant = $tenant;
                        $tenancy->initialized = true;
                        app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
                        $restoredTenant = true;
                    }
                }

                try {
                    $service = app(ComplianceService::class);

                    $result = match ($documentType) {
                        'credit_note' => $service->submitCreditNote(\App\Models\CustomerCreditNote::findOrFail($documentId), $countryCode),
                        'refund_note' => $service->submitRefundNote(\App\Models\Payment::findOrFail($documentId), $countryCode),
                        default => $service->submitInvoice(\App\Models\Invoice::findOrFail($documentId), $countryCode),
                    };

                    if (($result['success'] ?? false) !== true) {
                        throw new \RuntimeException((string) ($result['message'] ?? 'E-invoice submission failed.'));
                    }
                } finally {
                    if ($restoredTenant) {
                        $tenancy->tenant = $previousTenant;
                        $tenancy->initialized = $previousInitialized;
                    }
                }
            },
        );
    }

    /**
     * Register Singapore (SG) plugin implementations
     */
    private function registerSingaporePlugins(CountryPluginRegistry $registry): void
    {
        $registry->register('SG', [
            'validator' => SFRSValidationService::class,
            'mapper' => SFRSInvoiceMapper::class,
            'provider' => PeppolSubmissionProvider::class,
            'sync' => PeppolStatusSync::class,
            'exporter' => XBRLReportExporter::class,
        ]);
    }

    /**
     * Register Malaysia (MY) plugin implementations
     */
    private function registerMalaysiaPlugins(CountryPluginRegistry $registry): void
    {
        $registry->register('MY', [
            'validator' => MFRSValidationService::class,
            'mapper' => MyInvoiceMapper::class,
            'provider' => MyInvoiceSubmissionProvider::class,
            'sync' => MyInvoiceStatusSync::class,
            'exporter' => MFRSReportExporter::class,
        ]);
    }

    /**
     * Register event listeners for compliance workflow.
     *
     * InvoicePosted, CreditNotePosted, RefundPosted → auto-submit to each
     *   country plugin where CountryConfiguration.is_enabled = true AND
     *   settings.auto_submit = true.
     *
     * #667 (EI5): the submission is never run inline in the posting request.
     * The listener records the compliance document as `queued` and dispatches
     * SubmitEInvoiceJob, which retries with backoff and marks the document
     * `failed` (with notification and platform exception) on terminal failure.
     */
    private function registerEventListeners(): void
    {
        Event::listen(InvoicePosted::class, function (InvoicePosted $event) {
            $this->autoSubmit($event->invoice, 'invoice', $event->tenantId);
        });

        // #516 (CN7): a posted credit note is a statutory document of its own
        // (MyInvois type 02) and must reference the original invoice's UUID, so
        // it follows the same auto_submit rules as an invoice.
        Event::listen(CreditNotePosted::class, function (CreditNotePosted $event) {
            $this->autoSubmit($event->creditNote, 'credit_note', $event->tenantId);
        });

        // #816 (EP5): money returned to the buyer is a refund note (MyInvois
        // type 04), so a posted customer refund follows the same auto_submit
        // rules as an invoice or a credit note.
        Event::listen(RefundPosted::class, function (RefundPosted $event) {
            $this->autoSubmit($event->refundPayment, 'refund_note', $event->tenantId);
        });
    }

    /**
     * Queue a posted document for every country plugin the tenant has enabled
     * with auto_submit on. Shared by the invoice, credit note and refund
     * listeners so the country rules cannot drift apart.
     */
    private function autoSubmit(
        \App\Models\Invoice|\App\Models\CustomerCreditNote|\App\Models\Payment $source,
        string $documentType,
        ?string $eventTenantId = null,
    ): void {
        $tenantId = $eventTenantId ?? tenancy()->tenant?->id;
        if (! $tenantId) {
            return;
        }

        // #516 (CN7): the posting path tears the tenancy context down before
        // the post-commit dispatch, so restore it while queueing — every
        // compliance write is tenant-scoped, and the queued job carries the
        // tenant it was dispatched under. Only the context object is set —
        // stancl's initialize()/end() would reset the schema and connection
        // for the request still in flight.
        $tenancy = app(\Stancl\Tenancy\Tenancy::class);
        $previousTenant = $tenancy->tenant;
        $previousInitialized = $tenancy->initialized;
        $restoredTenant = false;

        if (! $tenancy->tenant && $eventTenantId) {
            try {
                $tenant = \App\Models\Tenant::find($eventTenantId);

                if ($tenant) {
                    $tenancy->tenant = $tenant;
                    $tenancy->initialized = true;
                    $this->app->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
                    $restoredTenant = true;
                }
            } catch (\Throwable $e) {
                \Log::error('Compliance auto-submit could not restore the tenant context', [
                    'document_type' => $documentType,
                    'document_id' => $source->id,
                    'tenant_id' => $eventTenantId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->queueForEnabledCountries($source, $documentType, (string) $tenantId);
        } finally {
            if ($restoredTenant) {
                $tenancy->tenant = $previousTenant;
                $tenancy->initialized = $previousInitialized;
            }
        }
    }

    private function queueForEnabledCountries(
        \App\Models\Invoice|\App\Models\CustomerCreditNote|\App\Models\Payment $source,
        string $documentType,
        string $tenantId,
    ): void {
        /** @var ComplianceService $complianceService */
        $complianceService = app(ComplianceService::class);

        foreach ($complianceService->autoSubmitCountryCodes() as $countryCode) {
            try {
                // Idempotent: an invoice's document was already created as
                // queued inside its posting transaction.
                $complianceService->queueForAutoSubmit($documentType, $source, $countryCode);

                SubmitEInvoiceJob::dispatch($tenantId, $documentType, (int) $source->id, $countryCode);
            } catch (\Throwable $e) {
                // The job could not be queued — the document stays `queued`
                // (listed by the daily digest) and the accountant gets the
                // platform exception with its re-submit retry (#902).
                \Log::error('Compliance auto-submit could not be queued', [
                    'document_type' => $documentType,
                    'document_id' => $source->id,
                    'country_code' => $countryCode,
                    'error' => $e->getMessage(),
                ]);

                SubmitEInvoiceJob::reportException($documentType, (int) $source->id, $countryCode, $e->getMessage(), null);
            }
        }
    }
}
