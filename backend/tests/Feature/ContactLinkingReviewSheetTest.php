<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 3, hostile review of linking plan revision 2 (2026-10-07):
 *
 *  A. a review decision depends on the TARGET too (the candidate
 *     organisation, the matched person, the merge survivor), so a row's
 *     fingerprint must change when its target changes — not only when the
 *     source customer/contact changes;
 *  B. `--review-sheet=<csv>` writes one row per planned customer and
 *     contact, with the fingerprint, the allowed decisions for its action,
 *     a proposed decision and empty decision columns; names that look like
 *     spreadsheet formulas are escaped.
 *
 * The report must write nothing.
 */
class ContactLinkingReviewSheetTest extends TenantTestCase
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

        $this->reportPath = tempnam(sys_get_temp_dir(), 'linking-sheet-json-');
        $this->sheetPath = tempnam(sys_get_temp_dir(), 'linking-sheet-csv-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        @unlink($this->sheetPath);
        parent::tearDown();
    }

    public function test_fingerprints_cover_the_target_and_the_review_sheet_is_safe_and_complete(): void
    {
        // A linked organisation that an unlinked look-alike may be.
        $linked = (int) $this->postJson('/api/v1/customers', ['name' => 'Acme Hospital', 'type' => 'company'])->assertCreated()->json('id');
        $linkedOrg = (string) Customer::findOrFail($linked)->core_contact_uuid;
        $sitiPerson = $this->postJson("/api/v1/customers/{$linked}/contacts", ['name' => 'Siti', 'email' => 'siti@acme.test'])->assertCreated()->json('core_contact_uuid');

        $lookalike = Customer::factory()->create(['name' => 'ACME  Hospital', 'type' => 'company', 'email' => null, 'phone' => null, 'core_contact_uuid' => null]);
        $fresh = Customer::factory()->create(['name' => '=HYPERLINK("http://evil.test","x")', 'type' => 'company', 'email' => null, 'phone' => null, 'core_contact_uuid' => null]);

        // An unlinked contact that auto-links to Siti's person (same name, email).
        $sitiAgain = $this->legacyContact($linked, 'Siti', ['email' => 'siti@acme.test']);

        $first = $this->runReport();
        $orgs = collect($first['organisations'])->keyBy('customer_id');
        $people = collect($first['people'])->keyBy('customer_contact_id');
        $this->assertSame('possible_existing_organisation', $orgs[$lookalike->id]['reason']);
        $this->assertSame('auto_link_existing_person', $people[$sitiAgain]['action']);

        // A: the candidate organisation changes (deactivated) without
        // changing whether it matches — the source customer did not change,
        // but the decision's target did.
        DB::table('core_contacts')->where('id', $linkedOrg)->update(['is_active' => false, 'updated_at' => now()->addMinute()]);
        // The matched person changes too (again without changing the match).
        DB::table('core_contacts')->where('id', $sitiPerson)->update(['phone' => '0311112222', 'updated_at' => now()->addMinute()]);

        $second = $this->runReport();
        $this->assertNotSame($orgs[$lookalike->id]['fingerprint'], collect($second['organisations'])->keyBy('customer_id')[$lookalike->id]['fingerprint'], 'A candidate organisation change invalidates the decision.');
        $this->assertNotSame($people[$sitiAgain]['fingerprint'], collect($second['people'])->keyBy('customer_contact_id')[$sitiAgain]['fingerprint'], 'A matched person change invalidates the decision.');
        $this->assertSame($orgs[$fresh->id]['fingerprint'], collect($second['organisations'])->keyBy('customer_id')[$fresh->id]['fingerprint'], 'Unrelated rows keep their fingerprint.');

        // B
        $rows = array_map('str_getcsv', array_filter(explode("\n", (string) file_get_contents($this->sheetPath))));
        $header = array_shift($rows);
        $this->assertSame(['tenant_id', 'kind', 'row_id', 'fingerprint', 'name', 'action', 'reason', 'targets', 'allowed_decisions', 'proposed_decision', 'decision', 'decision_target', 'decided_by', 'decided_at', 'note'], $header);
        $sheet = collect($rows)->map(fn ($r) => array_combine($header, $r));

        $lookalikeRow = $sheet->first(fn ($r) => $r['kind'] === 'organisation' && $r['row_id'] === (string) $lookalike->id);
        $this->assertSame(collect($second['organisations'])->keyBy('customer_id')[$lookalike->id]['fingerprint'], $lookalikeRow['fingerprint']);
        $this->assertSame('create|link|skip', $lookalikeRow['allowed_decisions']);
        $this->assertSame('', $lookalikeRow['proposed_decision'], 'A row in review has no proposal.');
        $this->assertStringContainsString($linkedOrg, $lookalikeRow['targets']);

        $freshRow = $sheet->first(fn ($r) => $r['kind'] === 'organisation' && $r['row_id'] === (string) $fresh->id);
        $this->assertSame('create', $freshRow['proposed_decision']);
        $this->assertSame("'=HYPERLINK(\"http://evil.test\",\"x\")", $freshRow['name'], 'A formula-looking name is escaped.');

        $sitiRow = $sheet->first(fn ($r) => $r['kind'] === 'person' && $r['row_id'] === (string) $sitiAgain);
        $this->assertSame('link', $sitiRow['proposed_decision']);
        $this->assertSame('link|create|skip', $sitiRow['allowed_decisions']);
        $this->assertSame($sitiPerson, $sitiRow['targets']);
        $this->assertSame('', $sitiRow['decision']);
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
