<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\SettlementAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Tests\TenantTestCase;

/**
 * #483 (follows #477/#482): cancelling a DRAFT payment must not run the
 * posted-payment unapply/correction flow (which requires a journal entry and
 * builds the correction request with the central auth id). A draft is simply
 * removed through the deletion path — reversing any legacy allocation — and no
 * reversal journal entry is created. Actors recorded are tenant users.
 *
 * Before: POST /payments/{id}/cancel on a draft hit
 * PaymentCorrectionService::unapplyPayment, which threw "has not been posted"
 * (surfaced as 500) — and passed the central public.users id as the actor.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentDraftCancelTest extends TenantTestCase
{
    protected User $admin;

    protected Customer $customer;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);
    }

    private function openInvoice(float $total = 500): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'created_by' => $this->admin->id,
        ]);
    }

    private function recordDraft(Invoice $invoice, float $amount): Payment
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => 'cash',
            ])
            ->assertCreated();

        return Payment::findOrFail($response->json('id'));
    }

    public function test_cancelling_a_draft_removes_it_without_a_reversal_journal_entry(): void
    {
        $invoice = $this->openInvoice(500);
        $payment = $this->recordDraft($invoice, 200);

        $this->assertSame(0, JournalEntry::count(), 'A draft has no journal entry.');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/cancel")
            ->assertOk();

        $this->assertNull(Payment::find($payment->id), 'A cancelled draft is removed.');
        $this->assertSame(0, JournalEntry::count(), 'Cancelling a draft must not create a reversal journal entry.');

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->paid_amount, 'The invoice is untouched by a draft.');
        $this->assertSame(500.0, (float) $invoice->balance);
    }

    public function test_cancelling_a_legacy_draft_restores_invoices_and_records_the_tenant_actor(): void
    {
        $a = $this->openInvoice(500);
        $b = $this->openInvoice(500);
        $payment = $this->recordDraft($a, 300);

        // Legacy draft that had already settled two invoices (pre-#477).
        $invoiceService = app(InvoiceService::class);
        $invoiceService->applyAllocatedPayment($a, 100, $payment);
        $invoiceService->applyAllocatedPayment($b, 200, $payment);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/cancel")
            ->assertOk();

        $this->assertNull(Payment::find($payment->id));
        $this->assertSame(0.0, (float) $a->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $b->fresh()->paid_amount);

        $rows = SettlementAuditLog::where('payment_id', $payment->id)
            ->where('notes', 'Payment allocation reversed')
            ->get();
        $this->assertCount(2, $rows, 'Each reversed allocation must be audited.');

        $tenantUserId = TenantUserHelper::resolveTenantUserId();
        foreach ($rows as $row) {
            $this->assertNotNull($row->actor_user_id);
            $this->assertSame(
                (int) $tenantUserId,
                (int) $row->actor_user_id,
                'The recorded actor must be the tenant user id, not the central auth id.',
            );
        }
    }
}
