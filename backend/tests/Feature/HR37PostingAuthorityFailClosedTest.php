<?php

/**
 * HR37-03 / HR37-04 — Posting authority fail-closed runtime proof.
 *
 * Issue #37 re-review (HR37-03, P0 blocker) required that an unseeded material
 * accounting permission must NEVER fail open. Before the fix,
 * AccountingAccessService::userHasPermission() ended in a "Foundation phase"
 * `return true` that converted a missing permission catalogue into
 * authorization, and a broad role-name fallback could do the same.
 *
 * These tests exercise the REAL posting authority chain:
 *   PostingEngine::post()
 *     -> ActionEligibilityEvaluator::evaluate()
 *       -> PermissionGate::evaluate()
 *         -> AccountingAccessService::userHasPermission()
 *
 * Coverage required by the re-review:
 *  - NEGATIVE: unseeded material permission + privileged-sounding role => BLOCK,
 *    no JournalEntry / JournalEntryLine, snapshot identifies the failed decision.
 *  - NEGATIVE: an arbitrary privileged role NAME alone (no canonical mapping for
 *    the exact material permission) does not convert the scenario into PASS.
 *  - POSITIVE: explicit Spatie grant => PASS, exactly one journal, authority
 *    source identified.
 *  - POSITIVE: canonical role->permission mapping => PASS, authority source
 *    identified.
 *  - SERVICE-FAILURE: access-service exception => BLOCK (PERMISSION_SERVICE_ERROR),
 *    not PASS.
 *  - HTTP / REAL-ROUTE: an actor without posting authority hitting the production
 *    POST /accounting/journal-entries/{id}/post route is denied (403) and cannot
 *    cause the posting.
 */

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\AuthorityEvaluationSnapshot;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountingAccessService;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

class HR37PostingAuthorityFailClosedTest extends TenantTestCase
{
    protected string $tenantId;

    /** A material permission that is deliberately NOT seeded by RolePermissionSeeder. */
    private const UNSEEDED_MATERIAL_PERMISSION = 'accounting.reversal_approve';

    /** A second unseeded material permission used for the role-name-alone proof. */
    private const UNSEEDED_MATERIAL_PERMISSION_2 = 'accounting.backdate_override';

