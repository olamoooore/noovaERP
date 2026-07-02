<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Features\AI\Services\AIReasoningRecordService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * AI Reasoning Record evidence test — ADC Section 9.
 *
 * Proves the reasoning audit trail captures who, what data, what reasoning,
 * what output, and is retrievable per-tenant (audit traceability).
 */
class AiReasoningRecordTest extends TenantTestCase
{
    private AIReasoningRecordService $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('test-tenant');
        $this->records = app(AIReasoningRecordService::class);
    }

    public function test_reasoning_record_captures_full_audit_context(): void
    {
        $user = User::factory()->create();

        $id = $this->records->recordReasoning([
            'tenant_id' => 'test-tenant',
            'user_id' => $user->id,
            'module' => 'accounting',
            'action' => 'suggest_ap_coding',
            'input_prompt' => 'Code this AP invoice',
            'data_accessed' => ['invoices:inv-1', 'vendors:ven-9'],
            'reasoning_steps' => ['matched past OCR postings', 'ranked by frequency'],
            'output' => 'Suggested account 5100 - Office Supplies',
            'confidence_score' => 0.87,
            'duration_ms' => 1200,
            'model_used' => 'gpt-4',
            'tokens_used' => 540,
            'cost' => 0.021,
        ]);

        $row = DB::table('ai_reasoning_records')->where('id', $id)->first();

        $this->assertNotNull($row);
        $this->assertSame('test-tenant', $row->tenant_id);
        $this->assertEquals($user->id, $row->user_id);
        $this->assertSame('suggest_ap_coding', $row->action);
        $this->assertSame('accounting', $row->module);

        $dataAccessed = json_decode((string) $row->data_accessed, true);
        $this->assertContains('invoices:inv-1', $dataAccessed);
        $this->assertSame('gpt-4', $row->model_used);
    }

    public function test_reasoning_record_can_be_updated_with_output(): void
    {
        $user = User::factory()->create();
        $id = $this->records->recordReasoning([
            'tenant_id' => 'test-tenant',
            'user_id' => $user->id,
            'module' => 'accounting',
            'action' => 'detect_close_anomalies',
        ]);

        $updated = $this->records->updateRecord($id, [
            'output' => 'Anomaly: period 06 closing balance mismatch',
            'confidence_score' => 0.92,
            'cost' => 0.03,
        ]);

        $this->assertTrue($updated);
        // decimal(10,6) stores full precision.
        $this->assertSame('0.030000', (string) DB::table('ai_reasoning_records')->where('id', $id)->value('cost'));
    }

    public function test_tenant_records_are_scoped_to_tenant(): void
    {
        $user = User::factory()->create();

        $this->records->recordReasoning([
            'tenant_id' => 'test-tenant',
            'user_id' => $user->id,
            'module' => 'accounting',
            'action' => 'review_revenue',
        ]);
        $this->records->recordReasoning([
            'tenant_id' => 'other-tenant',
            'user_id' => $user->id,
            'module' => 'accounting',
            'action' => 'review_revenue',
        ]);

        $records = $this->records->getTenantRecords('test-tenant');

        $this->assertCount(1, $records);
        $this->assertSame('test-tenant', $records[0]->tenant_id);
    }
}
