<?php

namespace Tests\Feature;

use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\CountryConfiguration;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class MyInvoisConfigurationTest extends TenantTestCase
{
    public function test_credentials_can_replace_legacy_plaintext_configuration(): void
    {
        $this->bindTenantContext('myinvois-legacy-tenant');

        $id = DB::table('acct_country_configurations')->insertGetId([
            'tenant_id' => 'myinvois-legacy-tenant',
            'country_code' => 'MY',
            'provider_name' => 'myinvoice',
            'is_enabled' => true,
            'api_credentials' => json_encode(['old' => 'plaintext']),
            'settings' => json_encode(['auto_submit' => false]),
            'test_mode' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $config = app(ComplianceService::class)->updateCountryConfig('MY', [
            'provider_name' => 'myinvoice',
            'is_enabled' => true,
            'test_mode' => true,
            'api_credentials' => [
                'myinvoice_client_id' => 'client-id',
                'myinvoice_client_secret' => 'client-secret',
                'supplier_tin' => 'C1234567890',
                'supplier_name' => 'Example Supplier',
            ],
            'settings' => [],
        ]);

        $this->assertSame($id, $config->id);
        $this->assertSame('client-id', $config->api_credentials['myinvoice_client_id']);
        $this->assertSame('client-secret', $config->api_credentials['myinvoice_client_secret']);
        $this->assertSame([], $config->settings);

        $raw = DB::table('acct_country_configurations')->where('id', $id)->first();
        $this->assertStringNotContainsString('client-secret', $raw->api_credentials);
        $this->assertStringNotContainsString('auto_submit', $raw->settings);
    }
}
