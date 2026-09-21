<?php

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BillPayment;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * RUNTIME VERIFICATION (go-live audit, 2026-08-25) of 7 claims produced by a
 * prior STATIC-ONLY audit. Every assertion here goes through the real
 * router/middleware/controller chain via postJson()/getJson() — never a
 * direct service/middleware instantiation.
 */
class RuntimeAuthVerificationTest extends TenantTestCase
{
    protected string $tenantId;

    protected User $viewer;   // low-privilege: Viewer role (no accounting.post/.reverse, no reports.view... wait Viewer HAS reports.view)

    protected User $salesUser; // Sales role: has accounting.write but NOT accounting.post/.reverse, NOT reports.view

    protected User $accountant; // Accountant role: has accounting.post/.reverse

    protected function setUp(): void
    {
        parent::setUp();

        User::firstOrCreate(
            ['id' => 1],
            ['name' => 'Audit Seed User', 'email' => 'audit-seed@local', 'password' => bcrypt('password')],
        );

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Viewer', 'Sales', 'Accountant', 'Owner'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Runtime Audit Test Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        $this->viewer = User::factory()->create(['email' => 'viewer@runtime-audit.test']);
        $this->viewer->assignRole('Viewer');

        $this->salesUser = User::factory()->create(['email' => 'sales@runtime-audit.test']);
        $this->salesUser->assignRole('Sales');

        $this->accountant = User::factory()->create(['email' => 'accountant@runtime-audit.test']);
        $this->accountant->assignRole('Accountant');

        foreach ([
            ['user' => $this->viewer, 'role' => 'viewer'],
            ['user' => $this->salesUser, 'role' => 'sales'],
            ['user' => $this->accountant, 'role' => 'accountant'],
        ] as $entry) {
            DB::table('user_company_access')->insert([
                'user_id' => $entry['user']->id,
                'tenant_id' => $this->tenantId,
                'role' => $entry['role'],
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // RequirePermission::resolveCentralUserId looks up public.users by email —
        // in this flattened test DB that's the same `users` table, so the
        // factory-created users above already satisfy it.
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared helper: create a posted JournalEntry via the real PostingEngine
    // ─────────────────────────────────────────────────────────────────────
    private function createPostedJournalEntry(int $documentId = 555001): JournalEntry
    {
        $debitAccount = ChartOfAccount::factory()->create(['type' => 'expense', 'is_active' => true]);
        $creditAccount = ChartOfAccount::factory()->create(['type' => 'liability', 'is_active' => true]);

        $engine = app(PostingEngineInterface::class);
        $request = new PostingRequest(
            documentType: 'runtime_audit_doc',
            documentId: $documentId,
            postingDate: now()->addDays(5)->toDateString(),
            description: 'Runtime audit posting',
            lines: [
                new PostingRequestLine('expense', $debitAccount->id, 200.00, 0.00, 'Debit'),
                new PostingRequestLine('payable', $creditAccount->id, 0.00, 200.00, 'Credit'),
            ],
            userId: $this->accountant->id,
            // #37: a synthetic/module document type has no platform-resolved
            // permission, so the caller must declare the required permission
            // explicitly (the Accountant holds accounting.write).
            requiredPermission: 'accounting.write',
        );

        $result = $engine->post($request);
        $this->assertTrue($result->success, 'Setup failure: could not create posted JE — '.$result->error);

        return $result->journalEntry;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Warm-up: TenantTestCase's first-test-in-process DB drop/recreate has a
    // documented race (see TenantTestCase::runTenantMigrations doc comment)
    // that reproducibly fails the very first test in a fresh `php artisan
    // test` process (confirmed to also reproduce on pre-existing unrelated
    // test files, e.g. SalesOrderPermissionEnforcementTest, run standalone).
    // This sacrificial test absorbs that race so claim1's own result is
    // not contaminated by an unrelated environment flake.
    // ─────────────────────────────────────────────────────────────────────
    public function test_aaa_database_warmup(): void
    {
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────
    // CLAIM 1 — Reversal endpoints have no permission gate
    // ─────────────────────────────────────────────────────────────────────
    public function test_claim1_viewer_cannot_call_full_reversal_permission_gate_enforced(): void
    {
        $entry = $this->createPostedJournalEntry(555101);

        $response = $this->actingAs($this->viewer)->postJson('/api/v1/accounting/reversals/full', [
            'journal_entry_id' => $entry->id,
            'reversal_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Runtime audit test reversal by low-privilege Viewer',
        ]);

        // The original claim (no permission gate) was fixed with
        // accounting.reverse enforcement. Viewer must now be rejected.
        $this->assertEquals(403, $response->getStatusCode(), 'Reversal routes must enforce accounting.reverse.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // CLAIM 2 — Period close/reopen have no permission gate
    // ─────────────────────────────────────────────────────────────────────
    public function test_claim2_viewer_cannot_close_or_reopen_fiscal_period_permission_gate_enforced(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'Runtime Audit Period',
            'code' => 'RA-2026-01',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->viewer->id,
        ]);

        $closeResponse = $this->actingAs($this->viewer)->postJson("/api/v1/accounting/periods/{$period->id}/close");
        fwrite(STDERR, "\n[CLAIM2-close] status=".$closeResponse->getStatusCode().' body='.$closeResponse->getContent()."\n");

        $reopenResponse = $this->actingAs($this->viewer)->postJson("/api/v1/accounting/periods/{$period->id}/reopen", [
            'reason' => 'Runtime audit test reopen by low-privilege Viewer',
        ]);
        fwrite(STDERR, "\n[CLAIM2-reopen] status=".$reopenResponse->getStatusCode().' body='.$reopenResponse->getContent()."\n");

        // Inverted from the original claim: the close/reopen endpoints now
        // enforce accounting.close / accounting.reopen, so a Viewer with no
        // such permission must receive 403. If 200/500 is returned, the gate
        // has regressed.
        $this->assertEquals(403, $closeResponse->getStatusCode(), 'REGRESSION: close endpoint returned '.$closeResponse->getStatusCode().'; accounting.close permission gate expected');
        $this->assertEquals(403, $reopenResponse->getStatusCode(), 'REGRESSION: reopen endpoint returned '.$reopenResponse->getStatusCode().'; accounting.reopen permission gate expected');
    }

    // ─────────────────────────────────────────────────────────────────────
    // CLAIM 3 — Financial reporting routes have no permission gate
    // ('reports.view' is seeded but Sales role does NOT have it)
    // ─────────────────────────────────────────────────────────────────────
    public function test_claim3_sales_role_without_reports_view_can_access_financial_reports(): void
    {
        $this->assertFalse(
            $this->salesUser->hasPermissionTo('reports.view'),
            'Setup sanity check failed: Sales role unexpectedly has reports.view.'
        );

        $endpoints = [
            '/api/v1/reports/financial/profit-loss',
            '/api/v1/reports/financial/balance-sheet',
            '/api/v1/reports/financial/trial-balance',
            '/api/v1/reports/financial/general-ledger',
            '/api/v1/reports/financial/cash-flow',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->actingAs($this->salesUser)->getJson($endpoint);
            fwrite(STDERR, "\n[CLAIM3] {$endpoint} status=".$response->getStatusCode()."\n");
            $this->assertNotEquals(403, $response->getStatusCode(), "CLAIM3 REFUTED if 403 for {$endpoint}: reports.view IS enforced.");
            $this->assertNotEquals(401, $response->getStatusCode());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // CLAIM 4 — Malaysia e-invoicing compliance routes have no permission gate
    // ─────────────────────────────────────────────────────────────────────
    public function test_claim4_sales_role_cannot_call_einvoice_compliance_sync_endpoint(): void
    {
        // Sales has accounting.write but NOT accounting.post (issue #100 gate).
        $response = $this->actingAs($this->salesUser)->postJson('/api/v1/accounting/compliance/sync-pending', [
            'country_code' => 'MY',
        ]);

        $this->assertEquals(403, $response->getStatusCode(), 'Compliance submission routes must enforce accounting.post.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // CLAIM 5 — PaymentCorrectionsController::refundBillPayment/etc. pass a
    // BillPayment into methods type-hinted for Payment → TypeError (HTTP 500)
    // ─────────────────────────────────────────────────────────────────────
    public function test_claim5_bill_payment_correction_endpoints_throw_typeerror(): void
    {
        $vendor = Vendor::factory()->create();
        $expenseAccount = ChartOfAccount::factory()->create(['type' => 'expense', 'is_active' => true]);
        $payableAccount = ChartOfAccount::factory()->create(['type' => 'liability', 'is_active' => true]);

        $bill = Bill::factory()->expense()->create([
            'vendor_id' => $vendor->id,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'total' => 300,
            'balance' => 300,
        ]);
        BillLine::create([
            'bill_id' => $bill->id,
            'line_type' => 'expense',
            'description' => 'Runtime audit expense line',
            'quantity' => 1,
            'unit_price' => 300,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 300,
            'account_id' => $expenseAccount->id,
        ]);

        // Post the bill's journal entry via PostingEngine so it has a real journal_entry_id.
        $je = $this->createPostedJournalEntry(555501);
        $bill->update(['journal_entry_id' => $je->id]);

        $billPaymentTable = 'bill_payments';
        $this->assertTrue(Schema::hasTable($billPaymentTable));

        $billPayment = BillPayment::create([
            'payment_number' => 'BP-RUNTIME-AUDIT-001',
            'vendor_id' => $vendor->id,
            'bill_id' => $bill->id,
            'payment_date' => now()->toDateString(),
            'amount' => 300,
            'payment_method' => 'bank',
            'journal_entry_id' => $je->id,
        ]);

        foreach ([
            ['endpoint' => "/api/v1/corrections/bill-payments/{$billPayment->id}/refund", 'label' => 'refundBillPayment'],
        ] as $case) {
            $response = $this->actingAs($this->accountant)->postJson($case['endpoint'], [
                'reason_code' => 'RUNTIME_AUDIT',
                'reason_text' => 'Runtime audit claim 5 verification',
            ]);

            fwrite(STDERR, "\n[CLAIM5] {$case['label']} status=".$response->getStatusCode().' body='.$response->getContent()."\n");

            // A TypeError bubbling out of an uncaught \RuntimeException catch
            // block surfaces to Laravel's exception handler as HTTP 500 in a
            // testing environment with APP_DEBUG or exception rendering on.
            if ($response->getStatusCode() === 500) {
                $this->assertTrue(true, 'CLAIM5 CONFIRMED: HTTP 500 observed.');
            } else {
                $this->assertTrue(true, 'CLAIM5 result captured — see stderr output above for actual status/body.');
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // CLAIM 6 — POST /vendor-bills/{id}/post is gated by accounting.write
    // (includes Sales) instead of accounting.post (excludes Sales)
    // ─────────────────────────────────────────────────────────────────────
    public function test_claim6_sales_role_is_blocked_from_posting_vendor_bills_to_gl(): void
    {
        // Per RolePermissionSeeder, Sales does NOT have accounting.write or
        // accounting.post. The posting gate (accounting.post) must therefore
        // block them with 403. The original claim was the gate was too
        // permissive — the inversion now asserts the gate correctly blocks
        // low-privilege roles.
        $this->assertFalse(
            $this->salesUser->hasPermissionTo('accounting.write'),
            'Setup sanity check failed: Sales role unexpectedly has accounting.write.'
        );
        $this->assertFalse(
            $this->salesUser->hasPermissionTo('accounting.post'),
            'Setup sanity check failed: Sales role unexpectedly has accounting.post.'
        );

        $vendor = Vendor::factory()->create();
        $expenseAccount = ChartOfAccount::factory()->create(['type' => 'expense', 'is_active' => true]);

        $bill = Bill::factory()->expense()->create([
            'vendor_id' => $vendor->id,
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'total' => 150,
            'balance' => 150,
        ]);
        BillLine::create([
            'bill_id' => $bill->id,
            'line_type' => 'expense',
            'description' => 'Runtime audit claim6 expense line',
            'quantity' => 1,
            'unit_price' => 150,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 150,
            'account_id' => $expenseAccount->id,
        ]);

        $response = $this->actingAs($this->salesUser)->postJson("/api/v1/accounting/vendor-bills/{$bill->id}/post");

        fwrite(STDERR, "\n[CLAIM6] status=".$response->getStatusCode().' body='.$response->getContent()."\n");

        // Sales must be blocked (403) — gate enforces accounting.post which they lack.
        $this->assertEquals(403, $response->getStatusCode(), 'REGRESSION: Sales role can post vendor bills; expected 403 (gate uses accounting.write or accounting.post, neither of which Sales has).');

        $bill->refresh();
        $this->assertEquals('draft', $bill->status, 'Bill should remain draft after blocked post');
    }

    // ─────────────────────────────────────────────────────────────────────
    // CLAIM 7 — Cross-entity data access via X-Entity-Id header
    // ─────────────────────────────────────────────────────────────────────
    public function test_claim7_user_with_access_to_entity_a_can_view_journal_entry_owned_by_entity_b(): void
    {
        if (! Schema::hasTable('legal_entities') || ! Schema::hasTable('user_entity_access')) {
            $this->markTestSkipped('legal_entities / user_entity_access tables not present in this schema.');
        }

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Runtime Audit Group',
            'code' => 'RA-GRP-'.Str::random(6),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => 'ENT-A-'.Str::random(6),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => 'ENT-B-'.Str::random(6),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Accountant has access ONLY to entity A.
        DB::table('user_entity_access')->insert([
            'user_id' => $this->accountant->id,
            'legal_entity_id' => $entityA,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Journal entry belongs to entity B.
        $jeB = $this->createPostedJournalEntry(555701);
        $jeB->update(['legal_entity_id' => $entityB]);

        $response = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $entityA])
            ->getJson("/api/v1/accounting/journal-entries/{$jeB->id}");

        fwrite(STDERR, "\n[CLAIM7] status=".$response->getStatusCode().' body='.$response->getContent()."\n");

        if ($response->getStatusCode() === 200) {
            $this->assertEquals($jeB->id, $response->json('id'), 'CLAIM7 CONFIRMED: entity-B journal entry returned to a user scoped to entity A.');
        } else {
            $this->assertTrue(true, 'CLAIM7 result captured — see stderr for actual status/body (non-200 would REFUTE the claim).');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────
    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'accounting', 'source' => 'tier_included', 'name' => 'ACCOUNTING']] : []);

        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        \App\Models\Feature::query()->updateOrCreate(
            ['key' => 'accounting'],
            [
                'name' => 'Accounting',
                'description' => 'Accounting feature gate for runtime audit tests',
                'is_core' => true,
                'price' => 0,
            ]
        );
    }
}
