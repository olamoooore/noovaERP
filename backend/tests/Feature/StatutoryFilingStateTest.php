<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StatutoryReturnFiling;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #274 G-14: filing submission-state dimension (FILED → ACCEPTED/REJECTED)
 * with RegulatorySubmission outbox events; G-12: TaxReturnPrepared on filing.
 */
class StatutoryFilingStateTest extends TenantTestCase
{
    private User $user;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->period = now()->format('Y-m');
    }

    public function test_filing_publishes_prepared_event_and_records_authority_outcome(): void
    {
        $file = $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/SG/{$this->period}/file");
        $file->assertStatus(201);

        $filing = StatutoryReturnFiling::firstOrFail();
        // #279 F13: prepared is not filed — the lifecycle starts at PREPARED.
        $this->assertSame('PREPARED', $filing->state, 'A freshly filed return starts in PREPARED.');

        $this->assertTrue(
            DB::table('accounting_outbox_events')
                ->where('event_type', 'TaxReturnPrepared')
                ->where('aggregate_version', (int) $filing->filing_version)
                ->exists(),
            'Filing must publish TaxReturnPrepared to the shared outbox.'
        );

        $accepted = $this->actingAs($this->user)->postJson(
            "/api/v1/accounting/statutory-returns/filings/{$filing->id}/submission",
            ['outcome' => 'accepted', 'reference' => 'IRAS-ACK-123']
        );
        $accepted->assertStatus(200);

        $filing->refresh();
        $this->assertSame('ACCEPTED', $filing->state);
        $this->assertSame('IRAS-ACK-123', $filing->authority_reference);
        $this->assertNotNull($filing->state_updated_at);

        $this->assertTrue(
            DB::table('accounting_outbox_events')->where('event_type', 'RegulatorySubmissionAccepted')->exists(),
            'Acceptance must publish RegulatorySubmissionAccepted.'
        );

        $rejected = $this->actingAs($this->user)->postJson(
            "/api/v1/accounting/statutory-returns/filings/{$filing->id}/submission",
            ['outcome' => 'rejected', 'note' => 'Box 6 mismatch']
        );
        $rejected->assertStatus(200);
        $this->assertSame('REJECTED', $filing->refresh()->state);

        $this->assertTrue(
            DB::table('accounting_outbox_events')->where('event_type', 'RegulatorySubmissionRejected')->exists(),
            'Rejection must publish RegulatorySubmissionRejected.'
        );
    }

    public function test_submission_lifecycle_is_idempotent_and_unknown_blocks_resubmission(): void
    {
        $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/SG/{$this->period}/file")->assertStatus(201);
        $filing = StatutoryReturnFiling::firstOrFail();

        $first = $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/filings/{$filing->id}/submit", ['reference' => 'IRAS-SUB-1']);
        $first->assertStatus(200);
        $this->assertFalse((bool) $first->json('idempotent'));
        $this->assertSame('SUBMITTED', $filing->refresh()->state);
        $this->assertNotNull($filing->submitted_at);

        // Idempotent: a second submit changes nothing.
        $second = $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/filings/{$filing->id}/submit");
        $second->assertStatus(200);
        $this->assertTrue((bool) $second->json('idempotent'));
        $this->assertSame(1, DB::table('audit_logs')->where('operation', 'filing_state_submitted')->count());

        // Timeout -> unknown delivery state blocks a blind duplicate.
        $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/filings/{$filing->id}/submission-unknown", ['note' => 'gateway timeout'])
            ->assertStatus(200);
        $this->assertSame('PENDING_AUTHORITY_RESPONSE', $filing->refresh()->state);
        $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/filings/{$filing->id}/submit")
            ->assertStatus(409);

        // Reconciled outcome resolves the unknown state.
        $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/filings/{$filing->id}/submission", ['outcome' => 'accepted', 'reference' => 'ACK-279'])
            ->assertStatus(200);
        $this->assertSame('ACCEPTED', $filing->refresh()->state);

        // Accepted filings cannot be resubmitted.
        $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/filings/{$filing->id}/submit")
            ->assertStatus(409);
    }
}
