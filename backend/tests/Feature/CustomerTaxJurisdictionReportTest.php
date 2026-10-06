<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 2: a read-only report for the tax agent listing the
 * customers whose tax jurisdiction comes from unrecognised country text, or
 * would change if tax used the shared customer address rule. It must flag
 * each case and write nothing.
 */
class CustomerTaxJurisdictionReportTest extends TenantTestCase
{
    private string $tenantId;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId,
            'name' => 'Tax Jurisdiction Report Tenant',
            'email' => $this->tenantId.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($admin, 'sanctum');

        $this->reportPath = tempnam(sys_get_temp_dir(), 'tax-jurisdiction-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_report_flags_country_text_and_rule_differences_and_writes_nothing(): void
    {
        $clean = $this->customerWithAddress('MY', true);
        $named = $this->customerWithAddress('Malaysia', true);
        $nonDefault = $this->customerWithAddress('SG', false);

        $legacy = Customer::factory()->create([
            'billing_address' => json_encode(['street' => '1 Old Road', 'city' => 'Singapore', 'country' => 'SG']),
            'shipping_address' => null,
        ]);
        $noAddress = Customer::factory()->create(['billing_address' => null, 'shipping_address' => null]);

        $before = [
            DB::table('customers')->count(),
            DB::table('customer_addresses')->count(),
            DB::table('tax_determination_results')->count(),
        ];

        $this->artisan('tax:customer-jurisdiction-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);

        $this->assertSame($before, [
            DB::table('customers')->count(),
            DB::table('customer_addresses')->count(),
            DB::table('tax_determination_results')->count(),
        ], 'The report must not write anything.');

        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $rows = collect($report['customers'])->keyBy('customer_id');

        $this->assertArrayNotHasKey($clean->id, $rows->all(), 'A recognised default billing country is not flagged.');
        $this->assertArrayNotHasKey($noAddress->id, $rows->all());

        $this->assertSame('MALAYSIA', $rows[$named->id]['current_jurisdiction'], 'Today the text itself becomes the jurisdiction.');
        $this->assertNull($rows[$named->id]['resolver_jurisdiction']);
        $this->assertEqualsCanonicalizing(['unrecognised_country_text', 'jurisdiction_would_change'], $rows[$named->id]['flags']);

        foreach ([$nonDefault, $legacy] as $customer) {
            $this->assertNull($rows[$customer->id]['current_jurisdiction'], 'Today only the default saved billing address counts.');
            $this->assertSame('SG', $rows[$customer->id]['resolver_jurisdiction']);
            $this->assertEqualsCanonicalizing(['country_only_outside_default_billing', 'jurisdiction_would_change'], $rows[$customer->id]['flags']);
        }

        $this->assertSame(['Malaysia' => 1], $report['summary']['unrecognised_values']);
        $this->assertSame(3, $report['summary']['jurisdiction_would_change']);
    }

    public function test_report_flags_vendor_country_text_and_non_default_addresses(): void
    {
        $clean = $this->vendorWithAddress('MY', true);
        $named = $this->vendorWithAddress('Malaysia', true);
        $firstOnly = $this->vendorWithAddress('SG', false);
        $noAddress = Vendor::factory()->create();

        $before = [DB::table('vendors')->count(), DB::table('vendor_addresses')->count()];

        $this->artisan('tax:customer-jurisdiction-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->assertExitCode(0);

        $this->assertSame($before, [DB::table('vendors')->count(), DB::table('vendor_addresses')->count()], 'The report must not write anything.');

        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $rows = collect($report['vendors'])->keyBy('vendor_id');

        $this->assertArrayNotHasKey($clean->id, $rows->all());
        $this->assertArrayNotHasKey($noAddress->id, $rows->all());

        $this->assertSame('MALAYSIA', $rows[$named->id]['current_jurisdiction'], 'Today the text itself becomes the input-tax jurisdiction.');
        $this->assertSame(['unrecognised_country_text'], $rows[$named->id]['flags']);

        $this->assertSame('SG', $rows[$firstOnly->id]['current_jurisdiction']);
        $this->assertSame(['country_from_non_default_address'], $rows[$firstOnly->id]['flags']);

        $this->assertSame(['Malaysia' => 1], $report['vendor_summary']['unrecognised_values']);
        $this->assertSame(2, $report['vendor_summary']['vendors_flagged']);
    }

    private function vendorWithAddress(string $country, bool $default): Vendor
    {
        $vendor = Vendor::factory()->create();
        VendorAddress::create([
            'vendor_id' => $vendor->id,
            'type' => 'billing',
            'street' => '1 Probe Road',
            'city' => 'Probe City',
            'country' => $country,
            'is_default' => $default,
        ]);

        return $vendor;
    }

    private function customerWithAddress(string $country, bool $default): Customer
    {
        $customer = Customer::factory()->create(['billing_address' => null, 'shipping_address' => null]);

        $this->postJson("/api/v1/customers/{$customer->id}/addresses", [
            'type' => 'billing',
            'street' => '1 Probe Road',
            'city' => 'Probe City',
            'country' => $country,
            'is_default' => $default,
        ])->assertCreated();

        return $customer;
    }
}
