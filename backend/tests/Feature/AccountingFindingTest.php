<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Accounting\AccountingFinding;
use App\Models\User;
use App\Services\AccountingFindingService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

class AccountingFindingTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        Role::firstOrCreate(['name' => 'admin']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function as(User $user, string $method, string $uri, array $data = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->withoutMiddleware()
            ->json($method, $uri, $data);
    }

    // ─── Positive Tests ──────────────────────────────────────────

    public function test_create_finding(): void
    {
        $service = app(AccountingFindingService::class);

        $finding = $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
            'severity' => 'high',
            'title' => 'Unusual balance variance',
            'description' => 'Account 5000 shows 150% increase from prior period.',
            'account_code' => '5000',
            'amount' => 15000.00,
            'currency' => 'USD',
        ]);

        $this->assertNotNull($finding);
        $this->assertEquals('anomaly', $finding->finding_type);
        $this->assertEquals('high', $finding->severity);
        $this->assertEquals('open', $finding->status);
        $this->assertEquals('entity-001', $finding->entity_id);
        $this->assertEquals('5000', $finding->account_code);
    }

    public function test_finding_lifecycle(): void
    {
        $service = app(AccountingFindingService::class);
        $user = $this->admin();

        $finding = $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'missing_support',
            'title' => 'Missing invoice attachment',
            'description' => 'Journal entry JE-001 lacks supporting documentation.',
        ]);

        // Acknowledge
        $finding = $service->acknowledge($finding->id, $user->id);
        $this->assertEquals('acknowledged', $finding->status);
        $this->assertNotNull($finding->acknowledged_at);

        // Investigate
        $finding = $service->investigate($finding->id, $user->id);
        $this->assertEquals('investigating', $finding->status);

        // Resolve
        $finding = $service->resolve($finding->id, 'Documentation uploaded');
        $this->assertEquals('resolved', $finding->status);
        $this->assertNotNull($finding->resolved_at);
    }

    public function test_dismiss_finding(): void
    {
        $service = app(AccountingFindingService::class);

        $finding = $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
            'title' => 'False positive',
            'description' => 'This was a planned transfer.',
        ]);

        $finding = $service->dismiss($finding->id, 'False positive - planned transfer');
        $this->assertEquals('dismissed', $finding->status);
    }

    public function test_escalate_finding(): void
    {
        $service = app(AccountingFindingService::class);

        $finding = $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'control_weakness',
            'severity' => 'high',
            'title' => 'Segregation of duties violation',
            'description' => 'Same user approved and posted journal entry.',
        ]);

        $finding = $service->escalate($finding->id, 'Requires management review');
        $this->assertEquals('escalated', $finding->status);
        $this->assertNotNull($finding->escalated_at);
    }

    public function test_get_findings(): void
    {
        $service = app(AccountingFindingService::class);

        $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
            'title' => 'Finding 1',
            'description' => 'Description 1',
        ]);

        $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'missing_support',
            'title' => 'Finding 2',
            'description' => 'Description 2',
        ]);

        $findings = $service->getFindings('entity-001');
        $this->assertCount(2, $findings);
    }

    public function test_get_summary(): void
    {
        $service = app(AccountingFindingService::class);

        $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
            'severity' => 'critical',
            'title' => 'Critical finding',
            'description' => 'Critical issue',
        ]);

        $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'missing_support',
            'severity' => 'medium',
            'title' => 'Medium finding',
            'description' => 'Medium issue',
        ]);

        $summary = $service->getSummary('entity-001');
        $this->assertEquals(2, $summary['total']);
        $this->assertEquals(2, $summary['open']);
        $this->assertEquals(1, $summary['critical']);
    }

    public function test_risk_score_calculation(): void
    {
        $service = app(AccountingFindingService::class);

        $finding = $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
            'severity' => 'critical',
            'title' => 'High risk finding',
            'description' => 'Critical issue with large amount',
            'amount' => 50000.00,
        ]);

        $score = $finding->calculateRiskScore();
        $this->assertGreaterThan(0, $score);
    }

    // ─── Negative Tests ──────────────────────────────────────────

    public function test_invalid_finding_type_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = app(AccountingFindingService::class);
        $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'invalid_type',
            'title' => 'Test',
            'description' => 'Test',
        ]);
    }

    public function test_invalid_severity_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = app(AccountingFindingService::class);
        $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
            'severity' => 'invalid',
            'title' => 'Test',
            'description' => 'Test',
        ]);
    }

    public function test_missing_required_field_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = app(AccountingFindingService::class);
        $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
        ]);
    }

    public function test_api_store_finding(): void
    {
        $res = $this->as($this->admin(), 'post', '/api/v1/accounting/findings', [
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
            'title' => 'API test finding',
            'description' => 'Created via API',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.finding_type', 'anomaly')
            ->assertJsonPath('data.status', 'open');
    }

    public function test_api_index_findings(): void
    {
        $service = app(AccountingFindingService::class);
        $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
            'title' => 'Test',
            'description' => 'Test',
        ]);

        $res = $this->as($this->admin(), 'get', '/api/v1/accounting/findings/entity-001');
        $res->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_api_lifecycle(): void
    {
        $service = app(AccountingFindingService::class);
        $finding = $service->createFinding([
            'entity_id' => 'entity-001',
            'finding_type' => 'anomaly',
            'title' => 'Lifecycle test',
            'description' => 'Test lifecycle',
        ]);

        $user = $this->admin();

        // Acknowledge
        $this->as($user, 'post', "/api/v1/accounting/findings/{$finding->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');

        // Resolve
        $this->as($user, 'post', "/api/v1/accounting/findings/{$finding->id}/resolve", [
            'notes' => 'Fixed',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
    }
}
