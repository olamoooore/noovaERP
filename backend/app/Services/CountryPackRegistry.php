<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Config-driven country pack resolver.
 * No hardcoded if-country conditionals — all behaviour derived from pack data.
 */
class CountryPackRegistry
{
    /**
     * Get a country pack by country code.
     */
    public function get(string $countryCode): ?object
    {
        return DB::table('country_packs')
            ->where('country_code', strtoupper($countryCode))
            ->where('is_active', true)
            ->first();
    }

    /**
     * List all active country packs.
     */
    public function listActive(): \Illuminate\Support\Collection
    {
        return DB::table('country_packs')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the statutory COA template for a country.
     */
    public function getCoaTemplate(string $countryCode): ?array
    {
        $pack = $this->get($countryCode);
        return $pack ? json_decode($pack->statutory_coa_template, true) : null;
    }

    /**
     * Get default tax rates for a country.
     */
    public function getDefaultTaxRates(string $countryCode): array
    {
        $pack = $this->get($countryCode);
        return $pack ? json_decode($pack->default_tax_rates, true) : [];
    }

    /**
     * Get mandatory tax types for a country.
     */
    public function getMandatoryTaxTypes(string $countryCode): array
    {
        $pack = $this->get($countryCode);
        return $pack ? json_decode($pack->mandatory_tax_types, true) : [];
    }

    /**
     * Get regulatory report mappings.
     */
    public function getRegulatoryReports(string $countryCode): array
    {
        $pack = $this->get($countryCode);
        return $pack ? json_decode($pack->regulatory_report_mappings, true) : [];
    }

    /**
     * Get e-invoicing requirements.
     */
    public function getEInvoicingRequirements(string $countryCode): ?array
    {
        $pack = $this->get($countryCode);
        return $pack ? json_decode($pack->e_invoicing_requirements, true) : null;
    }

    /**
     * Get numbering conventions.
     */
    public function getNumberingConventions(string $countryCode): ?array
    {
        $pack = $this->get($countryCode);
        return $pack ? json_decode($pack->numbering_conventions, true) : null;
    }

    /**
     * Check if e-invoicing is required for a country.
     */
    public function isEInvoicingRequired(string $countryCode): bool
    {
        $requirements = $this->getEInvoicingRequirements($countryCode);
        return $requirements['required'] ?? false;
    }

    /**
     * Validate that a country pack exists and is active.
     */
    public function exists(string $countryCode): bool
    {
        return $this->get($countryCode) !== null;
    }
}
