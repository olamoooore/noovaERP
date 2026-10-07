<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 3, hostile review of linking plan revision 3 (2026-10-07):
 * a person row can depend on other rows, and the review sheet must show it.
 *
 *  - linking a contact of an UNLINKED customer runs
 *    ensureOrganisationContact(), which creates the company. So the person
 *    row depends on the customer's organisation row (`organisation:<id>`);
 *  - a link to `batch:<id>` depends on row <id> creating that person
 *    (`person:<id>`);
 *  - a dependent row gets no proposal unless every row it depends on is
 *    proposed to create (or link): bulk-accepting proposals must never run
 *    a person ahead of a company still in review.
 */
class ContactLinkingDependenciesTest extends TenantTestCase
{
    private string $tenantId;

    private string $reportPath;

    private string $sheetPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId,
            'name' => 'Linking Review Sheet Tenant',
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

        $this->reportPath = tempnam(sys_get_temp_dir(), 'linking-deps-json-');
        $this->sheetPath = tempnam(sys_get_temp_dir(), 'linking-deps-csv-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        @unlink($this->sheetPath);
        parent::tearDown();
    }

    public function test_rows_carry_their_dependencies_and_dependent_proposals_follow_them(): void
    {
        // Linked customer: its contacts depend on nothing.
        $linked = (int) $this->postJson('/api/v1/customers', ['name' => 'Acme Hospital', 'type' => 'company'])->assertCreated()->json('id');
        $linkedContact = $this->legacyContact($linked, 'Plain Person');

        // Unlinked look-alike (organisation in review) with a contact.
        $lookalike = Customer::factory()->create(['name' => 'ACME  Hospital', 'type' => 'company', 'email' => null, 'phone' => null, 'core_contact_uuid' => null]);
        $underReview = $this->legacyContact($lookalike->id, 'Nurse Ana');

        // Unlinked fresh customer (organisation proposed create) with two
        // contacts of the same human: the second links to the first's new person.
        $fresh = Customer::factory()->create(['name' => 'Fresh Clinic', 'type' => 'company', 'email' => null, 'phone' => null, 'core_contact_uuid' => null]);
        $wongA = $this->legacyContact($fresh->id, 'Dr Wong', ['email' => 'wong@example.test']);
        $wongB = $this->legacyContact($fresh->id, 'Dr Wong', ['email' => 'WONG@example.test']);

        $report = $this->runReport();
        $people = collect($report['people'])->keyBy('customer_contact_id');

        $this->assertSame([], $people[$linkedContact]['depends_on']);
        $this->assertSame(['organisation:'.$lookalike->id], $people[$underReview]['depends_on']);
        $this->assertSame(['organisation:'.$fresh->id], $people[$wongA]['depends_on']);
        $this->assertSame('batch:'.$wongA, $people[$wongB]['person_id']);
        $this->assertSame(['organisation:'.$fresh->id, 'person:'.$wongA], $people[$wongB]['depends_on']);

        $rows = array_map('str_getcsv', array_filter(explode("\n", (string) file_get_contents($this->sheetPath))));
        $header = array_shift($rows);
        $this->assertContains('depends_on', $header);
        $sheet = collect($rows)->map(fn ($r) => array_combine($header, $r))->filter(fn ($r) => $r['kind'] === 'person')->keyBy('row_id');

        $this->assertSame('create', $sheet[(string) $linkedContact]['proposed_decision']);
        $this->assertSame('', $sheet[(string) $underReview]['proposed_decision'], 'A person of a company still in review gets no proposal.');
        $this->assertSame('organisation:'.$lookalike->id, $sheet[(string) $underReview]['depends_on']);
        $this->assertSame('create', $sheet[(string) $wongA]['proposed_decision'], 'Its company is proposed to be created.');
        $this->assertSame('link', $sheet[(string) $wongB]['proposed_decision'], 'Its company and its batch person are both proposed to be created.');
        $this->assertSame('organisation:'.$fresh->id.'|person:'.$wongA, $sheet[(string) $wongB]['depends_on']);
    }

    /** @return array<string, mixed> */
    private function runReport(): array
    {
        $before = $this->tableCounts();
        $this->artisan('contacts:linking-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath, '--review-sheet' => $this->sheetPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);
        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');

        return json_decode((string) file_get_contents($this->reportPath), true)[0];
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

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['customers', 'customer_contacts', 'core_contacts', 'contact_relationships', 'contact_roles'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