    protected function setUp(): void
    {
        parent::setUp();

        // The PostingEngine attributes audit records to a tenant user; seed id=1.
        User::firstOrCreate(
            ['id' => 1],
            ['name' => 'HR37 Audit User', 'email' => 'hr37-audit@local', 'password' => bcrypt('password')],
        );

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        // Seed the canonical role->permission catalogue. This intentionally does
        // NOT contain self::UNSEEDED_MATERIAL_PERMISSION*, which is the point of
        // the negative proofs.
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Admin', 'Accountant', 'Owner', 'Viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'HR37 Fail-Closed Test Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Deterministic expense/liability accounts. The COA seeder populates many
     * standard codes, so ChartOfAccount::factory()'s random codes can collide
     * (e.g. 7201). firstOrCreate on reserved-for-test codes avoids that.
     *
     * @return array{0: ChartOfAccount, 1: ChartOfAccount} [debit, credit]
     */
    private function resolveAccounts(): array
    {
        $debit = ChartOfAccount::firstOrCreate(
            ['code' => '9901'],
            ['name' => 'HR37 Expense', 'type' => 'expense', 'is_active' => true],
        );
        $credit = ChartOfAccount::firstOrCreate(
            ['code' => '9902'],
            ['name' => 'HR37 Liability', 'type' => 'liability', 'is_active' => true],
        );

        return [$debit, $credit];
    }

    private function makeRequest(string $requiredPermission, int $userId, int $documentId = 9101): PostingRequest
    {
        [$debit, $credit] = $this->resolveAccounts();

        return new PostingRequest(
            documentType: 'hr37_doc',
            documentId: $documentId,
            postingDate: now()->addDays(30)->toDateString(),
            description: 'HR37 authority fail-closed posting',
            lines: [
                new PostingRequestLine('expense', $debit->id, 300.00, 0.00, 'Debit'),
                new PostingRequestLine('payable', $credit->id, 0.00, 300.00, 'Credit'),
            ],
            userId: $userId,
            tenantId: null, // resolved from bound tenant context
            requiredPermission: $requiredPermission,
        );
    }

    private function makeUser(string $email, ?string $role = null, bool $superAdminFlag = false): User
    {
        $user = User::factory()->create(['email' => $email]);
        if ($superAdminFlag) {
            $user->forceFill(['is_super_admin' => true])->save();
        }
        if ($role) {
            $user->assignRole($role);
        }
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role ? strtolower($role) : 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** Return the ACTOR_PERMISSION gate result row from the latest snapshot. */
    private function latestPermissionGateResult(): ?array
    {
        $snapshot = AuthorityEvaluationSnapshot::query()->latest('id')->first();
        $this->assertNotNull($snapshot, 'Expected an AuthorityEvaluationSnapshot to be persisted.');

        return collect($snapshot->gate_results ?? [])->firstWhere('gate_code', 'ACTOR_PERMISSION');
    }

    private function latestSnapshotFinalResult(): ?string
    {
        return AuthorityEvaluationSnapshot::query()->latest('id')->value('final_result');
    }

    // ─────────────────────────────────────────────────────────────────────
    // NEGATIVE RUNTIME PROOF (HR37-03)
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function unseeded_material_permission_with_privileged_role_is_denied_through_real_posting_chain(): void
    {
        // Sanity: the permission is genuinely absent from the catalogue.
        $this->assertFalse(
            Permission::where('name', self::UNSEEDED_MATERIAL_PERMISSION)->exists(),
            'Setup failure: the material permission under test must be unseeded.'
        );

        // Actor holds a privileged-sounding role (Admin) but there is NO canonical
        // role->permission mapping for this exact material permission, and the
        // permission is not seeded.
        $admin = $this->makeUser('hr37-admin@fail-closed.test', 'Admin');
        $this->assertFalse($admin->is_super_admin ?? false, 'Actor must not carry the super-admin flag.');

        $engine = app(PostingEngineInterface::class);
        $result = $engine->post($this->makeRequest(self::UNSEEDED_MATERIAL_PERMISSION, $admin->id, 9101));

        // Posting rejected.
        $this->assertFalse($result->success, 'HR37-03 REGRESSION: unseeded material permission failed open.');
        $this->assertNull($result->journalEntryId);
        $this->assertStringContainsString('INSUFFICIENT_PERMISSION', (string) $result->error);

        // No ledger mutation.
        $this->assertSame(0, JournalEntry::count(), 'No JournalEntry may be created on denial.');
        $this->assertSame(0, JournalEntryLine::count(), 'No JournalEntryLine may be created on denial.');

        // Authority snapshot identifies the failed permission decision.
        $this->assertSame('BLOCK', $this->latestSnapshotFinalResult());
        $gate = $this->latestPermissionGateResult();
        $this->assertNotNull($gate, 'Snapshot must record the ACTOR_PERMISSION gate.');
        $this->assertSame('BLOCK', $gate['result']);
        $this->assertSame('INSUFFICIENT_PERMISSION', $gate['reason_code']);
    }

    /** @test */
    public function arbitrary_privileged_role_name_alone_does_not_satisfy_unseeded_material_permission(): void
    {
        $this->assertFalse(
            Permission::where('name', self::UNSEEDED_MATERIAL_PERMISSION_2)->exists(),
            'Setup failure: the material permission under test must be unseeded.'
        );

        // 'Owner' is a privileged-sounding role name. Per the canonical
        // role->permission rule, a role NAME alone is not authority for an exact
        // material action that has no canonical mapping and is unseeded.
        $owner = $this->makeUser('hr37-owner@fail-closed.test', 'Owner');
        $this->assertFalse($owner->is_super_admin ?? false);

        $engine = app(PostingEngineInterface::class);
        $result = $engine->post($this->makeRequest(self::UNSEEDED_MATERIAL_PERMISSION_2, $owner->id, 9102));

        $this->assertFalse($result->success, 'HR37-03 REGRESSION: a privileged role name alone failed open.');
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalEntryLine::count());
        $this->assertSame('BLOCK', $this->latestSnapshotFinalResult());

        $gate = $this->latestPermissionGateResult();
        $this->assertSame('BLOCK', $gate['result']);
        $this->assertSame('INSUFFICIENT_PERMISSION', $gate['reason_code']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POSITIVE REGRESSIONS (authority must still work)
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function explicit_spatie_permission_grant_passes_and_identifies_authority_source(): void
    {
        // Actor has NO privileged role — authority comes solely from an explicit
        // Spatie grant of the exact required permission.
        $user = $this->makeUser('hr37-explicit@fail-closed.test', 'Viewer');
        $user->givePermissionTo('accounting.write');

        // Authority source = explicit granted permission.
        $this->assertTrue($user->hasPermissionTo('accounting.write'));

        $engine = app(PostingEngineInterface::class);
        $result = $engine->post($this->makeRequest('accounting.write', $user->id, 9103));

        $this->assertTrue($result->success, 'Legitimate explicit authority must not be blocked: '.$result->error);
        $this->assertNotNull($result->journalEntry);
        $this->assertSame('posted', $result->journalEntry->status);

        // Exactly one intended journal result.
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(2, JournalEntryLine::where('journal_entry_id', $result->journalEntryId)->count());

        $this->assertSame('ALLOW', $this->latestSnapshotFinalResult());
        $gate = $this->latestPermissionGateResult();
        $this->assertSame('PASS', $gate['result']);
    }

    /** @test */
    public function canonical_role_permission_mapping_passes_and_identifies_authority_source(): void
    {
        // Accountant is canonically mapped to accounting.write by
        // RolePermissionSeeder (the same canonical policy the runtime authority
        // path uses). No explicit per-user grant is made here.
        $accountant = $this->makeUser('hr37-accountant@fail-closed.test', 'Accountant');

        // Authority source = canonical role->permission mapping.
        $this->assertTrue(
            $accountant->hasPermissionTo('accounting.write'),
            'Setup failure: Accountant role must canonically carry accounting.write.'
        );
        $this->assertFalse($accountant->is_super_admin ?? false);

        $engine = app(PostingEngineInterface::class);
        $result = $engine->post($this->makeRequest('accounting.write', $accountant->id, 9104));

        $this->assertTrue($result->success, 'Canonical role authority must not be blocked: '.$result->error);
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame('ALLOW', $this->latestSnapshotFinalResult());
        $this->assertSame('PASS', $this->latestPermissionGateResult()['result']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SERVICE-FAILURE REGRESSION (exception => BLOCK, not PASS)
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function access_service_failure_results_in_block_not_pass(): void
    {
        $user = $this->makeUser('hr37-service-failure@fail-closed.test', 'Accountant');

        // Bind a failing access service BEFORE the evaluator singleton resolves
        // PermissionGate (the only gate resolved through the container).
        $mock = \Mockery::mock(AccountingAccessService::class);
        $mock->shouldReceive('userHasPermission')
            ->andThrow(new \RuntimeException('access service unavailable'));
        // Other gates / entitlement checks must not be destabilised by the mock.
        $mock->shouldReceive('isModuleEntitled')->andReturn(true);
        $mock->shouldReceive('userHasEntitlement')->andReturn(true);
        $mock->shouldReceive('canWrite')->andReturn(true);
        $mock->shouldReceive('canPost')->andReturn(true);
        $mock->shouldIgnoreMissing();
        $this->app->instance(AccountingAccessService::class, $mock);

        // ActionEligibilityEvaluator is a container singleton that captures a
        // PermissionGate instance at construction. If it (or PermissionGate) was
        // already resolved with the real access service, forget them so they are
        // rebuilt against the failing mock when PostingEngine is resolved next.
        $this->app->forgetInstance(\App\Accounting\Services\ActionEligibilityEvaluator::class);
        $this->app->forgetInstance(\App\Accounting\Services\Gates\PermissionGate::class);

        $engine = app(PostingEngineInterface::class);
        $result = $engine->post($this->makeRequest('accounting.write', $user->id, 9105));

        $this->assertFalse($result->success, 'A permission-service failure must BLOCK, not PASS.');
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalEntryLine::count());
        $this->assertSame('BLOCK', $this->latestSnapshotFinalResult());

        $gate = $this->latestPermissionGateResult();
        $this->assertSame('BLOCK', $gate['result']);
        $this->assertSame('PERMISSION_SERVICE_ERROR', $gate['reason_code']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HTTP / REAL-ROUTE EVIDENCE
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function viewer_without_posting_authority_is_denied_on_production_post_route(): void
    {
        // Create a real draft journal entry (as a privileged actor) so we can
        // prove the unauthorized actor cannot cause it to post.
        $accountant = $this->makeUser('hr37-http-accountant@fail-closed.test', 'Accountant');
        $viewer = $this->makeUser('hr37-http-viewer@fail-closed.test', 'Viewer');

        [$debit, $credit] = $this->resolveAccounts();

        $draft = JournalEntry::create([
            'entry_number' => 'HR37-HTTP-DRAFT-'.Str::random(6),
            'entry_date' => now()->addDays(10)->toDateString(),
            'description' => 'HR37 HTTP draft',
            'status' => 'draft',
            'reference' => 'HR37-HTTP-1',
            'created_by' => $accountant->id,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $draft->id, 'account_id' => $debit->id,
            'debit' => 100.00, 'credit' => 0.00, 'description' => 'D',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $draft->id, 'account_id' => $credit->id,
            'debit' => 0.00, 'credit' => 100.00, 'description' => 'C',
        ]);

        // Viewer holds neither accounting.write nor accounting.post.
        $this->assertFalse($viewer->hasPermissionTo('accounting.post'));

        $response = $this->actingAs($viewer)
            ->postJson("/api/v1/accounting/journal-entries/{$draft->id}/post");

        $this->assertEquals(403, $response->getStatusCode(),
            'Production post route must deny an actor without posting authority; got '.$response->getStatusCode());

        // The draft must remain unposted — the unauthorized actor caused no posting.
        $this->assertSame('draft', $draft->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Local helpers (mirrored from RuntimeAuthVerificationTest)
    // ─────────────────────────────────────────────────────────────────────

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key, 'is_entitled' => $isEntitled,
            'source' => $isEntitled ? 'tier_included' : null, 'name' => strtoupper($key),
        ]);
        $mock->shouldReceive('all')->andReturn($isEntitled
            ? [['key' => 'accounting', 'source' => 'tier_included', 'name' => 'ACCOUNTING']] : []);
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
            ['name' => 'Accounting', 'description' => 'HR37 feature gate', 'is_core' => true, 'price' => 0],
        );
    }
}
