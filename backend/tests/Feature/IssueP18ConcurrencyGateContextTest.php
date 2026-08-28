<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Accounting\Services\Gates\ConcurrencyGate;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue P1-8 — ConcurrencyGate context wiring.
 * PostingEngine must forward expectedRecordVersion and currentRecordVersion
 * to the gate evaluator so optimistic locking on the source aggregate is
 * actually enforced when the caller opts in.
 */
class IssueP18ConcurrencyGateContextTest extends TenantTestCase
{
    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
    }

    public function test_concurrency_gate_returns_not_applicable_without_version_context(): void
    {
        $gate = app(ConcurrencyGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'document_type' => 'invoice',
        ]);

        $this->assertSame('NOT_APPLICABLE', $result->result->value);
    }

    public function test_concurrency_gate_passes_when_versions_match(): void
    {
        $gate = app(ConcurrencyGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'document_type' => 'invoice',
            'expected_record_version' => 5,
            'current_record_version' => 5,
        ]);

        $this->assertSame('PASS', $result->result->value);
    }

    public function test_concurrency_gate_blocks_when_versions_differ(): void
    {
        $gate = app(ConcurrencyGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'document_type' => 'invoice',
            'expected_record_version' => 5,
            'current_record_version' => 7,
        ]);

        $this->assertSame('BLOCK', $result->result->value);
        $this->assertSame('SOURCE_VERSION_CONFLICT', $result->reasonCode);
    }

    public function test_concurrency_gate_errors_when_current_version_missing(): void
    {
        $gate = app(ConcurrencyGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'document_type' => 'invoice',
            'expected_record_version' => 5,
        ]);

        $this->assertSame('ERROR', $result->result->value);
        $this->assertSame('VERSION_NOT_AVAILABLE', $result->reasonCode);
    }

    public function test_posting_engine_forwards_version_context_through_evaluator(): void
    {
        // Create the supporting infrastructure
        $chartOfAccount = ChartOfAccount::create([
            'code' => '1100-'.uniqid(),
            'name' => 'Test AR',
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
        ]);

        // Use a stub user for userId context
        $user = User::factory()->create();
        \Illuminate\Support\Facades\Auth::login($user);

        // Build a request with explicit expected/current record versions
        $request = new PostingRequest(
            documentType: 'invoice',
            documentId: 999,
            postingDate: now()->toDateString(),
            description: 'Test with version context',
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'accounts_receivable',
                    accountId: $chartOfAccount->id,
                    debit: 100,
                    credit: 0,
                ),
                new PostingRequestLine(
                    accountMappingKey: 'sales_revenue',
                    accountId: $chartOfAccount->id,
                    debit: 0,
                    credit: 100,
                ),
            ],
            expectedRecordVersion: 3,
            currentRecordVersion: 4, // Mismatch on purpose
        );

        // Post through engine — should fail with SOURCE_VERSION_CONFLICT
        $result = app(PostingEngine::class)->post($request);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('SOURCE_VERSION_CONFLICT', (string) $result->error);
    }
}
