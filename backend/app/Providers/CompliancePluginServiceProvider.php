<?php

namespace App\Providers;

use App\Accounting\Events\InvoicePosted;
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
     * InvoicePosted → auto-submit to each country plugin where
     *   CountryConfiguration.is_enabled = true AND settings.auto_submit = true
     */
    private function registerEventListeners(): void
    {
        Event::listen(InvoicePosted::class, function (InvoicePosted $event) {
            $tenantId = tenancy()->tenant?->id;
            if (! $tenantId) {
                return;
            }

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
                    $complianceService->submitInvoice($event->invoice, $config->country_code);
                } catch (\Throwable $e) {
                    \Log::error('Compliance auto-submit failed', [
                        'invoice_id' => $event->invoice->id,
                        'country_code' => $config->country_code,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
