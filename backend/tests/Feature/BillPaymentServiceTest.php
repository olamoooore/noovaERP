<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\BillPaymentService;
use Tests\TenantTestCase;

class BillPaymentServiceTest extends TenantTestCase
{

    private BillPaymentService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Bind a tenant context and seed Spatie roles + permissions so
        // PostingEngine::post() passes the MISSING_TENANT_CONTEXT and
        // INSUFFICIENT_PERMISSION gates introduced by the ACC-002 authority
        // work (issue #37). The Admin role carries accounting.write.
        $tenantId = \Illuminate\Support\Str::uuid()->toString();
        $this->user = User::factory()->create();
        $this->bindTenantContextWithPermissions($tenantId, $this->user);
        $this->service = app(BillPaymentService::class);
    }

    private function createJournalEntry(): JournalEntry
    {
        return JournalEntry::create([
            'entry_number' => 'JE-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'type' => 'payment',
            'description' => 'Test journal entry',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);
    }

    /** @test */
    public function it_records_payment_and_posts_to_gl(): void
    {
        $vendor = Vendor::factory()->create();
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'total' => 1000,
            'balance' => 1000,
            'status' => 'posted',
        ]);

        $payment = $this->service->recordPayment([
            'vendor_id' => $vendor->id,
            'bill_id' => $bill->id,
            'payment_date' => now()->format('Y-m-d'),
            'amount' => 500,
            'payment_method' => 'bank',
        ], $this->user->id);

        $this->assertInstanceOf(BillPayment::class, $payment);
        $this->assertNotNull($payment->journal_entry_id);
        $this->assertEquals(500, $payment->amount);

        // Bill should be updated
        $bill->refresh();
        $this->assertEquals(500, $bill->paid_amount);
        $this->assertEquals(500, $bill->balance);
        $this->assertEquals('partial', $bill->status);
    }

    /** @test */
    public function it_throws_exception_when_payment_exceeds_bill_balance(): void
    {
        $vendor = Vendor::factory()->create();
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'total' => 1000,
            'balance' => 100,
            'status' => 'posted',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Payment amount exceeds bill balance');

        $this->service->recordPayment([
            'vendor_id' => $vendor->id,
            'bill_id' => $bill->id,
            'payment_date' => now()->format('Y-m-d'),
            'amount' => 500,
            'payment_method' => 'bank',
        ], $this->user->id);
    }

    /** @test */
    public function it_throws_exception_when_bill_does_not_belong_to_vendor(): void
    {
        $vendor1 = Vendor::factory()->create();
        $vendor2 = Vendor::factory()->create();
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor1->id,
            'total' => 1000,
            'balance' => 1000,
            'status' => 'posted',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Bill does not belong to the specified vendor');

        $this->service->recordPayment([
            'vendor_id' => $vendor2->id,
            'bill_id' => $bill->id,
            'payment_date' => now()->format('Y-m-d'),
            'amount' => 500,
            'payment_method' => 'bank',
        ], $this->user->id);
    }

    /** @test */
    public function duplicate_retries_do_not_double_post(): void
    {
        $vendor = Vendor::factory()->create();
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'total' => 1000,
            'balance' => 1000,
            'status' => 'posted',
        ]);

        // Create first payment
        $payment1 = $this->service->recordPayment([
            'vendor_id' => $vendor->id,
            'bill_id' => $bill->id,
            'payment_date' => now()->format('Y-m-d'),
            'amount' => 500,
            'payment_method' => 'bank',
            'payment_number' => 'PAY-TEST-001',
        ], $this->user->id);

        // Check journal entry was created
        $this->assertNotNull($payment1->journal_entry_id);
        $journalEntryId = $payment1->journal_entry_id;

        // Attempt to create second payment with same payment number
        // This should fail due to unique constraint on payment_number
        try {
            $this->service->recordPayment([
                'vendor_id' => $vendor->id,
                'bill_id' => $bill->id,
                'payment_date' => now()->format('Y-m-d'),
                'amount' => 500,
                'payment_method' => 'bank',
                'payment_number' => 'PAY-TEST-001',
            ], $this->user->id);
            $this->fail('Expected exception for duplicate payment');
        } catch (\Exception $e) {
            // Expected - payment_number must be unique
            $this->assertStringContainsString('duplicate', strtolower($e->getMessage()));
        }

        // Verify only one journal entry exists
        $paymentCount = BillPayment::where('bill_id', $bill->id)->count();
        $this->assertEquals(1, $paymentCount);

        // Verify bill balance is correct (not double-updated)
        $bill->refresh();
        $this->assertEquals(500, $bill->paid_amount);
        $this->assertEquals(500, $bill->balance);
    }

    /** @test */
    public function it_updates_draft_payment(): void
    {
        $vendor = Vendor::factory()->create();
        $payment = BillPayment::factory()->create([
            'vendor_id' => $vendor->id,
            'amount' => 500,
            'payment_method' => 'cash',
            'journal_entry_id' => null, // Not posted yet
        ]);

        $updated = $this->service->updateDraftPayment($payment, [
            'amount' => 750,
            'payment_method' => 'bank',
        ]);

        $this->assertEquals(750, $updated->amount);
        $this->assertEquals('bank', $updated->payment_method);
    }

    /** @test */
    public function it_throws_exception_when_updating_posted_payment(): void
    {
        $vendor = Vendor::factory()->create();
        $journalEntry = $this->createJournalEntry();
        $payment = BillPayment::factory()->create([
            'vendor_id' => $vendor->id,
            'amount' => 500,
            'journal_entry_id' => $journalEntry->id, // Posted
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot update payment that has been posted to journal');

        $this->service->updateDraftPayment($payment, [
            'amount' => 750,
        ]);
    }

    /** @test */
    public function it_deletes_draft_payment(): void
    {
        $vendor = Vendor::factory()->create();
        $payment = BillPayment::factory()->create([
            'vendor_id' => $vendor->id,
            'journal_entry_id' => null, // Not posted yet
        ]);

        $this->service->deleteDraftPayment($payment);

        $this->assertNull(BillPayment::find($payment->id));
    }

    /** @test */
    public function it_throws_exception_when_deleting_posted_payment(): void
    {
        $vendor = Vendor::factory()->create();
        $journalEntry = $this->createJournalEntry();
        $payment = BillPayment::factory()->create([
            'vendor_id' => $vendor->id,
            'journal_entry_id' => $journalEntry->id, // Posted
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete payment that has been posted to journal');

        $this->service->deleteDraftPayment($payment);
    }

    /** @test */
    public function it_marks_bill_as_paid_when_full_payment_received(): void
    {
        $vendor = Vendor::factory()->create();
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'total' => 1000,
            'balance' => 1000,
            'paid_amount' => 0,
            'status' => 'posted',
        ]);

        $this->service->recordPayment([
            'vendor_id' => $vendor->id,
            'bill_id' => $bill->id,
            'payment_date' => now()->format('Y-m-d'),
            'amount' => 1000,
            'payment_method' => 'bank',
        ], $this->user->id);

        $bill->refresh();
        $this->assertEquals('paid', $bill->status);
        $this->assertEquals(0, $bill->balance);
    }
}
