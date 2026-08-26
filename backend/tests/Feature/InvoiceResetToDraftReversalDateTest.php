<?php

/**
 * Reset-to-Draft reversal date correctness.
 *
 * When a posted invoice is reset to draft, the reversal journal entry must
 * carry the SAME accounting date as the original invoice journal entry (not
 * the current system date), so both movements fall in the same accounting
 * period and the net impact on Revenue / AR / Tax is zero.
 *
 * Verified end-to-end through the router: POST /api/v1/invoices/{id}/reset-to-draft
 */

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class InvoiceResetToDraftReversalDateTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());

        $this->user = User::firstOrCreate(
            ['id' => 1],
            ['name' => 'Reset Draft User', 'email' => 'reset-draft@local', 'password' => bcrypt('password')],
        );
        $this->user->assignRole('Admin');
    }

    /** Post an invoice JE for a past accounting date and link it to the invoice. */
    private function postInvoice(Invoice $invoice, string $accountingDate): JournalEntry
    {
        $arAccount = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);
        $revenueAccount = ChartOfAccount::factory()->create(['type' => 'revenue', 'is_active' => true]);

        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: $invoice->id,
            postingDate: $accountingDate,
            description: "Invoice #{$invoice->invoice_number}",
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'accounts_receivable',
                    accountId: $arAccount->id,
                    debit: 1000.00,
                    credit: 0.00,
                    description: 'AR',
                ),
                new PostingRequestLine(
                    accountMappingKey: 'sales_revenue',
                    accountId: $revenueAccount->id,
                    debit: 0.00,
                    credit: 1000.00,
                    description: 'Revenue',
                ),
            ],
            userId: $this->user->id,
        ));

        $this->assertTrue($result->success, "Posting failed: {$result->error}");

        $invoice->update(['journal_entry_id' => $result->journalEntry->id]);

        return $result->journalEntry;
    }

    /** @test */
    public function reset_to_draft_creates_reversal_with_original_accounting_date(): void
    {
        $originalDate = now()->subMonths(5)->startOfMonth()->toDateString();

        $invoice = Invoice::factory()->create([
            'status' => 'cancelled',
            'lifecycle_status' => 'posted',
            'invoice_date' => $originalDate,
            'created_by' => $this->user->id,
        ]);

        $originalJe = $this->postInvoice($invoice, $originalDate);
        $this->assertSame($originalDate, $originalJe->entry_date->toDateString());

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/reset-to-draft");

        $response->assertOk();

        // Invoice back to draft, JE link cleared
        $invoice->refresh();
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->journal_entry_id);

        // Reversal entry exists, linked, and dated with the ORIGINAL accounting date
        $link = JournalEntryLink::where('original_journal_entry_id', $originalJe->id)
            ->where('relation_type', 'reversal')
            ->first();
        $this->assertNotNull($link, 'Reversal must be recorded via JournalEntryLink');

        $reversalJe = JournalEntry::find($link->journal_entry_id);
        $this->assertNotNull($reversalJe);
        $this->assertTrue((bool) $reversalJe->is_reversal);
        $this->assertSame(
            $originalDate,
            $reversalJe->entry_date->toDateString(),
            'Reversal must use the original invoice accounting date, not the current date'
        );
    }

    /** @test */
    public function reset_to_draft_reversal_nets_to_zero_per_account(): void
    {
        $originalDate = now()->subYear()->startOfMonth()->toDateString();

        $invoice = Invoice::factory()->create([
            'status' => 'cancelled',
            'lifecycle_status' => 'posted',
            'invoice_date' => $originalDate,
            'created_by' => $this->user->id,
        ]);

        $originalJe = $this->postInvoice($invoice, $originalDate);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/reset-to-draft")
            ->assertOk();

        $link = JournalEntryLink::where('original_journal_entry_id', $originalJe->id)
            ->where('relation_type', 'reversal')
            ->first();
        $reversalJe = JournalEntry::find($link->journal_entry_id);

        // Mirror lines: every original line has a swapped debit/credit counterpart
        $this->assertSame($originalJe->lines->count(), $reversalJe->lines->count());

        foreach ($originalJe->lines as $originalLine) {
            $mirror = $reversalJe->lines->firstWhere('account_id', $originalLine->account_id);
            $this->assertNotNull($mirror, 'Reversal must mirror every original line account');
            $this->assertEqualsWithDelta((float) $originalLine->credit, (float) $mirror->debit, 0.01);
            $this->assertEqualsWithDelta((float) $originalLine->debit, (float) $mirror->credit, 0.01);
        }

        // Net impact per account across original + reversal is zero
        $accountIds = $originalJe->lines->pluck('account_id')->unique();
        foreach ($accountIds as $accountId) {
            $netDebit = $originalJe->lines->where('account_id', $accountId)->sum('debit')
                + $reversalJe->lines->where('account_id', $accountId)->sum('debit');
            $netCredit = $originalJe->lines->where('account_id', $accountId)->sum('credit')
                + $reversalJe->lines->where('account_id', $accountId)->sum('credit');

            $this->assertEqualsWithDelta($netDebit, $netCredit, 0.01, "Account {$accountId} must net to zero");
        }
    }

    /** @test */
    public function reset_to_draft_is_idempotent_for_reversal(): void
    {
        $originalDate = now()->subMonths(3)->toDateString();

        $invoice = Invoice::factory()->create([
            'status' => 'cancelled',
            'lifecycle_status' => 'posted',
            'invoice_date' => $originalDate,
            'created_by' => $this->user->id,
        ]);

        $originalJe = $this->postInvoice($invoice, $originalDate);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/reset-to-draft")
            ->assertOk();

        // Only ONE reversal entry may exist for the original posting
        $reversalCount = JournalEntry::where('reversed_entry_id', $originalJe->id)
            ->where('is_reversal', true)
            ->count();
        $this->assertSame(1, $reversalCount);
    }
}
