<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Events\BillPosted;
use App\Accounting\Events\InvoicePosted;
use App\Accounting\Events\JournalEntryPosted;
use App\Accounting\Events\PaymentRecorded;
use App\Accounting\Events\ReconciliationCompleted;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\Event;
use Tests\TenantTestCase;

class AccountingDomainEventTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        Event::fake();
    }

    public function test_bill_posted_event_dispatched(): void
    {
        $bill = new Bill();
        $bill->id = 1;
        $bill->bill_number = 'BILL-001';
        $bill->total_amount = 1500.00;

        event(new BillPosted($bill));

        Event::assertDispatched(BillPosted::class, function ($event) use ($bill) {
            return $event->bill->id === $bill->id;
        });
    }

    public function test_invoice_posted_event_dispatched(): void
    {
        $invoice = new Invoice();
        $invoice->id = 1;
        $invoice->invoice_number = 'INV-001';
        $invoice->total_amount = 5000.00;

        event(new InvoicePosted($invoice));

        Event::assertDispatched(InvoicePosted::class, function ($event) use ($invoice) {
            return $event->invoice->id === $invoice->id;
        });
    }

    public function test_journal_entry_posted_event_dispatched(): void
    {
        $entry = new JournalEntry();
        $entry->id = 1;
        $entry->entry_number = 'JE-001';

        event(new JournalEntryPosted($entry));

        Event::assertDispatched(JournalEntryPosted::class, function ($event) use ($entry) {
            return $event->journalEntry->id === $entry->id;
        });
    }

    public function test_payment_recorded_event_dispatched(): void
    {
        $payment = new Payment();
        $payment->id = 1;
        $payment->payment_number = 'PAY-001';
        $payment->amount = 2500.00;

        event(new PaymentRecorded($payment));

        Event::assertDispatched(PaymentRecorded::class, function ($event) use ($payment) {
            return $event->payment->id === $payment->id;
        });
    }

    public function test_reconciliation_completed_event_dispatched(): void
    {
        $reconciliation = new \App\Models\BankReconciliation();
        $reconciliation->id = 1;

        event(new ReconciliationCompleted($reconciliation));

        Event::assertDispatched(ReconciliationCompleted::class, function ($event) use ($reconciliation) {
            return $event->reconciliation->id === $reconciliation->id;
        });
    }
}
