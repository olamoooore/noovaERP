<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * The person-matching bug (fixed in 9e389eb) linked contacts without an
 * email/mobile to an unrelated person. This REPORT lists the damage — people
 * linked from several customer contacts without a shared identifier — so a
 * repair can be planned and reviewed. It must write nothing.
 *
 * Correct links are created through the real HTTP routes. Wrong links can no
 * longer be produced through the API after the fix, so they are seeded the
 * way the bug left them: a customer contact (and its company relationship)
 * pointing at another contact's person.
 */
class PersonMergeDamageReportTest extends TenantTestCase
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
            'name' => 'Merge Damage Tenant',
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

        $this->reportPath = tempnam(sys_get_temp_dir(), 'merge-damage-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_report_lists_wrongly_shared_people_and_writes_nothing(): void
    {
        [$hospital, $hospitalOrg] = $this->createCustomer('Acme Hospital');
        [$clinic, $clinicOrg] = $this->createCustomer('Bayu Clinic');
        [$pharmacy] = $this->createCustomer('Citra Pharmacy');

        // Correct: one person at two companies, linked by a shared email.
        $tanHospital = $this->addContact($hospital, ['name' => 'Dr Tan', 'email' => 'tan@example.test']);
        $tanClinic = $this->addContact($clinic, ['name' => 'Dr Tan', 'email' => 'tan@example.test']);
        $tanPerson = $tanHospital['core_contact_uuid'];
        $this->assertSame($tanPerson, $tanClinic['core_contact_uuid']);

        // Siti's person, which the bug then handed to two unrelated contacts.
        $siti = $this->addContact($hospital, ['name' => 'Siti', 'email' => 'siti@acme.test']);
        $sitiPerson = $siti['core_contact_uuid'];
        $ali = $this->wronglyMerged($clinic, $clinicOrg, 'Ali', $sitiPerson);
        $sitiNamesake = $this->wronglyMerged($pharmacy, null, 'Siti', $sitiPerson);

        $before = $this->tableCounts();

        $this->artisan('contacts:person-merge-damage-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);

        $this->assertSame($before, $this->tableCounts(), 'The report must not write anything.');

        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $this->assertSame('report_only', $report['mode']);

        $people = collect($report['people'])->keyBy('person_id');
        $this->assertArrayNotHasKey($tanPerson, $people->all(), 'A person linked by a shared email is not damage.');

        $damaged = $people[$sitiPerson];
        $this->assertSame('suspect', $damaged['severity']);
        $this->assertSame($siti['id'], $damaged['created_from_customer_contact_id']);
        $links = collect($damaged['links'])->keyBy('customer_contact_id');
        $this->assertSame('ok', $links[$siti['id']]['status']);
        $this->assertSame('suspect', $links[$ali]['status'], 'Different name, no shared identifier.');
        $this->assertSame('unverifiable', $links[$sitiNamesake]['status'], 'Same name, no shared identifier.');
        $this->assertEqualsCanonicalizing([$hospitalOrg, $clinicOrg], $damaged['impact']['organisations']);

        $this->assertSame(1, $report['summary']['people_with_suspect_links']);
        $this->assertSame(1, $report['summary']['suspect_links']);
        $this->assertSame(1, $report['summary']['unverifiable_links']);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function createCustomer(string $name): array
    {
        $created = $this->postJson('/api/v1/customers', ['name' => $name, 'type' => 'company'])->assertCreated();

        return [(int) $created->json('id'), $created->json('core_contact_uuid')];
    }

    private function addContact(int $customerId, array $payload): array
    {
        $json = $this->postJson("/api/v1/customers/{$customerId}/contacts", $payload)->assertCreated()->json();

        return ['id' => (int) $json['id'], 'core_contact_uuid' => $json['core_contact_uuid']];
    }

    /** What the bug left behind: a contact without identifiers on someone else's person. */
    private function wronglyMerged(int $customerId, ?string $orgId, string $name, string $personId): int
    {
        $id = (int) DB::table('customer_contacts')->insertGetId([
            'customer_id' => $customerId,
            'name' => $name,
            'core_contact_uuid' => $personId,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($orgId) {
            DB::table('contact_relationships')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'contact_id' => $orgId,
                'related_contact_id' => $personId,
                'relationship_type' => 'org_person',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['customer_contacts', 'core_contacts', 'contact_relationships', 'contact_roles'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
