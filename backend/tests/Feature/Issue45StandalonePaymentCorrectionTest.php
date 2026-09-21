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
            'payment_status' => 'applied',
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

    /**
     * AC1 (true end-to-end): a standalone payment posted through the REAL
     * PaymentPostingService (AR customer-credit GL) can be reversed through
     * the REAL PaymentCorrectionService, and the original + reversal journal
     * entries net to zero (balanced pair). No parent document required.
     */
    public function test_standalone_payment_posts_and_reverses_end_to_end(): void
    {
        // Permission-gate needs accounting.write on the posting user (issue #37).
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.write');

        $customer = Customer::firstOrCreate(
            ['name' => 'E2E Standalone Customer '.uniqid()],
            ['name' => 'E2E Standalone Customer '.uniqid()]
        );

        $payment = Payment::create([
            'payment_number' => 'PAY-E2E-'.uniqid(),
            'customer_id' => $customer->id,
            'amount' => 250.00,
            'currency' => 'USD',
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'payment_status' => 'recorded',
            'received_by' => $user->id,
        ]);

        // 1. Post standalone payment through the real posting service.
        $posted = app(\App\Services\PaymentPostingService::class)->post($payment, $user->id);
        $this->assertSame('applied', $posted->payment_status);
        $originalJeId = $posted->journal_entry_id;
        $this->assertNotNull($originalJeId, 'Standalone payment must post to the GL');

        // 2. Reverse it through the real correction service (no stub engine).
        $result = app(PaymentCorrectionService::class)->reversePayment(
            $posted->fresh(),
            $this->correctionRequest($user->id)
        );
        $this->assertSame('reversed', $result->payment->payment_status);

        // 3. A distinct reversal journal entry exists (stored on the newly
        //    created reversal payment's reversal_journal_entry_id).
        $reversalJeId = DB::table('payments')
            ->where('id', $result->payment->id)
            ->value('reversal_journal_entry_id');
        $this->assertNotNull($reversalJeId);
        $this->assertNotSame((int) $originalJeId, (int) $reversalJeId,
            'Reversal must produce its own journal entry');

        // 4. The original + reversal JEs net to zero on every account
        //    (the standalone AR credit is fully reversed).
        $perAccount = DB::table('journal_entry_lines')
            ->selectRaw('account_id, SUM(debit) AS d, SUM(credit) AS c')
            ->whereIn('journal_entry_id', [$originalJeId, $reversalJeId])
            ->groupBy('account_id')
            ->get();

        $this->assertNotEmpty($perAccount);
        foreach ($perAccount as $row) {
            $this->assertEqualsWithDelta((float) $row->d, (float) $row->c, 0.01,
                "Account {$row->account_id} must net to zero after standalone reversal");
        }
    }

    /**
     * AC3 (shared contract): both supported payment shapes are correctable,
     * and non-posted/already-corrected shapes remain rejected. Documents the
     * PaymentPostingService ↔ PaymentCorrectionService contract so a future
     * change to one service cannot silently break the other again.
     */
    public function test_correction_shape_contract_is_enforced(): void
    {
        // (a) A standalone (no invoice/bill) posted payment IS correctable.
        $standalone = $this->createStandalonePayment();
        $this->stubPostingEngine();
        $reverseResult = app(PaymentCorrectionService::class)->reversePayment(
            $standalone->fresh(),
            $this->correctionRequest(User::factory()->create()->id)
        );
        $this->assertSame('reversed', $reverseResult->payment->payment_status);

        // (b) An unposted payment is NOT correctable (must be deleted instead).
        $customer = Customer::firstOrCreate(
            ['name' => 'Contract Draft Customer '.uniqid()],
            ['name' => 'Contract Draft Customer '.uniqid()]
        );
        $draft = Payment::create([
            'payment_number' => 'PAY-DRAFT-'.uniqid(),
            'customer_id' => $customer->id,
            'amount' => 10.00,
            'currency' => 'USD',
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'payment_status' => 'recorded',
            'received_by' => User::factory()->create()->id,
        ]);

        $threw = false;
        try {
            app(PaymentCorrectionService::class)->reversePayment(
                $draft,
                $this->correctionRequest(User::factory()->create()->id)
            );
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('not been posted', strtolower($e->getMessage()));
        }
        $this->assertTrue($threw, 'Unposted payment must be rejected for correction (assertCorrectable)');
    }
}
