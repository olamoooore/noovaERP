<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

/**
 * Country Configuration - Stores country-specific settings per tenant
 */
class CountryConfiguration extends Model
{
    protected $table = 'acct_country_configurations';

    protected $fillable = [
        'tenant_id',
        'country_code',
        'provider_name',        // peppol, myinvoice, etc
        'is_enabled',
        'api_credentials',      // JSON - encrypted credentials
        'settings',             // JSON - provider-specific settings
        'test_mode',
        'last_synced_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'test_mode' => 'boolean',
        'api_credentials' => 'encrypted:array',
        'settings' => 'encrypted:array',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Check if provider is enabled
     */
    public function isEnabled(): bool
    {
        return $this->is_enabled === true;
    }

    /**
     * Get encrypted credentials
     */
    public function getCredentials(): array
    {
        return $this->api_credentials ?? [];
    }

    public function getProviderContext(): array
    {
        return array_merge(
            $this->settings ?? [],
            $this->api_credentials ?? [],
            [
                'provider_name' => $this->provider_name,
                'test_mode' => $this->test_mode,
                'is_enabled' => $this->is_enabled,
            ]
        );
    }

    /**
     * Update credentials safely
     */
    public function updateCredentials(array $credentials): void
    {
        $this->api_credentials = $credentials;
        $this->save();
    }
}
