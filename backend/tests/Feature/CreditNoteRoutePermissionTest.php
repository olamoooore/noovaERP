<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #515 (CN6) — the credit note routes sat in the accounting group with no
 * per-route authority: `post` had no permission at all, `void` asked only for
 * `customers.write`, and every GET was reachable by anyone who passed the group
 * middleware. A MANAGER (accounting.write, but not accounting.post) could
 * therefore post and void credit notes — reversing ledger entries and reducing
 * revenue — while being unable to post the invoices they credit.
 *
 * Contract asserted here, through the real router/middleware/controller chain:
 *  - create / update / delete require accounting.write;
 *  - post and void require accounting.post, the same gate invoice posting uses;
 *  - reads require accounting.view.
 */
class CreditNoteRoutePermissionTest extends TenantTestCase
{
    private User $manager;

    private User $accountant;

    private User $viewer;

    private Customer $customer;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->manager = $this->makeUser('manager', UserRole::MANAGER);
        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);
        $this->viewer = $this->makeUser('viewer', UserRole::VIEWER);
        $this->customer = Customer::factory()->create();

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN6', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN6', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN6', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function invoice(float $total = 500.0): array
    {
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->accountant->id,
        ]);

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit_price' => $total,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        return [$invoice->fresh(), $line];
    }

    private function createCreditNoteAs(User $actor, ?Invoice $invoice = null, ?InvoiceLine $line = null): CustomerCreditNote
    {
        if (! $invoice || ! $line) {
            [$invoice, $line] = $this->invoice();
        }

        $response = $this->actingAs($actor)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'CN6 authority',
            'lines' => [[
                'invoice_line_id' => $line->id,
                'description' => $line->description,
                'quantity' => 1,
                'unit_price' => $line->unit_price,
                'tax_rate' => 0,
            ]],
        ]);

        $response->assertCreated();

        return CustomerCreditNote::findOrFail($response->json('data.id'));
    }

    public function test_manager_can_create_a_draft_credit_note(): void
    {
        [$invoice, $line] = $this->invoice();

        $creditNote = $this->createCreditNoteAs($this->manager, $invoice, $line);

        $this->assertSame('draft', $creditNote->status, 'accounting.write must still allow drafting a credit note');
    }

    public function test_manager_cannot_post_a_credit_note(): void
    {
        $creditNote = $this->createCreditNoteAs($this->manager);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
            ->assertForbidden();

        $creditNote = $creditNote->fresh();
        $this->assertSame('draft', $creditNote->status, 'a forbidden post must leave the credit note a draft');
        $this->assertNull($creditNote->journal_entry_id, 'a manager must never reach the ledger through a credit note');
    }

    public function test_manager_cannot_void_a_credit_note(): void
    {
        $creditNote = $this->createCreditNoteAs($this->manager);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
            ->assertOk();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/void")
            ->assertForbidden();

        $this->assertSame('posted', $creditNote->fresh()->status, 'a forbidden void must not reverse the credit note');
    }

    public function test_accountant_can_post_and_void_a_credit_note(): void
    {
        $creditNote = $this->createCreditNoteAs($this->accountant);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
            ->assertOk();

        $posted = $creditNote->fresh();
        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->journal_entry_id, 'accounting.post must reach the ledger');

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/void")
            ->assertOk();

        $this->assertSame('voided', $creditNote->fresh()->status);
    }

    public function test_viewer_can_read_credit_notes_but_cannot_create_one(): void
    {
        $creditNote = $this->createCreditNoteAs($this->accountant);

        $this->actingAs($this->viewer)
            ->getJson('/api/v1/accounting/customers/credit-notes')
            ->assertOk();

        $this->actingAs($this->viewer)
            ->getJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}")
            ->assertOk();

        [$invoice, $line] = $this->invoice();

        $this->actingAs($this->viewer)
            ->postJson('/api/v1/accounting/customers/credit-notes', [
                'customer_id' => $this->customer->id,
                'invoice_id' => $invoice->id,
                'credit_note_date' => now()->toDateString(),
                'reason' => 'viewer must not create',
                'lines' => [[
                    'invoice_line_id' => $line->id,
                    'description' => $line->description,
                    'quantity' => 1,
                    'unit_price' => $line->unit_price,
                    'tax_rate' => 0,
                ]],
            ])
            ->assertForbidden();

        $this->assertSame(0, CustomerCreditNote::where('invoice_id', $invoice->id)->count(), 'the rejected viewer request must not create a credit note');
    }

    public function test_manager_cannot_post_an_invoice_either(): void
    {
        // The reason the credit note routes were wrong: a manager could post a
        // credit note while being blocked from posting the invoice it credits.
        [$invoice] = $this->invoice();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertForbidden();
    }
}
