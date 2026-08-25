<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Services\CorrectionRequest;
use App\Services\PaymentCorrectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #45 — Standalone payment correction is supported
 * without throwing on a null parent document.
 *
 * Prior to this fix, removing the unconditional rejection guard caused
 * reverse/refund to call linkRefund(null, …) which threw an
 * InvalidArgumentException. The fix is to skip the link when no parent
 * document exists.
 */
class Issue45StandalonePaymentCorrectionTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Test Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Role::firstOrCreate(['name' => 'admin']);
    }

    private function createStandalonePayment(): Payment
    {
        $customer = Customer::firstOrCreate(
            ['name' => 'Test Customer '.uniqid()],
            ['name' => 'Test Customer '.uniqid()]
        );
        $user = User::factory()->create();

        DB::table('journal_entries')->insert([
            'entry_number' => 'JE-STD-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'description' => 'Standalone test journal',
            'status' => 'posted',
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journalId = (int) DB::getPdo()->lastInsertId();

        return Payment::create([
            'payment_number' => 'PAY-STD-'.uniqid(),
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'currency' => 'USD',
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'payment_status' => 'posted',
            'journal_entry_id' => $journalId,
            'received_by' => $user->id,
        ]);
    }

    private function stubPostingEngine(): void
    {
        // Create a real JournalEntry row so the PostingResult success payload
        // can carry it. This is the minimal valid success path.
        $user = User::factory()->create();
        $journalId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-STUB-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'description' => 'Stub reversal journal',
            'status' => 'posted',
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stub = new class($journalId)
        {
            public function __construct(private int $journalId) {}

            public function reverse($request): \App\Accounting\DTOs\PostingResult
            {
                return \App\Accounting\DTOs\PostingResult::success(
                    \App\Models\JournalEntry::find($this->journalId),
                    null
                );
            }
        };
        app()->instance(\App\Accounting\PostingEngine::class, $stub);
    }

    private function correctionRequest(int $userId): CorrectionRequest
    {
        return new CorrectionRequest(
            reasonCode: 'REVERSAL_TEST',
            reasonText: 'Standalone payment correction',
            userId: $userId,
        );
    }

    public function test_standalone_reverse_payment_succeeds_without_invoice_or_bill(): void
    {
        $payment = $this->createStandalonePayment();
        $this->stubPostingEngine();

        $result = app(PaymentCorrectionService::class)->reversePayment(
            $payment->fresh(),
            $this->correctionRequest(User::factory()->create()->id)
        );

        $this->assertSame('reversed', $result->payment->payment_status);
        // No link was created because there is no parent document
        $this->assertSame(0, DB::table('document_correction_links')
            ->where('related_document_id', $result->payment->id)
            ->count());
    }

    public function test_standalone_refund_payment_succeeds_without_invoice_or_bill(): void
    {
        $payment = $this->createStandalonePayment();
        $this->stubPostingEngine();

        $result = app(PaymentCorrectionService::class)->refundPayment(
            $payment->fresh(),
            $this->correctionRequest(User::factory()->create()->id)
        );

        $this->assertSame('refunded', $result->payment->payment_status);
        $this->assertSame(0, DB::table('document_correction_links')
            ->where('related_document_id', $result->payment->id)
            ->count());
    }

    public function test_standalone_unapply_payment_succeeds_without_invoice_or_bill(): void
    {
        $payment = $this->createStandalonePayment();
        $this->stubPostingEngine();

        $result = app(PaymentCorrectionService::class)->unapplyPayment(
            $payment->fresh(),
            $this->correctionRequest(User::factory()->create()->id)
        );

        $this->assertSame('unapplied', $result->payment->payment_status);
    }
}
