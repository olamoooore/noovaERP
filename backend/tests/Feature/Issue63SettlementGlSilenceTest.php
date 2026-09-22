<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #63 — SettlementController::allocate()/reverse() are GL-silent by
 * design (documented in the controller docblock). This test makes that
 * contract verifiable: settlement allocation/reversal must never create a
 * journal entry, posting audit row or journal entry link — if settlement ever
 * gains a ledger effect it must route through PostingEngine, and this test
 * will fail until that path is added deliberately.
 */
class Issue63SettlementGlSilenceTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function createSettlement(float $allocated = 0, float $unapplied = 1000): Settlement
    {
        $settlement = new Settlement;
        $settlement->tenant_id = $this->tenantUuid;
        $settlement->settlement_number = 'STL-'.uniqid();
        $settlement->settlement_state = 'UNALLOCATED';
        $settlement->settlement_type = 'cash_application';
        $settlement->allocated_amount = $allocated;
        $settlement->unapplied_amount = $unapplied;
        $settlement->currency_code = 'USD';
        $settlement->save();

        return $settlement;
    }

    private function callAs(User $user, string $method, string $uri, array $data = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->withoutMiddleware([
                \App\Http\Middleware\SetTenantFromUserPreference::class,
                \App\Http\Middleware\EnsureTenantSearchPath::class,
                \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
                \App\Http\Middleware\RequirePermission::class,
                \App\Http\Middleware\ResolveEntityContext::class,
                \App\Http\Middleware\EnsureEntityAccess::class,
            ])
            ->json($method, $uri, $data);
    }

    private function ledgerWriteCounts(): array
    {
        return [
            'journal_entries' => DB::table('journal_entries')->count(),
            'journal_entry_links' => DB::table('journal_entry_links')->count(),
            'posting_audit_logs' => DB::table('posting_audit_logs')->count(),
        ];
    }

    public function test_allocation_and_reversal_are_gl_silent(): void
    {
        $settlement = $this->createSettlement(unapplied: 1000.00);
        $before = $this->ledgerWriteCounts();

        // ── Allocate ────────────────────────────────────────────────
        $this->callAs(
            $this->admin(),
            'POST',
            '/api/v1/accounting/settlements/allocate',
            [
                'settlement_id' => $settlement->id,
                'allocations' => [
                    ['source_record_type' => 'invoice', 'source_record_id' => 1, 'amount' => 400.00],
                ],
            ]
        )->assertStatus(201);

        $allocation = Allocation::where('settlement_id', $settlement->id)->firstOrFail();
        $this->assertNull($allocation->reversed_at);

        // Allocation is a subledger overlay: no ledger write may occur.
        $this->assertSame($before, $this->ledgerWriteCounts(), 'Allocation must not write to the GL.');

        // ── Reverse ─────────────────────────────────────────────────
        $this->callAs(
            $this->admin(),
            'POST',
            '/api/v1/accounting/settlements/'.$settlement->id.'/reverse',
            ['reason' => 'Issue63 GL-silence proof']
        )->assertOk();

        $this->assertNotNull($allocation->fresh()->reversed_at, 'The original allocation must be marked reversed.');
        $this->assertNotNull($allocation->fresh()->reversal_reason);

        $compensating = Allocation::where('reversal_of_allocation_id', $allocation->id)->first();
        $this->assertNotNull($compensating, 'A compensating allocation must exist.');
        $this->assertEqualsWithDelta(-400.00, (float) $compensating->allocated_amount, 0.01);

        // Reversal is also subledger-only.
        $this->assertSame($before, $this->ledgerWriteCounts(), 'Reversal must not write to the GL.');
    }
}
