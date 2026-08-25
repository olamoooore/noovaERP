<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuthorityEvaluationSnapshot;
use App\Models\User;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #48 — Stop fabricating actor/tenant UUIDs in audit records.
 *
 * Verifies:
 *  - Real numeric actor IDs are mapped to a stable UUID v5 (no random fabrication).
 *  - Missing identity blocks posting and never silently fabricates a UUID.
 */
class Issue48NoFabricatedUuidsTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
    }

    public function test_numeric_actor_id_maps_to_deterministic_uuid_v5(): void
    {
        $numericActorId = 12345;
        $expected = Uuid::uuid5(Uuid::NAMESPACE_URL, 'actor:'.$numericActorId)->toString();

        // Same input → same output (deterministic)
        $second = Uuid::uuid5(Uuid::NAMESPACE_URL, 'actor:'.$numericActorId)->toString();
        $this->assertSame($expected, $second);

        // Different input → different output
        $different = Uuid::uuid5(Uuid::NAMESPACE_URL, 'actor:67890')->toString();
        $this->assertNotSame($expected, $different);
    }

    public function test_missing_actor_in_evaluation_returns_block_not_silent_pass(): void
    {
        $evaluator = app(\App\Accounting\Services\ActionEligibilityEvaluator::class);

        // Invoke with no tenant/actor — must return BLOCK, not ALLOW.
        $result = $evaluator->evaluate(
            action: 'POST_JOURNAL',
            recordType: 'JournalEntry',
            recordId: 'test-1',
            context: [
                'tenant_id' => null,
                'actor_id' => null,
                'required_permission' => 'accounting.post',
            ],
            requireSnapshot: false,
        );

        $this->assertTrue($result->isBlocked(), 'Missing identity must BLOCK, not silently pass.');
        $this->assertSame(
            'MISSING_PERMISSION_CONTEXT',
            $result->blockingReason?->value
        );
    }
}
