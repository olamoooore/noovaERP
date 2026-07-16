<?php

namespace App\Accounting\Plugin;

use App\Accounting\Contracts\CountryValidationContract;
use App\Accounting\Contracts\InvoiceSchemaMapperContract;
use App\Accounting\Contracts\StatutoryReportExporterContract;
use App\Accounting\Contracts\SubmissionProviderContract;
use App\Accounting\Contracts\SubmissionStatusSyncContract;
use Illuminate\Support\Facades\Log;

/**
 * Plugin Registry - Loads and resolves country-specific plugins
 * Maintains mappings of country codes to plugin implementations
 */
class CountryPluginRegistry
{
    /**
     * Registered plugins by country code
     */
    protected array $plugins = [];

    /**
     * Plugin implementations
     */
    protected array $implementations = [];

    /**
     * Register a country plugin
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 (e.g. SG, MY)
     * @param  array  $implementations  {
     *                                  validator: CountryValidationContract class,
     *                                  mapper: InvoiceSchemaMapperContract class,
     *                                  provider: SubmissionProviderContract class,
     *                                  sync: SubmissionStatusSyncContract class,
     *                                  exporter: StatutoryReportExporterContract class
     *                                  }
     */
    public function register(string $countryCode, array $implementations): void
    {
        $this->plugins[strtoupper($countryCode)] = $implementations;
        Log::info("Registered country plugin: {$countryCode}", [
            'interfaces' => array_keys($implementations),
        ]);
    }

    /**
     * Resolve plugin instance for a country
     *
     * @param  string  $interface  One of: validator, mapper, provider, sync, exporter
     *
     * @throws \InvalidArgumentException
     */
    public function resolve(string $countryCode, string $interface): ?object
    {
        $countryCode = strtoupper($countryCode);

        if (! isset($this->plugins[$countryCode])) {
            Log::warning("Plugin not found for country: {$countryCode}");

            return null;
        }

        if (! isset($this->plugins[$countryCode][$interface])) {
            throw new \InvalidArgumentException("Interface {$interface} not registered for {$countryCode}");
        }

        $implementation = $this->plugins[$countryCode][$interface];

        // Cached instances
        $cacheKey = "{$countryCode}.{$interface}";
        if (! isset($this->implementations[$cacheKey])) {
            if (is_object($implementation)) {
                $this->implementations[$cacheKey] = $implementation;
            } else {
                $this->implementations[$cacheKey] = app($implementation);
            }
        }

        return $this->implementations[$cacheKey];
    }

    /**
     * Get all validator, mapper, provider, sync, and exporters for a country
     */
    public function resolveAll(string $countryCode): array
    {
        return [
            'validator' => $this->resolve($countryCode, 'validator'),
            'mapper' => $this->resolve($countryCode, 'mapper'),
            'provider' => $this->resolve($countryCode, 'provider'),
            'sync' => $this->resolve($countryCode, 'sync'),
            'exporter' => $this->resolve($countryCode, 'exporter'),
        ];
    }

    /**
     * Check if plugin exists for country
     */
    public function has(string $countryCode): bool
    {
        return isset($this->plugins[strtoupper($countryCode)]);
    }

    /**
     * Get registered countries
     *
     * @return string[]
     */
    public function getRegisteredCountries(): array
    {
        return array_keys($this->plugins);
    }
}
