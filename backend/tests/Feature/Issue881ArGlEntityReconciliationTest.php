<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ReconciliationControlResult;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #881 (P2, SA8): AR ↔ ledger reconciliation was tenant-wide, ran only
 * on demand, and fell back to a guessed account code (1100) when the
 * accounts_receivable mapping was missing.
 *
 * These tests drive the real HTTP route (router → middleware → controller)
 * and the scheduled command, in a multi-entity tenant.
 */
class Issue881ArGlEntityReconciliationTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue881 AR Entity Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue881 Group',
            'code' => 'I881-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => '881-A-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => '881-B-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->makeUser('admin');

        $suffix = Str::upper(Str::random(6));
        $ar = ChartOfAccount::factory()->create([
            'code' => "124000-{$suffix}", 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true,
        ]);
        $revenue = ChartOfAccount::factory()->create([
            'code' => "4100-{$suffix}", 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true,
        ]);

        AccountMapping::where('mapping_key', 'accounts_receivable')->delete();
        AccountMapping::where('mapping_key', 'sales_revenue')->delete();

        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'revenue', 'account_id' => $revenue->id]);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(ucfirst($role));

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$this->entityA, $this->entityB] as $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => $role,
                'is_default' => $entityId === $this->entityA,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function createAndPostInvoice(int $entityId, float $total): Invoice
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => $entityId,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customer->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'lines' => [[
                    'line_type' => 'product',
                    'description' => 'Issue881 Widget',
                    'quantity' => 1,
                    'unit_price' => $total,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ]],
            ]);

        $response->assertCreated($response->getContent());

        $invoice = Invoice::findOrFail($response->json('id'));

        $post = $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson("/api/v1/invoices/{$invoice->id}/post");

        $post->assertOk($post->getContent());

        return $invoice->refresh();
    }

    private function reconcile(int $entityId): TestResponse
    {
        return $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson('/api/v1/accounting/accounting/reconciliation/ar-gl', [
                'as_of_date' => now()->toDateString(),
                'legal_entity_id' => $entityId,
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC1: per-entity reconciliation, results stored per entity
    // ─────────────────────────────────────────────────────────────────────

    public function test_reconciliation_is_scoped_to_the_requested_entity(): void
    {
        $this->createAndPostInvoice($this->entityA, 100);
        $this->createAndPostInvoice($this->entityB, 50);

        $responseA = $this->reconcile($this->entityA);
        $responseA->assertOk($responseA->getContent());

        $this->assertSame(
            $this->entityA,
            (int) $responseA->json('data.legal_entity_id'),
            'The result must state which legal entity was reconciled'
        );
        $this->assertSame(
            100.0,
            (float) $responseA->json('data.subledger_balance'),
            'Entity A reconciliation must only see Entity A subledger rows, not Entity B'
        );
        $this->assertSame(
            100.0,
            (float) $responseA->json('data.gl_balance'),
            'Entity A reconciliation must only see Entity A journal lines'
        );
        $this->assertSame(0.0, (float) $responseA->json('data.difference'));

        $responseB = $this->reconcile($this->entityB);
        $responseB->assertOk($responseB->getContent());

        $this->assertSame(
            50.0,
            (float) $responseB->json('data.subledger_balance'),
            'Entity B reconciliation must only see Entity B subledger rows'
        );
        $this->assertSame(
            50.0,
            (float) $responseB->json('data.gl_balance'),
            'Entity B reconciliation must only see Entity B journal lines'
        );

        $stored = ReconciliationControlResult::where('legal_entity_id', $this->entityA)->first();
        $this->assertNotNull($stored, 'The per-entity result must be stored with its legal entity');
        $this->assertSame('passed', $stored->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC2: no code-based fallback — a missing mapping is a setup error
    // ─────────────────────────────────────────────────────────────────────

    public function test_missing_ar_mapping_is_a_setup_error_and_never_guesses_code_1100(): void
    {
        AccountMapping::where('mapping_key', 'accounts_receivable')->delete();

        ChartOfAccount::firstOrCreate(
            ['code' => '1100'],
            ['name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]
        );

        $before = ReconciliationControlResult::where('control_name', 'ar_gl_reconciliation')->count();

        $response = $this->reconcile($this->entityA);

        $response->assertStatus(422, $response->getContent());
        $this->assertStringContainsString(
            'accounts_receivable',
            (string) $response->json('message'),
            'The setup error must name the missing mapping key'
        );
        $this->assertSame(
            $before,
            ReconciliationControlResult::where('control_name', 'ar_gl_reconciliation')->count(),
            'No reconciliation result may be stored from a guessed control account'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC1: scheduled daily, one stored result per entity
    // ─────────────────────────────────────────────────────────────────────

    public function test_scheduled_command_stores_a_result_per_entity(): void
    {
        $this->createAndPostInvoice($this->entityA, 100);
        $this->createAndPostInvoice($this->entityB, 50);

        // The command skips re-initializing a tenant that is already the
        // active context, so binding it here lets the scheduler loop run
        // against this test's (transaction-scoped) data.
        $this->bindTenantContext($this->tenantId);

        $this->artisan('accounting:reconcile-ar-gl --fail-on-variance')->assertExitCode(0);

        $rows = ReconciliationControlResult::where('control_name', 'ar_gl_reconciliation')
            ->where('as_of_date', now()->toDateString())
            ->get();

        $this->assertCount(2, $rows, 'The scheduled run must store one AR/GL result per legal entity');
        $this->assertEqualsCanonicalizing(
            [$this->entityA, $this->entityB],
            $rows->pluck('legal_entity_id')->map(fn ($id) => (int) $id)->all(),
            'Each stored result must carry its legal entity'
        );

        $entityARow = $rows->firstWhere('legal_entity_id', $this->entityA);
        $this->assertSame(100.0, (float) $entityARow->subledger_balance);
        $this->assertSame('passed', $entityARow->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC4: alert when the difference exceeds tolerance
    // ─────────────────────────────────────────────────────────────────────

    public function test_scheduled_command_alerts_when_the_difference_exceeds_tolerance(): void
    {
        // A posted-looking subledger invoice with no journal entry at all:
        // the AR subledger carries 100, the GL control account carries 0.
        $customer = Customer::factory()->create([
            'legal_entity_id' => $this->entityA,
            'is_active' => true,
        ]);

        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'legal_entity_id' => $this->entityA,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'invoice_date' => now()->toDateString(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'balance' => 100,
        ]);

        $this->bindTenantContext($this->tenantId);

        $this->artisan('accounting:reconcile-ar-gl --fail-on-variance')->assertExitCode(1);

        $stored = ReconciliationControlResult::where('legal_entity_id', $this->entityA)->first();
        $this->assertNotNull($stored);
        $this->assertSame('failed', $stored->status);
        $this->assertSame(100.0, (float) $stored->difference);
    }
}
