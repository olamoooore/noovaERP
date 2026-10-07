<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactAddress;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 §4 step 1: the address backfill is REPORT ONLY until the plan
 * is reviewed. The report must give every address row exactly one action,
 * flag anything that needs a human decision (never guess), and write
 * nothing to the database.
 *
 * Data is created through the real HTTP routes where they still exist; older
 * states that the current API can no longer produce (free-text legacy
 * columns, an unlinked customer) are seeded directly.
 */
class ContactAddressBackfillReportTest extends TenantTestCase
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
            'name' => 'Address Backfill Report Tenant',
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

        $this->reportPath = tempnam(sys_get_temp_dir(), 'w6-report-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_report_plans_every_address_and_writes_nothing(): void
    {
        // Hospital: linked to an organisation, with saved addresses and people.
        $hospital = $this->postJson('/api/v1/customers', [
            'name' => 'Acme Hospital',
            'type' => 'company',
            'addresses' => [
                ['type' => 'billing', 'label' => 'Head Office', 'street' => '1 Finance Road', 'city' => 'Kuala Lumpur', 'zip' => '50000', 'country' => 'MY', 'is_default' => true],
                ['type' => 'shipping', 'street' => '22 Warehouse Way', 'city' => 'Shah Alam', 'country' => 'Malaysia', 'is_default' => true],
                ['type' => 'other', 'street' => '9 Store Room', 'city' => 'Klang', 'country' => 'MY'],
            ],
        ])->assertCreated();
        $hospitalId = (int) $hospital->json('id');
        $org = $hospital->json('core_contact_uuid');
        [$billing, $shipping, $other] = collect($hospital->json('addresses'))->sortBy('id')->pluck('id')->all();

        $site = $this->postJson("/api/v1/contacts/{$org}/addresses", [
            'address_type' => 'site', 'label' => 'Main Clinic', 'line_1' => '7 Clinic Lane', 'city' => 'Ipoh', 'postal_code' => '30000', 'country' => 'MY',
        ])->assertCreated()->json('data.id');

        $drTan = $this->addPerson($hospitalId, 'Dr Tan', ['street' => '7, Clinic Lane', 'city' => 'IPOH', 'zip' => '30000', 'country' => 'MY']);
        $siti = $this->addPerson($hospitalId, 'Siti', ['street' => '1 Finance Road', 'city' => 'Kuala Lumpur', 'zip' => '50000', 'country' => 'MY']);
        $ali = $this->addPerson($hospitalId, 'Ali', ['street' => '3 Ward Street', 'city' => 'Penang', 'country' => 'MY']);
        $ahKow = $this->addPerson($hospitalId, 'Ah Kow', ['street' => '3 WARD STREET', 'city' => 'penang', 'country' => 'MY']);
        $this->addPerson($hospitalId, 'No Address');

        // Older free-text column next to a saved billing address: superseded.
        DB::table('customers')->where('id', $hospitalId)->update(['billing_address' => "1 Finance Road\nKuala Lumpur"]);

        // Clinic: linked; already partly migrated, and a duplicate in the Contact Master.
        $clinic = $this->postJson('/api/v1/customers', [
            'name' => 'Bayu Clinic',
            'type' => 'company',
            'addresses' => [
                ['type' => 'billing', 'street' => '5 Bayu Road', 'city' => 'Ipoh', 'country' => 'MY', 'is_default' => true],
                ['type' => 'shipping', 'street' => '6 Bayu Road', 'city' => 'Ipoh', 'country' => 'MY', 'is_default' => true],
            ],
        ])->assertCreated();
        $clinicOrg = $clinic->json('core_contact_uuid');
        [$clinicBilling, $clinicShipping] = collect($clinic->json('addresses'))->sortBy('id')->pluck('id')->all();
        $migrated = $this->contactAddress($clinicOrg, 'billing', '5 Bayu Road', 'Ipoh', ['legacy_customer_address_id' => $clinicBilling]);
        $duplicate = $this->contactAddress($clinicOrg, 'delivery', '6 BAYU ROAD', 'ipoh');

        // Old customer: not linked to an organisation, older text addresses only.
        $old = $this->postJson('/api/v1/customers', ['name' => 'Old Trading', 'type' => 'company'])->assertCreated();
        $oldId = (int) $old->json('id');
        DB::table('customers')->where('id', $oldId)->update([
            'core_contact_uuid' => null,
            'billing_address' => "12 Old Street\nKuala Lumpur",
            'shipping_address' => json_encode(['street' => '13 Old Street', 'city' => 'Kuala Lumpur', 'country' => 'MY']),
        ]);

        // Linked customer whose only address is an older JSON text column.
        $json = $this->postJson('/api/v1/customers', ['name' => 'Json Supplies', 'type' => 'company'])->assertCreated();
        $jsonId = (int) $json->json('id');
        DB::table('customers')->where('id', $jsonId)->update([
            'billing_address' => json_encode(['street' => '40 Json Road', 'city' => 'Melaka', 'country' => 'MY']),
            'shipping_address' => str_repeat('a', 300),
        ]);

        // Linked customer whose older addresses are free text.
        $text = $this->postJson('/api/v1/customers', ['name' => 'Text Supplies', 'type' => 'company'])->assertCreated();
        $textId = (int) $text->json('id');
        DB::table('customers')->where('id', $textId)->update([
            'billing_address' => "12 Text Road\r\n\nKuala Lumpur",
            'shipping_address' => "Unit 1\nBlock 2\nJalan 3\nKlang",
        ]);

        $before = $this->tableCounts();

        $this->artisan('contacts:address-backfill-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);

        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');

        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $this->assertSame('report_only', $report['mode']);

        $saved = collect($report['customer_addresses'])->keyBy('customer_address_id');
        $this->assertSame('copy', $saved[$billing]['action']);
        $this->assertSame('billing', $saved[$billing]['target_type']);
        $this->assertSame(['needs_review', 'country_not_iso_alpha2'], [$saved[$shipping]['action'], $saved[$shipping]['reason']]);
        $this->assertSame(['needs_review', 'type_other_has_no_contact_master_equivalent'], [$saved[$other]['action'], $saved[$other]['reason']]);
        $this->assertSame(['already_migrated', $migrated], [$saved[$clinicBilling]['action'], $saved[$clinicBilling]['contact_address_id']]);
        $this->assertSame(['duplicate_of_existing', $duplicate], [$saved[$clinicShipping]['action'], $saved[$clinicShipping]['contact_address_id']]);

        $people = collect($report['person_addresses'])->keyBy('customer_contact_id');
        $this->assertCount(4, $people, 'Only people who hold an address are planned.');
        $this->assertSame(['link_existing_site', $site], [$people[$drTan]['action'], $people[$drTan]['site_address_id']]);
        // Revised recommendation #6 (approved 2026-10-06): a person at the
        // company's own address needs no site — no copy of the billing
        // address under Sites.
        $this->assertSame('no_site_at_company_address', $people[$siti]['action']);
        $this->assertSame($billing, $people[$siti]['matches_company_address_id']);
        // People at the same new address share ONE site, named after the address.
        foreach ([$ali, $ahKow] as $id) {
            $this->assertSame('create_site_and_link', $people[$id]['action']);
            $this->assertSame([$ali, $ahKow], $people[$id]['people_sharing_site']);
        }
        $this->assertSame($people[$ali]['new_site_key'], $people[$ahKow]['new_site_key']);
        $this->assertSame('3 Ward Street, Penang', $people[$ali]['site_label']);

        $legacy = collect($report['legacy_text_addresses'])->map(fn ($row) => [$row['customer_id'], $row['source_type'], $row['action'], $row['reason'] ?? null])->all();
        $this->assertContains([$hospitalId, 'billing', 'superseded_by_structured', null], $legacy);
        $this->assertContains([$oldId, 'billing', 'needs_review', 'customer_not_linked_to_organisation'], $legacy);
        $this->assertContains([$oldId, 'shipping', 'needs_review', 'customer_not_linked_to_organisation'], $legacy);
        $this->assertContains([$jsonId, 'billing', 'copy', null], $legacy);
        // Revised recommendation #3: free text is split on line breaks into
        // at most three lines of at most 255 characters, never interpreted.
        $this->assertContains([$jsonId, 'shipping', 'needs_review', 'line_too_long'], $legacy);
        $this->assertContains([$textId, 'shipping', 'needs_review', 'more_than_three_lines'], $legacy);
        $textBilling = collect($report['legacy_text_addresses'])->first(fn ($row) => $row['customer_id'] === $textId && $row['source_type'] === 'billing');
        $this->assertSame(['copy_text_as_lines', ['12 Text Road', 'Kuala Lumpur']], [$textBilling['action'], $textBilling['lines']]);

        $this->assertContains($oldId, $report['customers_without_organisation']);
    }

    private function addPerson(int $customerId, string $name, array $address = []): int
    {
        return (int) $this->postJson("/api/v1/customers/{$customerId}/contacts", array_merge([
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
        ], $address))->assertCreated()->json('id');
    }

    private function contactAddress(string $orgId, string $type, string $line1, string $city, array $metadata = []): string
    {
        return ContactAddress::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $orgId,
            'address_type' => $type,
            'line_1' => $line1,
            'city' => $city,
            'country' => 'MY',
            'metadata' => $metadata ?: null,
        ])->id;
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['customers', 'customer_addresses', 'customer_contacts', 'contact_addresses', 'contact_relationships'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['customers_billing_text'] = DB::table('customers')->whereNotNull('billing_address')->count();
        $counts['contacts_with_street'] = DB::table('customer_contacts')->whereNotNull('street')->count();

        return $counts;
    }
}
