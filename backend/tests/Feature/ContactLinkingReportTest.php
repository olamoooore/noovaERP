<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 pre-step: before any bulk linking of unlinked customers and
 * customer contacts to the Contact Master, a REPORT predicts the outcome and
 * flags the risky cases found in the hostile review:
 *  - a new organisation that looks like one already in the Contact Master;
 *  - a tier-1 (email/mobile) person match that crosses companies or names;
 *  - unlinked contacts sharing an email/mobile (would collapse into one);
 *  - legacy category/type values the sync would reject.
 * The report must write nothing.
 *
 * Linked data is created through the real HTTP routes; unlinked legacy rows
 * (which the current API can no longer produce) are inserted directly.
 */
class ContactLinkingReportTest extends TenantTestCase
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
            'name' => 'Linking Report Tenant',
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

        $this->reportPath = tempnam(sys_get_temp_dir(), 'linking-report-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_report_predicts_linking_flags_risky_cases_and_writes_nothing(): void
    {
        // Linked customer with a linked person who uses a shared mailbox.
        $linked = $this->postJson('/api/v1/customers', ['name' => 'Acme Hospital', 'type' => 'company'])->assertCreated();
        $linkedId = (int) $linked->json('id');
        $siti = $this->postJson("/api/v1/customers/{$linkedId}/contacts", ['name' => 'Siti', 'email' => 'info@shared.test', 'mobile' => '0198887777'])
            ->assertCreated()->json('core_contact_uuid');

        // Unlinked customers (older data).
        $fresh = Customer::factory()->create(['name' => 'Fresh Clinic', 'type' => 'company', 'email' => null, 'phone' => null, 'core_contact_uuid' => null]);
        $lookalike = Customer::factory()->create(['name' => 'ACME  Hospital', 'type' => 'company', 'email' => null, 'phone' => null, 'core_contact_uuid' => null]);

        // Unlinked customer contacts (older data).
        $ali = $this->legacyContact($fresh->id, 'Ali', ['email' => 'INFO@shared.test']);
        $sitiAgain = $this->legacyContact($linkedId, 'Siti', ['mobile' => '019-888 7777']);
        $ahKow = $this->legacyContact($fresh->id, 'Ah Kow', ['mobile' => '012-345 6789']);
        $meiLing = $this->legacyContact($fresh->id, 'Mei Ling', ['mobile' => '0123456789']);
        $wizard = $this->legacyContact($fresh->id, 'Wizard Man', ['contact_type' => 'Wizard']);
        $plain = $this->legacyContact($fresh->id, 'Plain Person');

        // Step 0: soft-deleted rows are planned too, marked as deleted.
        $deletedCustomer = Customer::factory()->create(['name' => 'Closed Trading', 'type' => 'company', 'email' => null, 'phone' => null, 'core_contact_uuid' => null]);
        $deletedCustomer->delete();
        $deletedContact = $this->legacyContact($fresh->id, 'Former Staff', ['deleted_at' => now()]);

        $before = $this->tableCounts();

        $this->artisan('contacts:linking-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);

        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');

        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $this->assertSame('report_only', $report['mode']);

        $orgs = collect($report['organisations'])->keyBy('customer_id');
        $this->assertArrayNotHasKey($linkedId, $orgs->all(), 'Linked customers are not planned.');
        $this->assertSame('create_organisation', $orgs[$fresh->id]['action']);
        $this->assertFalse($orgs[$fresh->id]['customer_deleted']);
        $this->assertTrue($orgs[$deletedCustomer->id]['customer_deleted']);
        $this->assertSame(['needs_review', 'possible_existing_organisation'], [$orgs[$lookalike->id]['action'], $orgs[$lookalike->id]['reason']]);
        $this->assertSame(['customer:'.$linkedId], $orgs[$lookalike->id]['candidates'][0]['linked_to']);

        $people = collect($report['people'])->keyBy('customer_contact_id');

        // Tier 1 across companies with a different name: flagged, not silently linked.
        $this->assertSame('needs_review', $people[$ali]['action']);
        $this->assertSame('auto_link_existing_person', $people[$ali]['predicted_action']);
        $this->assertSame($siti, $people[$ali]['person_id']);
        $this->assertSame(['email'], $people[$ali]['matched_on']);
        $this->assertTrue($people[$ali]['flags']['name_differs']);
        $this->assertNotEmpty($people[$ali]['flags']['person_belongs_to_other_organisations']);

        // Same person, same company: a clean link.
        $this->assertSame(['auto_link_existing_person', $siti], [$people[$sitiAgain]['action'], $people[$sitiAgain]['person_id']]);
        $this->assertSame(['mobile'], $people[$sitiAgain]['matched_on']);
        $this->assertArrayNotHasKey('flags', $people[$sitiAgain]);

        // Two different people sharing a mobile would collapse into one.
        foreach ([$ahKow, $meiLing] as $id) {
            $this->assertSame('needs_review', $people[$id]['action']);
            $this->assertEqualsCanonicalizing([$ahKow, $meiLing], $people[$id]['flags']['shared_mobile_in_batch']);
        }

        // Legacy type the sync would reject.
        $this->assertSame('needs_review', $people[$wizard]['action']);
        $this->assertSame(['Wizard'], $people[$wizard]['flags']['unknown_taxonomy_values']);

        // A contact with no email and no mobile gets a new person: nothing
        // links it automatically (fixed matcher, ADR-003A §9).
        $this->assertSame('create_person', $people[$plain]['action']);
        $this->assertTrue($people[$deletedContact]['contact_deleted']);
        $this->assertSame(1, $report['summary']['deleted_customers_included']);
        $this->assertSame(1, $report['summary']['deleted_contacts_included']);
        $this->assertArrayNotHasKey('flags', $people[$plain]);
        $this->assertTrue($people[$plain]['customer_needs_organisation']);
    }

    private function legacyContact(int $customerId, string $name, array $attributes = []): int
    {
        return (int) DB::table('customer_contacts')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'name' => $name,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['customers', 'customer_contacts', 'core_contacts', 'contact_relationships', 'contact_roles'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['linked_customers'] = DB::table('customers')->whereNotNull('core_contact_uuid')->count();
        $counts['linked_contacts'] = DB::table('customer_contacts')->whereNotNull('core_contact_uuid')->count();

        return $counts;
    }
}
