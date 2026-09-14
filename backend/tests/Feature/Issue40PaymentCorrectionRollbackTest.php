<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use App\Services\CorrectionRequest;
use App\Services\PaymentCorrectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #40 — payment correction rolls back atomically
 * when GL reversal fails.
 *
 * Replaces source-text-only checks with real DB state assertions before/after
 * a forced GL reversal failure.
 */
class Issue40PaymentCorrectionRollbackTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        // Ensure FK targets exist in central tables.
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Test Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Role::firstOrCreate(['name' => 'admin']);
    }

    private function createPostedPayment(): Payment
    {
        $user = User::factory()->create();

        // Ensure a Customer row exists (payments.customer_id is non-null).
        $customer = \App\Models\Customer::firstOrCreate(
            ['name' => 'Test Customer '.uniqid()],
            ['name' => 'Test Customer '.uniqid()]
        );

        // Create a real JournalEntry so the FK to journal_entries is satisfied.
        // Bypass TenantUserHelper by inserting via DB so the test works without
        // a fully-initialised tenant user.
        DB::table('journal_entries')->insert([
            'entry_number' => 'JE-TEST-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'description' => 'Test journal',
            'status' => 'posted',
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journalId = (int) DB::getPdo()->lastInsertId();
        $journal = \App\Models\JournalEntry::find($journalId);

        $payment = Payment::create([
            'payment_number' => 'PAY-TEST-'.uniqid(),
            'customer_id' => $customer->id,
            'amount' => 200.00,
            'currency' => 'USD',
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'payment_status' => 'applied',
            'journal_entry_id' => $journal->id,
            'received_by' => $user->id,
        ]);

        return $payment;
    }

    private function correctionRequest(int $userId): CorrectionRequest
    {
        return new CorrectionRequest(
            reasonCode: 'REVERSAL_TEST',
            reasonText: 'Test reason',
            userId: $userId,
        );
    }

    public function test_reverse_payment_rolls_back_on_gl_reversal_failure(): void
    {
        $payment = $this->createPostedPayment();
        $preImage = $payment->fresh()->getAttributes();
        $paymentsBefore = Payment::count();

        // Force PostingEngine::reverse() to throw a non-idempotency failure.
        // We bind a stub into the container so the call returns success=false.
        $stub = new class
        {
            public function reverse($request): \App\Accounting\DTOs\PostingResult
            {
                return \App\Accounting\DTOs\PostingResult::failure('Simulated GL reversal failure');
            }
        };
        app()->instance(\App\Accounting\PostingEngine::class, $stub);

        $threw = false;
        try {
            app(PaymentCorrectionService::class)->reversePayment(
                $payment->fresh(),
                $this->correctionRequest(User::factory()->create()->id)
            );
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('GL reversal failed', $e->getMessage());
        }

        $this->assertTrue($threw, 'Expected RuntimeException from GL reversal failure');

        // No new Payment rows were created
        $this->assertSame($paymentsBefore, Payment::count());

        // The original payment is unchanged
        $payment->refresh();
        $this->assertSame($preImage['payment_status'], $payment->payment_status);
        $this->assertNull($payment->refund_payment_id);
    }

    public function test_refund_payment_rolls_back_on_gl_reversal_failure(): void
    {
        $payment = $this->createPostedPayment();
        $paymentsBefore = Payment::count();

        $stub = new class
        {
            public function reverse($request): \App\Accounting\DTOs\PostingResult
            {
                return \App\Accounting\DTOs\PostingResult::failure('Simulated GL reversal failure');
            }
        };
        app()->instance(\App\Accounting\PostingEngine::class, $stub);

        $threw = false;
        try {
            app(PaymentCorrectionService::class)->refundPayment(
                $payment->fresh(),
                $this->correctionRequest(User::factory()->create()->id)
            );
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected RuntimeException from GL reversal failure');
        $this->assertSame($paymentsBefore, Payment::count());

        $payment->refresh();
        $this->assertSame('applied', $payment->payment_status);
        $this->assertNull($payment->refund_payment_id);
    }

    public function test_unapply_payment_rolls_back_on_gl_reversal_failure(): void
    {
        $payment = $this->createPostedPayment();

        $stub = new class
        {
            public function reverse($request): \App\Accounting\DTOs\PostingResult
            {
                return \App\Accounting\DTOs\PostingResult::failure('Simulated GL reversal failure');
            }
        };
        app()->instance(\App\Accounting\PostingEngine::class, $stub);

        $threw = false;
        try {
            app(PaymentCorrectionService::class)->unapplyPayment(
                $payment->fresh(),
                $this->correctionRequest(User::factory()->create()->id)
            );
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected RuntimeException from GL reversal failure');

        // Payment status unchanged
        $payment->refresh();
        $this->assertSame('applied', $payment->payment_status);
    }
}
