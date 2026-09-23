<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use App\Models\Invoice;
use App\Models\Payment;
use Tests\Feature\Issue155PaymentAllocationTest;

/**
 * Hostile review repro (#155): allocation must refuse payments that carry no
 * cash (reversed/refunded) and invoices that are not open posted receivables.
 */
class HRV155AllocationGuardsTest extends Issue155PaymentAllocationTest
{
    private function ctx(string $prop)
    {
        return \Closure::bind(fn () => $this->{$prop}, $this, Issue155PaymentAllocationTest::class)();
    }

    private function mk(string $method, ...$args)
    {
        return \Closure::bind(fn () => $this->{$method}(...$args), $this, Issue155PaymentAllocationTest::class)();
    }

    public function test_reversed_payment_cannot_settle_an_invoice(): void
    {
        $invoice = $this->mk('invoice', 500.0);
        $payment = $this->mk('payment', 500.0);
        Payment::whereKey($payment->id)->update(['payment_status' => 'reversed']);

        $res = $this->actingAs($this->ctx('user'))->postJson("/api/v1/payments/{$payment->id}/allocations", [
            'applications' => [['invoice_id' => $invoice->id, 'amount' => 500]],
        ]);

        $invoice->refresh();
        fwrite(STDERR, sprintf("\n[HRV155-reversed] http=%d invoice.status=%s balance=%.2f\n", $res->status(), $invoice->status, $invoice->balance));
        $this->assertSame(422, $res->status(), 'a reversed payment carries no cash and must not settle AR');
    }

    public function test_draft_invoice_cannot_be_settled_by_allocation(): void
    {
        $invoice = $this->mk('invoice', 300.0);
        Invoice::whereKey($invoice->id)->update(['status' => 'draft', 'lifecycle_status' => 'draft']);
        $payment = $this->mk('payment', 300.0);

        $res = $this->actingAs($this->ctx('user'))->postJson("/api/v1/payments/{$payment->id}/allocations", [
            'applications' => [['invoice_id' => $invoice->id, 'amount' => 300]],
        ]);

        $invoice->refresh();
        fwrite(STDERR, sprintf("\n[HRV155-draft] http=%d invoice.status=%s balance=%.2f\n", $res->status(), $invoice->status, $invoice->balance));
        $this->assertSame(422, $res->status(), 'an unposted draft invoice has no AR to settle');
    }
}
