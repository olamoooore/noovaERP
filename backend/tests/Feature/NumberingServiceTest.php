<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Platform\Numbering\NumberingSequence;
use App\Platform\Numbering\NumberingService;
use Tests\TenantTestCase;

/**
 * NumberingServiceTest — tests for the platform-wide Document Numbering Service (Pillar 8).
 */
class NumberingServiceTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected NumberingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->service = app(NumberingService::class);
    }

    // ─── Registration ────────────────────────────────────────────

    public function test_register_creates_sequence(): void
    {
        $seq = $this->service->register('invoice', 'INV', 6, null, $this->tenantUuid);

        $this->assertNotNull($seq);
        $this->assertSame('INV', $seq->prefix);
        $this->assertSame(6, $seq->padding);
        $this->assertSame(0, $seq->current_number);
    }

    public function test_register_idempotent(): void
    {
        $this->service->register('invoice', 'INV', 6, null, $this->tenantUuid);
        $this->service->register('invoice', 'INV', 6, null, $this->tenantUuid);

        $this->assertSame(1, NumberingSequence::where('tenant_id', $this->tenantUuid)->count());
    }

    // ─── Number Generation ───────────────────────────────────────

    public function test_next_increments_number(): void
    {
        $this->service->register('invoice', 'INV', 6, null, $this->tenantUuid);

        $n1 = $this->service->next('invoice', $this->tenantUuid);
        $n2 = $this->service->next('invoice', $this->tenantUuid);
        $n3 = $this->service->next('invoice', $this->tenantUuid);

        $this->assertSame('INV-000001', $n1);
        $this->assertSame('INV-000002', $n2);
        $this->assertSame('INV-000003', $n3);
    }

    public function test_next_with_custom_format(): void
    {
        $this->service->register('receipt', 'RCP', 4, 'RCP-{YYYY}-{NNNN}', $this->tenantUuid);

        $n = $this->service->next('receipt', $this->tenantUuid);

        $this->assertStringStartsWith('RCP-'.date('Y').'-', $n);
    }

    public function test_next_with_yearly_reset(): void
    {
        $this->service->register('journal', 'JNL', 4, null, $this->tenantUuid, 'yearly');

        $n1 = $this->service->next('journal', $this->tenantUuid);
        $this->assertSame('JNL-0001', $n1);
    }

    public function test_next_batch_generates_multiple(): void
    {
        $this->service->register('quote', 'QUO', 5, null, $this->tenantUuid);

        $numbers = $this->service->nextBatch('quote', $this->tenantUuid, 3);

        $this->assertCount(3, $numbers);
        $this->assertSame('QUO-00001', $numbers[0]);
        $this->assertSame('QUO-00002', $numbers[1]);
        $this->assertSame('QUO-00003', $numbers[2]);
    }

    // ─── Peek ────────────────────────────────────────────────────

    public function test_peek_does_not_increment(): void
    {
        $this->service->register('invoice', 'INV', 6, null, $this->tenantUuid);

        $peek1 = $this->service->peek('invoice', $this->tenantUuid);
        $peek2 = $this->service->peek('invoice', $this->tenantUuid);

        $this->assertSame('INV-000001', $peek1);
        $this->assertSame('INV-000001', $peek2); // Same — no increment

        $next = $this->service->next('invoice', $this->tenantUuid);
        $this->assertSame('INV-000001', $next);

        $peek3 = $this->service->peek('invoice', $this->tenantUuid);
        $this->assertSame('INV-000002', $peek3); // Now incremented
    }

    // ─── Current ─────────────────────────────────────────────────

    public function test_current_returns_counter(): void
    {
        $this->service->register('invoice', 'INV', 6, null, $this->tenantUuid);

        $this->assertSame(0, $this->service->current('invoice', $this->tenantUuid));

        $this->service->next('invoice', $this->tenantUuid);
        $this->assertSame(1, $this->service->current('invoice', $this->tenantUuid));

        $this->service->next('invoice', $this->tenantUuid);
        $this->assertSame(2, $this->service->current('invoice', $this->tenantUuid));
    }

    // ─── List ────────────────────────────────────────────────────

    public function test_list_sequences_returns_all(): void
    {
        $this->service->register('invoice', 'INV', 6, null, $this->tenantUuid);
        $this->service->register('receipt', 'RCP', 4, null, $this->tenantUuid);

        $list = $this->service->listSequences($this->tenantUuid);

        $this->assertCount(2, $list);
    }

    // ─── Reset ───────────────────────────────────────────────────

    public function test_reset_sets_counter_to_zero(): void
    {
        $this->service->register('invoice', 'INV', 6, null, $this->tenantUuid);

        $this->service->next('invoice', $this->tenantUuid);
        $this->service->next('invoice', $this->tenantUuid);

        $this->service->reset('invoice', $this->tenantUuid);

        $n = $this->service->next('invoice', $this->tenantUuid);
        $this->assertSame('INV-000001', $n);
    }

    // ─── Error ───────────────────────────────────────────────────

    public function test_next_without_sequence_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->next('nonexistent', $this->tenantUuid);
    }

    public function test_peek_without_sequence_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->peek('nonexistent', $this->tenantUuid);
    }
}
