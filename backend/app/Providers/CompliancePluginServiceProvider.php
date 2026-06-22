<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Accounting\Services\ComplianceService;
use Modules\AccountingCountry\Singapore\Services\XBRLReportExporter;

class CompliancePluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!config('compliance.enabled', false)) {
            return;
        }

        $this->app->singleton(ComplianceService::class, function ($app) {
            return new ComplianceService($app->make(CountryPluginRegistry::class));
        });

        $this->app->singleton(CountryPluginRegistry::class, function ($app) {
            $registry = new CountryPluginRegistry();

            $this->registerSingaporePlugins($registry);

            return $registry;
        });

        $this->app->bind('compliance', fn($app) => $app->make(ComplianceService::class));
    }

    private function registerSingaporePlugins(CountryPluginRegistry $registry): void
    {
        $registry->register('SG', [
            'exporter' => XBRLReportExporter::class,
        ]);
    }
}
