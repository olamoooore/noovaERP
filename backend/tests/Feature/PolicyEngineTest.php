<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Platform\Policies\PolicyEngine;
use App\Platform\Policies\PolicyResult;
use App\Platform\Policies\PolicyRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class PolicyEngineTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected PolicyEngine $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->service = new PolicyEngine();
    }

    public function test_register_creates_rule(): void
    {
        $result = $this->service->register(
            'invoice',
            'create',
            [
                'effect' => 'deny',
                'conditions' => [['field' => 'amount', 'operator' => 'greater_than', 'value' => 10000]],
                'message' => 'Amount too high',
            ],
            10
        );

        $this->assertNotEmpty($result->id);
        $this->assertEquals('invoice', $result->entity_type);
        $this->assertEquals('create', $result->action);
        $this->assertEquals(10, $result->priority);
        $this->assertTrue($result->is_active);
    }

    public function test_evaluate_allows_when_no_rules(): void
    {
        $result = $this->service->evaluate('invoice', 'create', ['amount' => 100]);

        $this->assertInstanceOf(PolicyResult::class, $result);
        $this->assertTrue($result->allowed);
    }

    public function test_evaluate_denies_when_rule_matches(): void
    {
        $this->service->register('invoice', 'create', [
            'effect' => 'deny',
            'conditions' => [['field' => 'amount', 'operator' => 'greater_than', 'value' => 10000]],
            'message' => 'Amount exceeds limit',
        ]);

        $result = $this->service->evaluate('invoice', 'create', ['amount' => 15000]);

        $this->assertFalse($result->allowed);
        $this->assertEquals('Amount exceeds limit', $result->message());
    }

    public function test_evaluate_allows_when_rule_not_matched(): void
    {
        $this->service->register('invoice', 'create', [
            'effect' => 'deny',
            'conditions' => [['field' => 'amount', 'operator' => 'greater_than', 'value' => 10000]],
            'message' => 'Amount exceeds limit',
        ]);

        $result = $this->service->evaluate('invoice', 'create', ['amount' => 5000]);

        $this->assertTrue($result->allowed);
    }

    public function test_discover_returns_all_rules_for_entity(): void
    {
        $this->service->register('invoice', 'create', ['effect' => 'deny', 'conditions' => []]);
        $this->service->register('invoice', 'update', ['effect' => 'deny', 'conditions' => []]);
        $this->service->register('bill', 'create', ['effect' => 'deny', 'conditions' => []]);

        $rules = $this->service->discover('invoice');

        $this->assertCount(2, $rules);
    }

    public function test_deactivate_disables_rule(): void
    {
        $rule = $this->service->register('invoice', 'create', ['effect' => 'deny', 'conditions' => []]);

        $deactivated = $this->service->deactivate($rule->id);

        $this->assertTrue($deactivated);

        $result = $this->service->evaluate('invoice', 'create', ['amount' => 100]);
        $this->assertTrue($result->allowed);
    }

    public function test_evaluate_uses_priority_order(): void
    {
        $this->service->register('invoice', 'create', [
            'effect' => 'deny',
            'conditions' => [['field' => 'status', 'operator' => 'equals', 'value' => 'draft']],
            'message' => 'Low priority deny',
        ], 5);

        $this->service->register('invoice', 'create', [
            'effect' => 'deny',
            'conditions' => [['field' => 'amount', 'operator' => 'greater_than', 'value' => 10000]],
            'message' => 'High priority deny',
        ], 10);

        $result = $this->service->evaluate('invoice', 'create', ['status' => 'draft', 'amount' => 15000]);

        $this->assertFalse($result->allowed);
        $this->assertEquals('High priority deny', $result->message());
    }

    public function test_evaluate_complex_conditions(): void
    {
        $this->service->register('invoice', 'create', [
            'effect' => 'deny',
            'conditions' => [
                ['field' => 'amount', 'operator' => 'greater_than', 'value' => 5000],
                ['field' => 'department', 'operator' => 'equals', 'value' => 'sales'],
            ],
            'message' => 'Sales invoices over 5000 denied',
        ]);

        $result = $this->service->evaluate('invoice', 'create', ['amount' => 6000, 'department' => 'sales']);
        $this->assertFalse($result->allowed);

        $result2 = $this->service->evaluate('invoice', 'create', ['amount' => 6000, 'department' => 'hr']);
        $this->assertTrue($result2->allowed);
    }
}
