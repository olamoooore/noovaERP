<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\Enums\ReasonCode;
use App\Accounting\PostingEngine;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #37 (fail-closed posting authority context).
 *
 * Replaces source-text checks with real PostingEngine::post() invocations
 * that exercise the gate framework end-to-end.
 */
class Issue37PostingFailClosedTest extends TenantTestCase
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

    private function makeRequest(?int $userId = null, ?string $tenantId = null): PostingRequest
    {
        $debitAccount = $this->createAccount('PFC-D-'.uniqid(), 'asset');
        $creditAccount = $this->createAccount('PFC-C-'.uniqid(), 'liability');

        return new PostingRequest(
            documentType: 'invoice',
            documentId: rand(1, 999999),
            description: 'Fail-closed test',
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
            tenantId: $tenantId === null ? null : (int) $tenantId,
        );
    }

    public function test_posting_without_tenant_context_is_rejected_with_canonical_reason(): void
    {
        // Unbind the tenant context to simulate CLI/batch without tenant
        $tenancy = app(\Stancl\Tenancy\Tenancy::class);
        $tenancy->end();
        app()->forgetInstance(\Stancl\Tenancy\Contracts\Tenant::class);

        $request = $this->makeRequest(userId: 1);

        $result = app(PostingEngine::class)->post($request);

        $this->assertFalse($result->success);
        $this->assertStringContainsString(
            ReasonCode::MISSING_TENANT_CONTEXT->value,
            $result->error ?? ''
        );
    }

    public function test_posting_with_tenant_and_user_succeeds(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest(userId: $user->id);

        $result = app(PostingEngine::class)->post($request);

        $this->assertTrue($result->success, "Posting failed: {$result->error}");
        $this->assertNotNull($result->journalEntry);
    }
}
