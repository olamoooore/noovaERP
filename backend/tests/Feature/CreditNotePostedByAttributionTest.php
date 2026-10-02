<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * Issue #517 (CN8) — the credit note recorded the CREATOR as the user who
 * posted it: postCreditNote() passed $creditNote->created_by as the audit
 * actor for both posted_by and the journal entry. If a clerk drafts the credit
 * note and an accountant posts it, the ledger and the record said the clerk
 * posted it, falsifying the audit trail and making a future "creator cannot
 * post" separation-of-duties rule impossible to enforce.
 *
 * Contract asserted here: the acting user is the poster.
 *  - created_by stays the creator;
 *  - posted_by is the user who ran the post;
 *  - the journal entry's user is the poster.
 */
class CreditNotePostedByAttributionTest extends TenantTestCase
{
    private string $tenantId;

    private User $creator;

    private User $poster;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->assertTenantSchemaReady([
            'invoices',
            'invoice_lines',
            'customers',
            'customer_credit_notes',
            'customer_credit_note_lines',
            'chart_of_accounts',
            'account_mappings',
            'journal_entries',
        ]);

        $this->creator = $this->makeUser('clerk-creator');
        $this->poster = $this->makeUser('accountant-poster');

        $this->customer = Customer::factory()->create();

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN8', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN8', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN8', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function makeUser(string $label): User
    {
        $user = User::factory()->create();
        $user->assignRole(\App\Enums\UserRole::ACCOUNTANT->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * Tenant-user id by the row's own email — NOT TenantUserHelper::resolveTenantUserId(),
     * which always prefers the currently authenticated user and would return
     * the same id for both fixture users.
     */
    private function tenantUserIdOf(User $user): int
    {
        return (int) DB::table('users')->where('id', $user->getKey())->value('id');
    }

    private function draftInvoice(): array
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
            'created_by' => $this->creator->id,
        ]);

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);
        $invoice->calculateTotals();

        return [$invoice, $line];
    }

    private function draftCreditNoteAs(User $actor, Invoice $invoice, InvoiceLine $line): CustomerCreditNote
    {
        $create = $this->actingAs($actor)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'CN8 attribution',
            'lines' => [[
                'invoice_line_id' => $line->id,
                'description' => $line->description,
                'quantity' => 1,
                'unit_price' => $line->unit_price,
                'tax_rate' => 0,
            ]],
        ]);
        $create->assertCreated();

        return CustomerCreditNote::findOrFail($create->json('data.id'));
    }

    private function assertPostedBy(CustomerCreditNote $creditNote, int $posterId): void
    {
        $creditNote = $creditNote->fresh();

        $this->assertSame($this->tenantUserIdOf($this->creator), (int) $creditNote->created_by, 'the creator must stay the creator');
        $this->assertSame($posterId, (int) $creditNote->posted_by, 'the poster must be the user who ran the post');

        $entry = DB::table('journal_entries')->where('id', $creditNote->journal_entry_id)->first();
        $this->assertNotNull($entry, 'posting must create a journal entry');
        $this->assertSame($posterId, (int) $entry->created_by, 'the journal entry must be attributed to the poster');
        $this->assertSame($posterId, (int) $entry->posted_by, 'the journal entry posted_by must be the poster');
    }

    public function test_the_user_who_posts_is_recorded_not_the_creator(): void
    {
        [$invoice, $line] = $this->draftInvoice();

        $creditNote = $this->draftCreditNoteAs($this->creator, $invoice, $line);

        $this->actingAs($this->poster)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
            ->assertOk();

        $this->assertPostedBy($creditNote, $this->tenantUserIdOf($this->poster));
    }

    /**
     * The production case the code-reading review flagged: the acting user's
     * identity is not resolvable by email in the tenant user table (stale or
     * unsynced row). Passing the CREATOR to the resolver then silently
     * attributes the post to the creator; the poster must still be recorded.
     */
    public function test_the_poster_is_recorded_when_their_email_cannot_be_resolved_in_the_tenant(): void
    {
        [$invoice, $line] = $this->draftInvoice();

        $creditNote = $this->draftCreditNoteAs($this->creator, $invoice, $line);

        // The authenticated identity carries an email that has no matching
        // tenant user row, while the poster's actual user row still exists.
        $posterWithUnmatchedEmail = $this->poster->replicate();
        $posterWithUnmatchedEmail->id = $this->poster->id;
        $posterWithUnmatchedEmail->email = 'central-only-'.$this->poster->id.'@example.test';

        $this->actingAs($posterWithUnmatchedEmail)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
            ->assertOk();

        $this->assertPostedBy($creditNote, $this->tenantUserIdOf($this->poster));
    }
}
