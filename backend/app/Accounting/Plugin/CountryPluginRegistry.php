<?php

namespace App\Accounting\Plugin;

use App\Accounting\Contracts\StatutoryReportExporterContract;
use Illuminate\Support\Facades\Log;

class CountryPluginRegistry
{
    protected array $plugins = [];

    protected array $implementations = [];

    public function register(string $countryCode, array $implementations): void
    {
        $this->plugins[strtoupper($countryCode)] = $implementations;
        Log::info("Registered country plugin: {$countryCode}", [
            'interfaces' => array_keys($implementations),
        ]);
    }

    public function resolve(string $countryCode, string $interface): ?object
    {
        $countryCode = strtoupper($countryCode);

        if (!isset($this->plugins[$countryCode])) {
            Log::warning("Plugin not found for country: {$countryCode}");
            return null;
        }

        if (!isset($this->plugins[$countryCode][$interface])) {
            throw new \InvalidArgumentException("Interface {$interface} not registered for {$countryCode}");
        }

        $implementation = $this->plugins[$countryCode][$interface];

        $cacheKey = "{$countryCode}.{$interface}";
        if (!isset($this->implementations[$cacheKey])) {
            if (is_object($implementation)) {
                $this->implementations[$cacheKey] = $implementation;
            } else {
                $this->implementations[$cacheKey] = app($implementation);
            }
        }

        return $this->implementations[$cacheKey];
    }
}
