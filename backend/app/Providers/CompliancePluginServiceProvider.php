<?php

namespace App\Providers;

use App\Accounting\Events\CreditNotePosted;
use App\Accounting\Events\InvoicePosted;
use App\Accounting\Events\RefundPosted;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\CountryConfiguration;
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
     * InvoicePosted, CreditNotePosted → auto-submit to each country plugin
     *   where CountryConfiguration.is_enabled = true AND
     *   settings.auto_submit = true
     */
    private function registerEventListeners(): void
    {
        Event::listen(InvoicePosted::class, function (InvoicePosted $event) {
            $this->autoSubmit(
                fn (ComplianceService $service, string $countryCode) => $service->submitInvoice($event->invoice, $countryCode),
                'invoice',
                (int) $event->invoice->id,
            );
        });

        // #516 (CN7): a posted credit note is a statutory document of its own
        // (MyInvois type 02) and must reference the original invoice's UUID, so
        // it follows the same auto_submit rules as an invoice.
        Event::listen(CreditNotePosted::class, function (CreditNotePosted $event) {
            $this->autoSubmit(
                fn (ComplianceService $service, string $countryCode) => $service->submitCreditNote($event->creditNote, $countryCode),
                'credit_note',
                (int) $event->creditNote->id,
                $event->tenantId,
            );
        });

        // #816 (EP5): money returned to the buyer is a refund note (MyInvois
        // type 04), so a posted customer refund follows the same auto_submit
        // rules as an invoice or a credit note.
        Event::listen(RefundPosted::class, function (RefundPosted $event) {
            $this->autoSubmit(
                fn (ComplianceService $service, string $countryCode) => $service->submitRefundNote($event->refundPayment, $countryCode),
                'refund_note',
                (int) $event->refundPayment->id,
                $event->tenantId,
            );
        });
    }

    /**
     * Submit a posted document to every country plugin the tenant has enabled
     * with auto_submit on. Shared by the invoice and credit note listeners so
     * the country rules cannot drift apart.
     *
     * @param  callable(ComplianceService, string): mixed  $submit
     */
    private function autoSubmit(callable $submit, string $documentType, int $documentId, ?string $eventTenantId = null): void
    {
        $tenantId = $eventTenantId ?? tenancy()->tenant?->id;
        if (! $tenantId) {
            return;
        }

        // #516 (CN7): the posting path tears the tenancy context down before
        // the post-commit dispatch, so restore it for the duration of the
        // submission — every compliance write (documents, submissions, the
        // event trail) is tenant-scoped and would otherwise be written with no
        // tenant at all. Released again when the submission is done.
        // Only the context object is set — stancl's initialize()/end() would
        // reset the schema and connection for the request still in flight.
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
                    'document_id' => $documentId,
                    'tenant_id' => $eventTenantId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->submitToEnabledCountries($submit, $documentType, $documentId, $tenantId);
        } finally {
            if ($restoredTenant) {
                $tenancy->tenant = $previousTenant;
                $tenancy->initialized = $previousInitialized;
            }
        }
    }

    /**
     * @param  callable(ComplianceService, string): mixed  $submit
     */
    private function submitToEnabledCountries(callable $submit, string $documentType, int $documentId, string $tenantId): void
    {
        $enabledConfigs = CountryConfiguration::where('tenant_id', $tenantId)
            ->where('is_enabled', true)
            ->get();

        foreach ($enabledConfigs as $config) {
            // Only process if tenant_country_code matches — prevents cross-country submission
            $tenant = tenancy()->tenant;
            if ($tenant && $tenant->tenant_country_code &&
                strtoupper($tenant->tenant_country_code) !== strtoupper($config->country_code)) {
                continue;
            }

            $autoSubmit = $config->settings['auto_submit'] ?? false;
            if (! $autoSubmit) {
                continue;
            }

            try {
                /** @var ComplianceService $complianceService */
                $complianceService = app(ComplianceService::class);
                $submit($complianceService, $config->country_code);
            } catch (\Throwable $e) {
                \Log::error('Compliance auto-submit failed', [
                    'document_type' => $documentType,
                    'document_id' => $documentId,
                    'country_code' => $config->country_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
