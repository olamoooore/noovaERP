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
     * Issue #698 (EC1): the contract each plugin slot must satisfy. A country
     * plugin that registers a missing or wrong-typed class used to fail only
     * when a submission resolved it (every Singapore submission crashed at
     * submit time because its validator class did not exist). Registrations are
     * now validated as they are made, so a broken plugin fails loudly at boot.
     */
    private const SLOT_CONTRACTS = [
        'validator' => CountryValidationContract::class,
        'mapper' => InvoiceSchemaMapperContract::class,
        'provider' => SubmissionProviderContract::class,
        'sync' => SubmissionStatusSyncContract::class,
        'exporter' => StatutoryReportExporterContract::class,
    ];

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
        $countryCode = strtoupper($countryCode);

        foreach ($implementations as $slot => $implementation) {
            $expectedContract = self::SLOT_CONTRACTS[$slot] ?? null;

            if ($expectedContract === null) {
                throw new \InvalidArgumentException(
                    "Unknown plugin slot '{$slot}' for country {$countryCode}; expected one of: "
                    .implode(', ', array_keys(self::SLOT_CONTRACTS)).'.'
                );
            }

            if (is_object($implementation)) {
                if (! $implementation instanceof $expectedContract) {
                    throw new \InvalidArgumentException(
                        "Plugin '{$slot}' for {$countryCode} must implement {$expectedContract}, got ".get_class($implementation).'.'
                    );
                }

                continue;
            }

            if (! is_string($implementation) || ! class_exists($implementation)) {
                throw new \InvalidArgumentException(
                    "Plugin '{$slot}' for {$countryCode} points at a class that does not exist: "
                    .(is_string($implementation) ? $implementation : gettype($implementation)).'.'
                );
            }

            if (! is_subclass_of($implementation, $expectedContract)) {
                throw new \InvalidArgumentException(
                    "Plugin '{$slot}' for {$countryCode} must implement {$expectedContract}, got {$implementation}."
                );
            }
        }

        $this->plugins[$countryCode] = $implementations;

        // The registry is also exercised by plain PHPUnit tests that boot no
        // application; only log when a logger is actually available.
        if (function_exists('app') && app()->bound('log')) {
            Log::info("Registered country plugin: {$countryCode}", [
                'interfaces' => array_keys($implementations),
            ]);
        }
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
