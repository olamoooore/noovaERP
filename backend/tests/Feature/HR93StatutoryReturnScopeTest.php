<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #93 (hostile re-review B2) — legal-entity scoping, quarterly periods
 * and the F7 disposition for the statutory return generators.
 */
class HR93StatutoryReturnScopeTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    private int $entityA;

    private int $entityB;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => (string) Str::uuid(),
        ]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady(['tax_transaction_details', 'legal_entities']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR93 Group',
            'code' => 'HR93-'.strtoupper(substr(md5(uniqid()), 0, 5)),
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'HR93 Entity A', 'code' => 'HR93A-'.strtoupper(substr(md5(uniqid()), 0, 5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'HR93 Entity B', 'code' => 'HR93B-'.strtoupper(substr(md5(uniqid()), 0, 5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function taxRow(?int $legalEntityId, string $period, float $taxable = 1000, float $tax = 90): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-93S-'.Str::upper(Str::random(6)),
            'entry_date' => $period.'-01',
            'status' => 'posted',
            'is_active_version' => true,
            'legal_entity_id' => $legalEntityId,
            'created_by' => $this->user->id,
        ]);

        DB::table('tax_transaction_details')->insert([
            'journal_entry_id' => $entry->id,
            'source_document_type' => 'invoice',
            'source_document_id' => 1,
            'tax_code' => 'SR',
            'taxable_amount' => $taxable,
            'tax_amount' => $tax,
            'jurisdiction' => 'SG',
            'reporting_period' => $period,
            'tax_rate' => 9,
            'tax_treatment' => 'standard',
            'tax_regime' => 'gst',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_return_is_scoped_to_a_single_legal_entity(): void
    {
        $this->taxRow($this->entityA, '2026-09', taxable: 1000, tax: 90);
        $this->taxRow($this->entityB, '2026-09', taxable: 5000, tax: 450);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/statutory-returns/SG/2026-09?entity_id={$this->entityA}");

        $response->assertOk();
        $response->assertJsonPath('data.reporting_period.legal_entity_id', $this->entityA);
        $this->assertSame(90.0, (float) $response->json('data.boxes.box_6_output_tax_due'),
            'Only entity A output tax may appear when entity A is selected.');
    }

    public function test_unspecified_entity_aggregates_all_entities(): void
    {
        $this->taxRow($this->entityA, '2026-09', taxable: 1000, tax: 90);
        $this->taxRow($this->entityB, '2026-09', taxable: 5000, tax: 450);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/statutory-returns/SG/2026-09');

        $response->assertOk();
        $this->assertSame(540.0, (float) $response->json('data.boxes.box_6_output_tax_due'));
        $this->assertNull($response->json('data.reporting_period.legal_entity_id'));
    }

    public function test_unknown_entity_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/statutory-returns/SG/2026-09?entity_id=999999')
            ->assertStatus(422);
    }

    public function test_quarterly_period_aggregates_three_months(): void
    {
        $this->taxRow($this->entityA, '2026-07', taxable: 100, tax: 9);
        $this->taxRow($this->entityA, '2026-08', taxable: 200, tax: 18);
        $this->taxRow($this->entityA, '2026-09', taxable: 300, tax: 27);
        // Next quarter must not leak in.
        $this->taxRow($this->entityA, '2026-10', taxable: 9000, tax: 810);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/statutory-returns/SG/2026-Q3');

        $response->assertOk();
        $response->assertJsonPath('data.reporting_period.months', ['2026-07', '2026-08', '2026-09']);
        $this->assertSame(54.0, (float) $response->json('data.boxes.box_6_output_tax_due'),
            'Q3 must aggregate exactly the three quarter months.');
    }

    public function test_malformed_period_is_rejected(): void
    {
        foreach (['2026-13', '2026-Q5', '2026', '2026-Q0'] as $bad) {
            $this->actingAs($this->user)
                ->getJson("/api/v1/accounting/statutory-returns/SG/{$bad}")
                ->assertStatus(422);
        }
    }

    public function test_f7_is_reported_as_supported_not_planned_since_265(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/statutory-returns');

        $response->assertOk();

        // Issue #265 implemented the F7 disclosure generator; it must now be
        // reported as supported. Nothing may sit silently absent.
        $supported = collect($response->json('data'))->pluck('form')->all();
        $this->assertContains('GST F7', $supported, 'F7 must be reported as a supported generator post-#265.');
        $this->assertContains('GST F5', $supported);
        $this->assertContains('SST-02', $supported);
        $this->assertSame([], $response->json('planned'), 'No tax generator may remain silently absent/planned.');
    }
}
