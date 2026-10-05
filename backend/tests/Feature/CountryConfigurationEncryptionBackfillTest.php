<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Accounting\CountryConfiguration;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Legacy plaintext `acct_country_configurations.settings` rows made every
 * invoice POST fail with 422 "The payload is invalid." AFTER the invoice was
 * written: the compliance auto-submit listener read the encrypted:array cast
 * outside its try/catch. The tenant migration must encrypt those rows once and
 * leave them readable through the model.
 */
class CountryConfigurationEncryptionBackfillTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Country Config Backfill Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_plaintext_settings_are_encrypted_and_readable_once(): void
    {
        if (! \Schema::hasTable('acct_country_configurations')) {
            $this->markTestSkipped('acct_country_configurations is not part of this schema.');
        }

        $id = DB::table('acct_country_configurations')->insertGetId([
            'tenant_id' => $this->tenantUuid,
            'country_code' => 'MY',
            'is_enabled' => true,
            'settings' => '{"auto_submit": false}',
            'api_credentials' => '{"client_id": "abc", "secret": "shh"}',
            'test_mode' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/tenant/2026_10_05_000200_encrypt_legacy_country_configuration_settings.php'
        );
        $migration->up();

        $raw = DB::table('acct_country_configurations')->where('id', $id)->first();
        $this->assertNotSame('{"auto_submit": false}', (string) $raw->settings, 'Plaintext settings must be encrypted.');

        $model = CountryConfiguration::findOrFail($id);
        $this->assertSame(['auto_submit' => false], $model->settings);
        $this->assertSame(['client_id' => 'abc', 'secret' => 'shh'], $model->api_credentials);

        // Idempotent: a second run must not re-encrypt (ciphertext is non-deterministic).
        $settingsBefore = (string) $raw->settings;
        $migration->up();
        $this->assertSame(
            $settingsBefore,
            (string) DB::table('acct_country_configurations')->where('id', $id)->value('settings')
        );
    }
}
