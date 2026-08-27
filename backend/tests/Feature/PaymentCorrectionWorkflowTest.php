<?php

/**
 * Payment correction workflow — end-to-end through the router.
 *
 * Verifies POST /api/v1/corrections/payments/{id}/refund|reverse|unapply:
 *  - reverse/refund create a negative correction payment + GL reversal
 *  - unapply restores the invoice balance and keeps the payment on account
 *  - repeated corrections are rejected (no double refunds/reversals)
 *  - drafts cannot be corrected (delete instead)
 *  - accounting.post permission is enforced on the routes
 */

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class PaymentCorrectionWorkflowTest extends TenantTestCase
{
    private string $tenantId;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContextWithPermissions($this->tenantId);

        // Central tenant record (FK target for user_company_access.tenant_id)
        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'Correction Test Tenant', 'email' => 'corrections-tenant@local', 'plan' => 'trial', 'created_at' => now(), 'updated_at' => now()],
        );

        $this->accountant = User::firstOrCreate(
            ['id' => 1],
            ['name' => 'Correction Accountant', 'email' => 'corrections@local', 'password' => bcrypt('password')],
        );
        $this->accountant->assignRole('Admin');

        // Central identity + role bridge used by RequirePermission/TenantRoleService.
        DB::table('public.users')->updateOrInsert(
            ['email' => 'corrections@local'],
            ['name' => 'Correction Accountant', 'password' => bcrypt('password'), 'created_at' => now(), 'updated_at' => now()],
        );
        $centralUserId = DB::table('public.users')->where('email', 'corrections@local')->value('id');
        DB::table('public.user_company_access')->updateOrInsert(
            ['user_id' => $centralUserId, 'tenant_id' => $this->tenantId],
            ['role' => 'accountant', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** Create a posted invoice with a posted payment (GL-backed). */
    private function createPostedPayment(float $amount = 400.00): array
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'total' => $amount,
            'paid_amount' => 0,
            'balance' => $amount,
            'created_by' => $this->accountant->id,
        ]);

        $arAccount = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);
        $revenueAccount = ChartOfAccount::factory()->create(['type' => 'revenue', 'is_active' => true]);

        $invoiceJe = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: $invoice->id,
            postingDate: now()->toDateString(),
            description: "Invoice #{$invoice->invoice_number}",
            lines: [
                new PostingRequestLine('accounts_receivable', $arAccount->id, $amount, 0.00, 'AR'),
                new PostingRequestLine('sales_revenue', $revenueAccount->id, 0.00, $amount, 'Revenue'),
            ],
            userId: $this->accountant->id,
        ));
        $this->assertTrue($invoiceJe->success, "Invoice posting failed: {$invoiceJe->error}");
        $invoice->update(['journal_entry_id' => $invoiceJe->journalEntry->id]);

        $cashAccount = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);
        $paymentJe = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'payment',
            documentId: 90000 + (int) (microtime(true) * 1000) % 1000,
            postingDate: now()->toDateString(),
            description: 'Payment receipt',
            lines: [
                new PostingRequestLine('cash', $cashAccount->id, $amount, 0.00, 'Cash'),
                new PostingRequestLine('accounts_receivable', $arAccount->id, 0.00, $amount, 'AR'),
            ],
            userId: $this->accountant->id,
        ));
        $this->assertTrue($paymentJe->success, "Payment posting failed: {$paymentJe->error}");

        $payment = Payment::create([
            'payment_number' => 'PAY-TEST-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'method' => 'bank_transfer',
            'payment_status' => 'applied',
            'journal_entry_id' => $paymentJe->journalEntry->id,
            'received_by' => $this->accountant->id,
        ]);

        $invoice->update(['paid_amount' => $amount, 'balance' => 0]);

        return [$payment, $invoice];
    }

    private function correctionPayload(): array
    {
        return [
            'reason_code' => 'DATA_ENTRY_ERROR',
            'reason_text' => 'E2E workflow verification',
        ];
    }

    // ── Tests ──────────────────────────────────────────────────────────────

    /** @test */
    public function reverse_creates_negative_correction_payment_and_gl_reversal(): void
    {
        [$payment, $invoice] = $this->createPostedPayment();

        $response = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/reverse", $this->correctionPayload());

        $response->assertOk();

        // Original payment marked reversed and linked to its correction record
        $payment->refresh();
        $this->assertSame('reversed', $payment->payment_status);
        $this->assertNotNull($payment->refund_payment_id);

        // Negative correction payment exists
        $reversalPayment = Payment::find($payment->refund_payment_id);
        $this->assertNotNull($reversalPayment);
        $this->assertEqualsWithDelta(-$payment->amount, (float) $reversalPayment->amount, 0.01);
        $this->assertSame('reverse', $reversalPayment->correction_type);
        $this->assertSame('DATA_ENTRY_ERROR', $reversalPayment->correction_reason_code);
        $this->assertSame($payment->id, $reversalPayment->original_payment_id);

        // GL reversal posted for the payment journal entry
        $this->assertNotNull($reversalPayment->reversal_journal_entry_id);
        $reversalJe = JournalEntry::find($reversalPayment->reversal_journal_entry_id);
        $this->assertTrue((bool) $reversalJe->is_reversal);
        $this->assertSame($payment->journal_entry_id, $reversalJe->reversed_entry_id);

        // Invoice balance restored
        $invoice->refresh();
        $this->assertEqualsWithDelta($payment->amount, (float) $invoice->balance, 0.01);
    }

    /** @test */
    public function refund_creates_refund_record_and_restores_invoice_balance(): void
    {
        [$payment, $invoice] = $this->createPostedPayment();

        $response = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/refund", $this->correctionPayload());

        $response->assertOk();

        $payment->refresh();
        $this->assertSame('refunded', $payment->payment_status);
        $this->assertNotNull($payment->refund_payment_id);

        $refundPayment = Payment::find($payment->refund_payment_id);
        $this->assertSame('refund', $refundPayment->correction_type);
        $this->assertEqualsWithDelta(-$payment->amount, (float) $refundPayment->amount, 0.01);
        $this->assertNotNull($refundPayment->refunded_at);

        $invoice->refresh();
        $this->assertEqualsWithDelta($payment->amount, (float) $invoice->balance, 0.01);
    }

    /** @test */
    public function unapply_detaches_payment_from_invoice_and_keeps_it_on_account(): void
    {
        [$payment, $invoice] = $this->createPostedPayment();

        $response = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/unapply", $this->correctionPayload());

        $response->assertOk();

        $payment->refresh();
        $this->assertSame('unapplied', $payment->payment_status);
        $this->assertSame('unapply_payment', $payment->correction_type);
        $this->assertNotNull($payment->unapplied_at);
        $this->assertNull($payment->refund_payment_id, 'Unapply must not create a correction payment row');

        $invoice->refresh();
        $this->assertEqualsWithDelta($payment->amount, (float) $invoice->balance, 0.01);
    }

    /** @test */
    public function already_reversed_payment_cannot_be_corrected_again(): void
    {
        [$payment] = $this->createPostedPayment();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/reverse", $this->correctionPayload())
            ->assertOk();

        // Second reversal must be rejected — no double reversals
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/reverse", $this->correctionPayload())
            ->assertStatus(422);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/refund", $this->correctionPayload())
            ->assertStatus(422);

        // Exactly one correction payment exists
        $this->assertSame(1, Payment::where('original_payment_id', $payment->id)->count());
    }

    /** @test */
    public function already_refunded_payment_cannot_be_refunded_again(): void
    {
        [$payment] = $this->createPostedPayment();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/refund", $this->correctionPayload())
            ->assertOk();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/refund", $this->correctionPayload())
            ->assertStatus(422);

        $this->assertSame(1, Payment::where('original_payment_id', $payment->id)->count());
    }

    /** @test */
    public function draft_payment_cannot_be_corrected(): void
    {
        $customer = Customer::factory()->create();
        $draft = Payment::create([
            'payment_number' => 'PAY-DRAFT-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'method' => 'cash',
            'payment_status' => 'draft',
            'received_by' => $this->accountant->id,
        ]);

        foreach (['reverse', 'refund', 'unapply'] as $action) {
            $this->actingAs($this->accountant, 'sanctum')
                ->postJson("/api/v1/corrections/payments/{$draft->id}/{$action}", $this->correctionPayload())
                ->assertStatus(422);
        }
    }

    /** @test */
    public function correction_requires_reason_code(): void
    {
        [$payment] = $this->createPostedPayment();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/reverse", ['reason_text' => 'no code'])
            ->assertStatus(422);
    }

    /** @test */
    public function user_without_accounting_post_permission_is_denied(): void
    {
        [$payment] = $this->createPostedPayment();

        $salesUser = User::firstOrCreate(
            ['id' => 2],
            ['name' => 'Sales Rep', 'email' => 'sales-only@local', 'password' => bcrypt('password')],
        );

        DB::table('public.users')->updateOrInsert(
            ['email' => 'sales-only@local'],
            ['name' => 'Sales Rep', 'password' => bcrypt('password'), 'created_at' => now(), 'updated_at' => now()],
        );
        $centralUserId = DB::table('public.users')->where('email', 'sales-only@local')->value('id');
        DB::table('public.user_company_access')->updateOrInsert(
            ['user_id' => $centralUserId, 'tenant_id' => $this->tenantId],
            ['role' => 'sales', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
        );

        $response = $this->actingAs($salesUser, 'sanctum')
            ->postJson("/api/v1/corrections/payments/{$payment->id}/reverse", $this->correctionPayload());

        $response->assertStatus(403);
        $this->assertSame('accounting.post', $response->json('required_permission'));
    }
}
