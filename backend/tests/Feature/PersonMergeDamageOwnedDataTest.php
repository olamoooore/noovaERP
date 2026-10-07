<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Services\ContactMasterService;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 4, decision register R10 (hostile review, 2026-10-07):
 * data a damaged person OWNS (identifiers, bank accounts, tax profiles,
 * registrations, module profiles) cannot be traced to the contact it came
 * from. So no link of a person holding owned data is split automatically:
 * it is not `auto_split_eligible`, says why
 * (`auto_split_blocked_by_owned_data`), and the review sheet proposes
 * `review`, not `split`.
 *
 * The report must write nothing.
 */
class PersonMergeDamageOwnedDataTest extends TenantTestCase
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
            'name' => 'Owned Data Repair Tenant',
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

        Setting::setValue(ContactTaxonomyRegistry::HEALTHCARE_SETTING_KEY, true);

        $this->reportPath = tempnam(sys_get_temp_dir(), 'merge-owned-json-');
        $this->sheetPath = tempnam(sys_get_temp_dir(), 'merge-owned-csv-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        @unlink($this->sheetPath);
        parent::tearDown();
    }

    public function test_links_of_a_person_with_owned_data_are_never_auto_split(): void
    {
        $hospital = (int) $this->postJson('/api/v1/customers', ['name' => 'Acme Hospital', 'type' => 'company'])->assertCreated()->json('id');
        $clinic = $this->postJson('/api/v1/customers', ['name' => 'Bayu Clinic', 'type' => 'company'])->assertCreated();
        $sitiPerson = $this->postJson("/api/v1/customers/{$hospital}/contacts", ['name' => 'Siti', 'email' => 'siti@acme.test'])->assertCreated()->json('core_contact_uuid');

        // The bug's signature: no email, no mobile, another name.
        $ali = $this->wronglyMerged((int) $clinic->json('id'), $sitiPerson, 'Ali');

        // Baseline: a person made by the normal contact sync owns nothing,
        // so the bug-signature link is proposed for an automatic split.
        [$person, $sheet] = $this->report($sitiPerson);
        $this->assertFalse($person['has_owned_data'], 'The contact sync itself creates no owned data.');
        $link = collect($person['links'])->firstWhere('customer_contact_id', $ali);
        $this->assertTrue($link['auto_split_eligible']);
        $this->assertFalse($link['auto_split_blocked_by_owned_data']);
        $this->assertSame('split', $sheet[(string) $ali]['proposed_decision']);

        // Someone added data to the person in the Contact Master. Whose it
        // is cannot be known, so the link goes to a reviewer.
        app(ContactMasterService::class)->addIdentifier($sitiPerson, 'staff_no', 'S-001', $this->tenantId);

        [$person, $sheet, $summary] = $this->report($sitiPerson);
        $this->assertTrue($person['has_owned_data']);
        $link = collect($person['links'])->firstWhere('customer_contact_id', $ali);
        $this->assertTrue($link['bug_signature']);
        $this->assertSame('suspect', $link['status']);
        $this->assertFalse($link['auto_split_eligible'], 'A person holding owned data is never split automatically (R10).');
        $this->assertTrue($link['auto_split_blocked_by_owned_data']);
        $this->assertSame('review', $sheet[(string) $ali]['proposed_decision']);
        $this->assertSame(0, $summary['auto_split_eligible_links']);
        $this->assertSame(1, $summary['suspect_links_for_review']);
        $this->assertSame(1, $summary['links_blocked_by_owned_data']);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, array<string, string>>, 2: array<string, mixed>} */
    private function report(string $personId): array
    {
        $before = $this->tableCounts();
        $this->artisan('contacts:person-merge-damage-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath, '--review-sheet' => $this->sheetPath])->assertExitCode(0);
        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');

        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $rows = array_map('str_getcsv', array_filter(explode("\n", (string) file_get_contents($this->sheetPath))));
        $header = array_shift($rows);
        $sheet = collect($rows)->map(fn ($r) => array_combine($header, $r))->keyBy('customer_contact_id')->all();

        return [collect($report['people'])->keyBy('person_id')[$personId], $sheet, $report['summary']];
    }

    /** What the bug left behind: a contact on someone else's person. */
    private function wronglyMerged(int $customerId, string $personId, string $name): int
    {
        return (int) DB::table('customer_contacts')->insertGetId([
            'customer_id' => $customerId,
            'name' => $name,
            'core_contact_uuid' => $personId,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['customer_contacts', 'core_contacts', 'contact_relationships', 'contact_roles', 'contact_group_members', 'contact_identifiers'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
