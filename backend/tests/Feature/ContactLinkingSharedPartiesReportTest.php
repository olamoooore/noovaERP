<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 3, decision inputs (hostile review of the L1–L10
 * recommendations, 2026-10-06). The linking report must count:
 *
 *  - L2: companies that are BOTH a customer and a vendor —
 *      * `already_shared`: one Contact Master record linked from a customer
 *        and a vendor, with the identity fields on which the two sides
 *        disagree (today the customer side overwrites the record on every
 *        edit and the vendor side never updates it);
 *      * `separate_records`: a customer and a vendor with the same
 *        registration number or name on DIFFERENT (or no) records —
 *        vendor create never matches, so these are invisible elsewhere;
 *  - L3: for links to a soft-deleted record, what still hangs off it
 *    (addresses, relationships, sites) — the objective restore rule.
 *
 * The report must write nothing.
 */
class ContactLinkingSharedPartiesReportTest extends TenantTestCase
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
            'name' => 'Shared Parties Tenant',
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

        $this->reportPath = tempnam(sys_get_temp_dir(), 'linking-shared-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_report_counts_customer_vendor_parties_and_deleted_record_dependents(): void
    {
        // Same company as customer and vendor, on two separate records.
        $customer = $this->customer(['name' => 'Tan Holdings Sdn Bhd', 'company_registration_number' => '201901012345']);
        $vendor = $this->vendor(['name' => 'TAN HOLDINGS SDN BHD', 'company_registration_number' => '2019-0101-2345']);
        $this->assertNotSame(Customer::findOrFail($customer)->core_contact_uuid, Vendor::findOrFail($vendor)->core_contact_uuid, 'Precondition: vendor create never matches.');

        // One record shared by a customer and a vendor, with disagreeing values.
        $sharedCustomer = $this->customer(['name' => 'Bee Supplies Trading', 'email' => 'sales@bee.test']);
        $sharedVendor = $this->vendor(['name' => 'Bee Supplies', 'email' => 'accounts@bee.test']);
        $sharedOrg = Customer::findOrFail($sharedCustomer)->core_contact_uuid;
        DB::table('vendors')->where('id', $sharedVendor)->update(['core_contact_uuid' => $sharedOrg]);

        // Unrelated vendor: no pair.
        $this->vendor(['name' => 'Lonely Parts']);

        // L3: a deleted record with a relationship, and one with nothing.
        $withPeople = $this->customer(['name' => 'Deleted With People']);
        $this->postJson("/api/v1/customers/{$withPeople}/contacts", ['name' => 'Staff One', 'email' => 'staff.one@example.test'])->assertCreated();
        CoreContact::whereKey(Customer::findOrFail($withPeople)->core_contact_uuid)->first()->delete();
        $bare = $this->customer(['name' => 'Deleted Bare']);
        CoreContact::whereKey(Customer::findOrFail($bare)->core_contact_uuid)->first()->delete();

        $report = $this->runReport();
        $parties = $report['customer_vendor_parties'];

        $pair = collect($parties['separate_records'])->first(fn ($p) => $p['customer_id'] === $customer && $p['vendor_id'] === $vendor);
        $this->assertNotNull($pair, 'A customer and a vendor with the same registration number on two records are listed.');
        $this->assertEqualsCanonicalizing(['registration_number', 'name'], $pair['matched_on']);

        $shared = collect($parties['already_shared'])->firstWhere('contact_id', $sharedOrg);
        $this->assertNotNull($shared);
        $this->assertSame([$sharedCustomer], $shared['customer_ids']);
        $this->assertSame([$sharedVendor], $shared['vendor_ids']);
        $this->assertEqualsCanonicalizing(['name', 'email'], $shared['differing_fields']);

        $this->assertSame(1, $parties['summary']['already_shared']);
        $this->assertSame(1, $parties['summary']['separate_records']);

        $orgs = collect($report['organisations'])->keyBy('customer_id');
        $this->assertSame('linked_contact_deleted', $orgs[$withPeople]['reason']);
        $this->assertSame(1, $orgs[$withPeople]['dependents']['relationships']);
        $this->assertSame(['addresses' => 0, 'relationships' => 0, 'sites' => 0], $orgs[$bare]['dependents']);
    }

    /** @return array<string, mixed> */
    private function runReport(): array
    {
        $before = $this->tableCounts();
        $this->artisan('contacts:linking-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);
        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');

        return json_decode((string) file_get_contents($this->reportPath), true)[0];
    }

    private function customer(array $payload): int
    {
        return (int) $this->postJson('/api/v1/customers', $payload + ['type' => 'company'])->assertCreated()->json('id');
    }

    private function vendor(array $payload): int
    {
        return (int) $this->postJson('/api/v1/accounting/vendors', $payload)->assertCreated()->json('data.id');
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['customers', 'vendors', 'customer_contacts', 'core_contacts', 'contact_relationships', 'contact_addresses'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['linked_vendors'] = DB::table('vendors')->whereNotNull('core_contact_uuid')->count();

        return $counts;
    }
}
