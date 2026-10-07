<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 0 (hostile review 2026-10-06): the address backfill
 * report must not lose or overwrite anything:
 *  - soft-deleted customers and contacts are planned too, marked deleted;
 *  - "no site" applies only to the company's MAIN (default billing)
 *    address; a person at a delivery address gets a site;
 *  - a site a user already chose is kept, never replaced;
 *  - several defaults of one type, and JSON keys that would be dropped,
 *    are flagged for review;
 *  - the same street in another country is not the same address;
 *  - a main address that exists only as text is flagged, not guessed;
 *  - non-ISO country values are summarised for the mapping decision;
 *  - --redact removes personal data from the JSON;
 *  - nothing is written.
 */
class ContactAddressBackfillReportStep0Test extends TenantTestCase
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
            'name' => 'Step 0 Report Tenant',
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

        $this->reportPath = tempnam(sys_get_temp_dir(), 'w6-step0-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_step0_rules_and_no_writes(): void
    {
        $hospital = $this->postJson('/api/v1/customers', [
            'name' => 'Acme Hospital',
            'type' => 'company',
            'addresses' => [
                ['type' => 'billing', 'label' => 'Head Office', 'street' => '1 Finance Road', 'city' => 'Kuala Lumpur', 'country' => 'MY', 'is_default' => true],
                ['type' => 'shipping', 'label' => 'Warehouse', 'street' => '22 Warehouse Way', 'city' => 'Shah Alam', 'country' => 'MY', 'is_default' => true],
            ],
        ])->assertCreated();
        $hospitalId = (int) $hospital->json('id');
        $org = $hospital->json('core_contact_uuid');
        $warehouse = collect($hospital->json('addresses'))->firstWhere('type', 'shipping')['id'];

        // Person at the delivery address → gets a site (not "no site").
        $picker = $this->person($hospitalId, 'Picker', ['street' => '22 Warehouse Way', 'city' => 'Shah Alam', 'country' => 'MY']);
        // Same street and city as head office, but in Singapore → not the main address.
        $abroad = $this->person($hospitalId, 'Abroad', ['street' => '1 Finance Road', 'city' => 'Kuala Lumpur', 'country' => 'SG']);

        // A user already picked a site for this person (W3/W4).
        $chosen = $this->postJson("/api/v1/contacts/{$org}/addresses", ['address_type' => 'site', 'label' => 'Ward 7', 'line_1' => '7 Ward Lane', 'city' => 'Ipoh', 'country' => 'MY'])
            ->assertCreated()->json('data.id');
        $keeper = $this->person($hospitalId, 'Keeper', ['street' => '99 Other Road', 'city' => 'Ipoh', 'country' => 'MY'], $chosen);

        // Older data: two default billing addresses.
        $twoDefaults = $this->postJson('/api/v1/customers', ['name' => 'Two Defaults', 'type' => 'company'])->assertCreated();
        $twoDefaultsId = (int) $twoDefaults->json('id');
        $defaultA = DB::table('customer_addresses')->insertGetId(['customer_id' => $twoDefaultsId, 'type' => 'billing', 'street' => '1 A Road', 'country' => 'MY', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $defaultB = DB::table('customer_addresses')->insertGetId(['customer_id' => $twoDefaultsId, 'type' => 'billing', 'street' => '2 B Road', 'country' => 'MY', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Older JSON address with a name and phone that copying would drop.
        $storefront = $this->postJson('/api/v1/customers', ['name' => 'Web Buyer', 'type' => 'company'])->assertCreated();
        $storefrontId = (int) $storefront->json('id');
        DB::table('customers')->where('id', $storefrontId)->update([
            'shipping_address' => json_encode(['first_name' => 'Lee', 'phone' => '0123', 'address' => '5 Web Street', 'city' => 'Ipoh', 'country' => 'MY']),
        ]);

        // Main address only as text; a person with an address there.
        $textOnly = $this->postJson('/api/v1/customers', ['name' => 'Text Only', 'type' => 'company'])->assertCreated();
        $textOnlyId = (int) $textOnly->json('id');
        DB::table('customers')->where('id', $textOnlyId)->update(['billing_address' => "8 Text Road\nPenang"]);
        $textPerson = $this->person($textOnlyId, 'Text Person', ['street' => '8 Text Road', 'city' => 'Penang', 'country' => 'Malaysia']);

        // Soft-deleted customer with an address and a soft-deleted contact.
        $gone = $this->postJson('/api/v1/customers', [
            'name' => 'Gone Trading',
            'type' => 'company',
            'addresses' => [['type' => 'billing', 'street' => '3 Gone Road', 'city' => 'Melaka', 'country' => 'Malaysia', 'is_default' => true]],
        ])->assertCreated();
        $goneId = (int) $gone->json('id');
        $goneAddress = collect($gone->json('addresses'))->first()['id'];
        $goneContact = $this->person($goneId, 'Gone Person', ['street' => '4 Gone Lane', 'city' => 'Melaka', 'country' => 'MY']);
        DB::table('customer_contacts')->where('id', $goneContact)->update(['deleted_at' => now()]);
        DB::table('customers')->where('id', $goneId)->update(['deleted_at' => now()]);

        $before = $this->tableCounts();

        $this->artisan('contacts:address-backfill-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->assertExitCode(0);

        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');
        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];

        $people = collect($report['person_addresses'])->keyBy('customer_contact_id');
        $this->assertSame('create_site_and_link', $people[$picker]['action'], 'A person at the delivery address gets a site.');
        $this->assertSame([$warehouse, 'shipping', 'Warehouse'], [$people[$picker]['matches_company_address_id'], $people[$picker]['matches_company_address_type'], $people[$picker]['site_label']]);
        $this->assertSame('create_site_and_link', $people[$abroad]['action'], 'Same street in another country is not the main address.');
        $this->assertSame(['keep_existing_site', $chosen], [$people[$keeper]['action'], $people[$keeper]['site_address_id']]);
        $this->assertSame(['needs_review', 'main_address_is_text_only'], [$people[$textPerson]['action'], $people[$textPerson]['reason']]);
        $this->assertTrue($people[$goneContact]['contact_deleted']);
        $this->assertTrue($people[$goneContact]['customer_deleted']);

        $saved = collect($report['customer_addresses'])->keyBy('customer_address_id');
        foreach ([$defaultA, $defaultB] as $id) {
            $this->assertSame(['needs_review', 'several_default_addresses'], [$saved[$id]['action'], $saved[$id]['reason']]);
        }
        $this->assertTrue($saved[$goneAddress]['customer_deleted'], 'Addresses of deleted customers are planned too.');

        $json = collect($report['legacy_text_addresses'])->first(fn ($row) => $row['customer_id'] === $storefrontId);
        $this->assertSame(['needs_review', 'json_keys_would_be_dropped'], [$json['action'], $json['reason']]);
        $this->assertEqualsCanonicalizing(['first_name', 'phone'], $json['dropped_keys']);

        $this->assertSame(1, $report['summary']['deleted_customers_included']);
        $this->assertSame(['Malaysia' => 2], $report['summary']['non_iso_country_values']);
    }

    public function test_redacted_report_has_no_personal_data(): void
    {
        $customer = $this->postJson('/api/v1/customers', [
            'name' => 'Acme Hospital',
            'type' => 'company',
            'addresses' => [['type' => 'billing', 'street' => '1 Finance Road', 'city' => 'Kuala Lumpur', 'country' => 'Malaysia', 'is_default' => true]],
        ])->assertCreated();
        $this->person((int) $customer->json('id'), 'Siti Aminah', ['street' => '7 Clinic Lane', 'city' => 'Ipoh', 'country' => 'MY']);

        $this->artisan('contacts:address-backfill-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath, '--redact' => true])
            ->assertExitCode(0);

        $raw = (string) file_get_contents($this->reportPath);
        foreach (['Siti Aminah', '1 Finance Road', '7 Clinic Lane', 'Kuala Lumpur', 'Ipoh'] as $personal) {
            $this->assertStringNotContainsString($personal, $raw);
        }
        $report = json_decode($raw, true)[0];
        $this->assertSame(['Malaysia' => 1], $report['summary']['non_iso_country_values'], 'Country values stay readable for the mapping decision.');
        $this->assertNotEmpty($report['person_addresses'][0]['action']);
    }

    private function person(int $customerId, string $name, array $address, ?string $site = null): int
    {
        return (int) $this->postJson("/api/v1/customers/{$customerId}/contacts", array_merge([
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
        ], $address, $site ? ['site_address_id' => $site] : []))->assertCreated()->json('id');
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

        return $counts;
    }
}
