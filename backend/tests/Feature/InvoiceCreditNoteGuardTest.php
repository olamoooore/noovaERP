<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #169 — cancel()/resetToDraft() blocked on payments but not on posted
 * credit notes, leaving the credit note's own journal entry referencing an
 * invoice with no posted JE of its own.
 */
class InvoiceCreditNoteGuardTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '99999999-8888-7777-6666-555555555555',
        ]));
        $this->bindTenantContext($tenant->id);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
    }

    private function postedInvoice(array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1000,
            'paid_amount' => 0,
            'balance' => 1000,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    private function creditNote(Invoice $invoice, string $status = 'posted'): CustomerCreditNote
    {
        return CustomerCreditNote::create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => $status,
            'subtotal' => 300,
            'tax_amount' => 0,
            'total_amount' => 300,
            'reason' => 'Guard test',
        ]);
    }

    public function test_cancel_is_blocked_by_a_posted_credit_note(): void
    {
        $invoice = $this->postedInvoice();
        $this->creditNote($invoice);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel');

        $response->assertStatus(422);
        $this->assertStringContainsString('credit note', $response->json('message'));
        $this->assertSame('posted', $invoice->fresh()->status, 'The invoice must remain posted.');
    }

    public function test_reset_to_draft_is_blocked_by_a_posted_credit_note(): void
    {
        $invoice = $this->postedInvoice(['status' => 'cancelled']);
        $this->creditNote($invoice);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->id.'/reset-to-draft');

        $response->assertStatus(422);
        $this->assertStringContainsString('credit note', $response->json('message'));
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_draft_credit_notes_do_not_block(): void
    {
        $invoice = $this->postedInvoice();
        $this->creditNote($invoice, 'draft');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel');

        $response->assertOk();
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_voiding_the_credit_note_unblocks_cancel_and_reset(): void
    {
        $invoice = $this->postedInvoice();
        $creditNote = $this->creditNote($invoice);

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel')
            ->assertStatus(422);

        $creditNote->update(['status' => 'voided']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel')
            ->assertOk();

        $this->assertSame('cancelled', $invoice->fresh()->status);

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->id.'/reset-to-draft')
            ->assertOk();

        $this->assertSame('draft', $invoice->fresh()->status);
    }
}
