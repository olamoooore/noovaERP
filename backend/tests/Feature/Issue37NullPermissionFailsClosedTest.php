<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\Enums\EligibilityResult;
use App\Accounting\Enums\GateResult;
use App\Accounting\PostingEngine;
use App\Accounting\Services\ActionEligibilityEvaluator;
use App\Accounting\Services\Gates\PermissionGate;
use App\Models\ChartOfAccount;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * AC4 verification for issue #37: missing required_permission on a
 * material action must produce ERROR (not silent PASS).
 *
 * This is the gap the original hostile review flagged: a posting call
 * with no resolvable required_permission was being silently PASSed by
 * PermissionGate's null-permission branch. After the fix, the gate
 * returns ERROR for material actions (POST_JOURNAL, REVERSE, etc.),
 * which ActionEligibilityEvaluator propagates to the outer PostingResult
 * via hasError().
 */
class Issue37NullPermissionFailsClosedTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
    }

    private function createAccount(string $code, string $type): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $code,
            'name' => "Test {$code}",
            'type' => $type,
            'account_type' => $type,
            'is_active' => true,
        ]);
    }

    private function makeRequestWithCustomDocType(
        string $documentType,
        ?int $userId = null,
        ?string $requiredPermission = null,
    ): PostingRequest {
        $debitAccount = $this->createAccount('NPC-D-'.uniqid(), 'asset');
        $creditAccount = $this->createAccount('NPC-C-'.uniqid(), 'liability');

        return new PostingRequest(
            documentType: $documentType,
            documentId: rand(1, 999999),
            description: 'AC4 null permission test',
            postingDate: now()->toDateString(),
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'debit',
                    accountId: $debitAccount->id,
                    debit: 100.00,
                    credit: 0,
                ),
                new PostingRequestLine(
                    accountMappingKey: 'credit',
                    accountId: $creditAccount->id,
                    debit: 0,
                    credit: 100.00,
                ),
            ],
            userId: $userId,
            // tenantId omitted — engine falls back to tenant()->id via middleware
            requiredPermission: $requiredPermission,
        );
    }

    public function test_unknown_document_type_with_no_permission_fails_closed_with_error(): void
    {
        // Seed permissions and roles
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('accounting.write');

        // Use an unknown documentType that the resolvePermissionForDocumentType()
        // mapping doesn't cover. This forces required_permission to be null.
        $request = $this->makeRequestWithCustomDocType(
            documentType: 'totally_unknown_doc_type',
            userId: $user->id,
        );

        $result = app(PostingEngine::class)->post($request);

        // AC4: missing required_permission on material action must fail closed
        $this->assertFalse(
            $result->success,
            "AC4 violated — unknown document type with null permission was silently allowed: {$result->error}"
        );

        $this->assertNotNull($result->error, 'AC4 requires a non-null error');
        $this->assertStringContainsString(
            'Authority gate evaluation failed',
            $result->error,
            'AC4: error must come from authority gate evaluation, not balance check or account resolution'
        );
    }

    public function test_known_document_type_with_granted_permission_still_succeeds(): void
    {
        // Regression guard: the fix must not break legitimate posts.
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('accounting.write');

        $request = $this->makeRequestWithCustomDocType(
            documentType: 'invoice', // covered by resolvePermissionForDocumentType()
            userId: $user->id,
        );

        $result = app(PostingEngine::class)->post($request);

        $this->assertTrue($result->success, "Known document type with permission should post: {$result->error}");
        $this->assertNotNull($result->journalEntry);
    }

    public function test_permission_gate_directly_returns_error_for_null_permission_on_post_journal(): void
    {
        // Direct unit-style check of the gate's new behavior
        $gate = app(PermissionGate::class);

        $result = $gate->evaluate([
            'action' => 'POST_JOURNAL',
            'actor_id' => '1',
            'tenant_id' => 't1',
            'required_permission' => null,
        ]);

        $this->assertSame(
            GateResult::ERROR,
            $result->result,
            'AC4: null required_permission on POST_JOURNAL must produce ERROR (not PASS, not BLOCK, not NOT_APPLICABLE)'
        );
        $this->assertSame(
            'MISSING_REQUIRED_PERMISSION_FOR_MATERIAL_ACTION',
            $result->reasonCode,
        );
    }

    public function test_permission_gate_returns_pass_for_null_permission_on_non_material_action(): void
    {
        // Backward-compat guard: non-material actions (e.g., read-only or
        // auxiliary paths) may legitimately have no permission requirement.
        $gate = app(PermissionGate::class);

        $result = $gate->evaluate([
            'action' => 'READ_ONLY_VIEW',
            'actor_id' => '1',
            'tenant_id' => 't1',
            'required_permission' => null,
        ]);

        $this->assertSame(GateResult::PASS, $result->result);
    }

    public function test_permission_gate_returns_pass_for_null_action(): void
    {
        // If `action` is not in context at all (e.g., legacy callers that
        // pre-date the action-aware behavior), preserve previous behavior:
        // null permission → PASS (backward compat).
        $gate = app(PermissionGate::class);

        $result = $gate->evaluate([
            'actor_id' => '1',
            'tenant_id' => 't1',
            'required_permission' => null,
        ]);

        $this->assertSame(GateResult::PASS, $result->result);
    }

    public function test_evaluator_propagates_permission_error_to_block_or_error_final_result(): void
    {
        // When PermissionGate returns ERROR, the evaluator's final result
        // must be ERROR (not ALLOW, not BLOCK). PostingEngine then translates
        // that to a failure (hasError()).
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('accounting.write');

        $request = $this->makeRequestWithCustomDocType(
            documentType: 'unknown_doc_type',
            userId: $user->id,
        );

        $result = app(PostingEngine::class)->post($request);

        // PostingEngine translates evaluator ERROR to PostingResult::failure
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Authority gate evaluation failed', $result->error);
    }
}
