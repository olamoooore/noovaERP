<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Runtime tests for issues #41 (settlement reversal schema) and
 * #46 (settlement over-allocation rejection).
 *
 * Replaces the previous source-string-only assertions with real HTTP + DB
 * coverage over the live SettlementController.
 */
class Issue41And46SettlementControllerTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'accountant']);
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

    // ─── #46: Over-allocation rejection ──────────────────────────

    public function test_over_allocation_is_rejected_with_canonical_error(): void
    {
        $settlement = $this->createSettlement(unapplied: 100.00);

        $response = $this->callAs(
            $this->admin(),
            'POST',
            '/api/v1/accounting/settlements/allocate',
            [
                'settlement_id' => $settlement->id,
                'allocations' => [
                    ['source_record_type' => 'invoice', 'source_record_id' => 1, 'amount' => 150.00],
                ],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonPath('error', 'ALLOCATION_EXCEEDS_UNAPPLIED')
            ->assertJsonPath('unapplied_amount', 100);

        // No allocation was created
        $this->assertSame(0, Allocation::where('settlement_id', $settlement->id)->count());
    }

    public function test_exact_boundary_allocation_succeeds(): void
    {
        $settlement = $this->createSettlement(unapplied: 100.00);

        $response = $this->callAs(
            $this->admin(),
            'POST',
            '/api/v1/accounting/settlements/allocate',
            [
                'settlement_id' => $settlement->id,
                'allocations' => [
                    ['source_record_type' => 'invoice', 'source_record_id' => 1, 'amount' => 100.00],
                ],
            ]
        );

        $response->assertCreated();
        $settlement->refresh();
        $this->assertEquals(100.00, (float) $settlement->allocated_amount);
        $this->assertEquals(0.00, (float) $settlement->unapplied_amount);
        $this->assertSame('FULLY_ALLOCATED', $settlement->settlement_state);
        $this->assertSame(1, Allocation::where('settlement_id', $settlement->id)->count());
    }

    public function test_partial_under_allocation_keeps_remainder(): void
    {
        $settlement = $this->createSettlement(unapplied: 250.00);

        $response = $this->callAs(
            $this->admin(),
            'POST',
            '/api/v1/accounting/settlements/allocate',
            [
                'settlement_id' => $settlement->id,
                'allocations' => [
                    ['source_record_type' => 'invoice', 'source_record_id' => 1, 'amount' => 50.00],
                    ['source_record_type' => 'invoice', 'source_record_id' => 2, 'amount' => 75.00],
                ],
            ]
        );

        $response->assertCreated();
        $settlement->refresh();
        $this->assertEquals(125.00, (float) $settlement->allocated_amount);
        $this->assertEquals(125.00, (float) $settlement->unapplied_amount);
        $this->assertSame('PARTIALLY_ALLOCATED', $settlement->settlement_state);
        $this->assertSame(2, Allocation::where('settlement_id', $settlement->id)->count());
    }

    public function test_allocations_carry_real_tenant_provenance(): void
    {
        $settlement = $this->createSettlement(unapplied: 100.00);

        $response = $this->callAs(
            $this->admin(),
            'POST',
            '/api/v1/accounting/settlements/allocate',
            [
                'settlement_id' => $settlement->id,
                'allocations' => [
                    ['source_record_type' => 'invoice', 'source_record_id' => 1, 'amount' => 60.00],
                ],
            ]
        );

        $response->assertCreated();
        $allocation = Allocation::where('settlement_id', $settlement->id)->first();
        $this->assertNotNull($allocation);
        // Real provenance: settlement already carries the test tenant UUID
        $this->assertSame($this->tenantUuid, (string) $allocation->tenant_id);
    }

    // ─── #41: Settlement reversal schema + invariants ────────────

    public function test_reversal_creates_compensating_allocations_and_preserves_invariants(): void
    {
        $settlement = $this->createSettlement(unapplied: 300.00);

        // Allocate 100 to invoice 1 → settlement now has allocated=100, unapplied=200
        $this->callAs(
            $this->admin(),
            'POST',
            '/api/v1/accounting/settlements/allocate',
            [
                'settlement_id' => $settlement->id,
                'allocations' => [
                    ['source_record_type' => 'invoice', 'source_record_id' => 1, 'amount' => 100.00],
                ],
            ]
        )->assertCreated();

        $original = Allocation::where('settlement_id', $settlement->id)->whereNull('reversed_at')->first();
        $this->assertNotNull($original);
        $this->assertEquals(100.00, (float) $original->allocated_amount);

        $totalBefore = (float) $settlement->fresh()->allocated_amount + (float) $settlement->fresh()->unapplied_amount;

        $response = $this->callAs(
            $this->admin(),
            'POST',
            "/api/v1/accounting/settlements/{$settlement->id}/reverse",
            ['reason' => 'test reversal']
        );
        $response->assertOk();

        // Compensating allocation was created (must have reversal_of_allocation_id
        // set, distinguishing it from the original allocation which was marked
        // reversed but has reversal_of_allocation_id=null).
        $allAllocs = Allocation::where('settlement_id', $settlement->id)->get();
        $compensation = Allocation::where('settlement_id', $settlement->id)
            ->whereNotNull('reversal_of_allocation_id')
            ->first();
        $this->assertNotNull($compensation, 'Expected compensating allocation; found: '.json_encode($allAllocs->toArray()));
        // Use the raw DB value to verify the negative sign survived
        $rawAmount = DB::scalar('SELECT allocated_amount FROM allocations WHERE id = ?', [$compensation->id]);
        $this->assertEquals('-100.00', (string) $rawAmount, 'Raw stored amount: '.var_export($rawAmount, true).' attrs: '.json_encode($compensation->getAttributes()));
        $this->assertSame($original->id, (int) $compensation->reversal_of_allocation_id);

        // Original allocation's amount is unchanged
        $original->refresh();
        $this->assertEquals(100.00, (float) $original->allocated_amount);
        $this->assertNotNull($original->reversed_at);

        // Settlement invariants preserved
        $settlement->refresh();
        $this->assertSame('REVERSED', $settlement->settlement_state);
        $this->assertEquals(0.00, (float) $settlement->allocated_amount);
        $totalAfter = (float) $settlement->allocated_amount + (float) $settlement->unapplied_amount;
        $this->assertEqualsWithDelta($totalBefore, $totalAfter, 0.01);
        $this->assertNotNull($settlement->reversed_at);
        $this->assertSame('test reversal', $settlement->reversal_reason);
    }

    public function test_double_reversal_is_rejected(): void
    {
        $settlement = $this->createSettlement(unapplied: 100.00);
        $this->callAs(
            $this->admin(),
            'POST',
            "/api/v1/accounting/settlements/{$settlement->id}/reverse"
        )->assertOk();

        $response = $this->callAs(
            $this->admin(),
            'POST',
            "/api/v1/accounting/settlements/{$settlement->id}/reverse"
        );

        $response->assertStatus(422)
            ->assertJsonPath('error', 'SETTLEMENT_ALREADY_REVERSED');
    }

    public function test_reversal_failure_rolls_back_all_changes(): void
    {
        $settlement = $this->createSettlement(unapplied: 100.00);

        $this->callAs(
            $this->admin(),
            'POST',
            '/api/v1/accounting/settlements/allocate',
            [
                'settlement_id' => $settlement->id,
                'allocations' => [
                    ['source_record_type' => 'invoice', 'source_record_id' => 1, 'amount' => 50.00],
                ],
            ]
        )->assertCreated();

        $originalAllocation = Allocation::where('settlement_id', $settlement->id)->first();
        $preImage = $settlement->fresh()->getAttributes();

        // Force the compensating Allocation::create() to fail mid-transaction
        // by hooking Allocation's creating event to throw the moment a
        // compensating (reversal_of_allocation_id != null) row is about to
        // persist. The DB::transaction wrapper should roll back everything.
        Allocation::creating(function ($model) {
            if ($model->reversal_of_allocation_id !== null) {
                throw new \RuntimeException('Simulated downstream failure');
            }
        });

        $response = $this->callAs(
            $this->admin(),
            'POST',
            "/api/v1/accounting/settlements/{$settlement->id}/reverse"
        );

        // The exception escapes the transaction and surfaces as a 500 response
        // because the controller rethrows after logging.
        $response->assertStatus(500);

        $settlement->refresh();
        // Settlement state preserved as pre-reversal (rollback worked)
        $this->assertSame($preImage['settlement_state'], $settlement->settlement_state);
        $this->assertSame($preImage['allocated_amount'], $settlement->allocated_amount);
        $this->assertSame($preImage['unapplied_amount'], $settlement->unapplied_amount);
        // The original allocation's reversal metadata was rolled back
        $originalAllocation->refresh();
        $this->assertNull($originalAllocation->reversed_at);
        // No compensating allocations remained
        $this->assertSame(
            1,
            Allocation::where('settlement_id', $settlement->id)->count(),
            'No compensating allocations should remain after rollback'
        );
    }
}
