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
use Illuminate\Support\Facades\DB;
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

    /**
     * After reset-to-draft, reposting must create a NEW active journal entry,
     * not re-link to the original (now reversed) one. The P&L must surface the
     * new posting's revenue while excluding the original + reversal.
     *
     * Regression: before the fix, PostingEngine::post()'s idempotency guard
     * found the leftover `post` JournalEntryLink and returned the OLD JE id,
     * so the reposted invoice pointed back at the reversed JE — making the new
     * posting invisible in the P&L while the old revenue stayed counted.
     */
    /** @test */
    public function repost_after_reset_creates_fresh_journal_entry_visible_in_pl(): void
    {
        // AR + Revenue accounts reused across postings so the P&L can compare
        $arAccount = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);
        $revenueAccount = ChartOfAccount::factory()->create(['type' => 'revenue', 'is_active' => true]);

        // Original posting at $1,000 on a past date
        $originalDate = now()->subMonths(5)->startOfMonth()->toDateString();
        $invoice = Invoice::factory()->create([
            'status' => 'cancelled',
            'lifecycle_status' => 'posted',
            'invoice_date' => $originalDate,
            'created_by' => $this->user->id,
        ]);

        $originalResult = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: $invoice->id,
            postingDate: $originalDate,
            description: 'Original posting',
            lines: [
                new PostingRequestLine('accounts_receivable', $arAccount->id, 1000.00, 0.00, 'AR'),
                new PostingRequestLine('sales_revenue', $revenueAccount->id, 0.00, 1000.00, 'Revenue'),
            ],
            userId: $this->user->id,
        ));
        $this->assertTrue($originalResult->success);
        $invoice->update(['journal_entry_id' => $originalResult->journalEntry->id]);

        // Reset to draft — creates reversal and releases the (invoice, 'post') link
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/reset-to-draft")
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->journal_entry_id);

        // The (invoice, 'post') link must have been released so a repost can succeed
        $this->assertSame(
            0,
            JournalEntryLink::where('source_document_type', 'invoice')
                ->where('source_document_id', $invoice->id)
                ->where('relation_type', 'post')
                ->count(),
            'Reset must release the post link so repost can create a new post link',
        );

        // Repost with a NEW amount — simulate editing then posting again
        $repostDate = $originalDate; // reposted to the same accounting period for the test
        $invoice->update(['total' => 100, 'balance' => 100, 'subtotal' => 100, 'tax_amount' => 0]);

        $repostResult = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: $invoice->id,
            postingDate: $repostDate,
            description: 'Reposted posting',
            lines: [
                new PostingRequestLine('accounts_receivable', $arAccount->id, 100.00, 0.00, 'AR'),
                new PostingRequestLine('sales_revenue', $revenueAccount->id, 0.00, 100.00, 'Revenue'),
            ],
            userId: $this->user->id,
        ));

        $this->assertTrue(
            $repostResult->success,
            "Repost must produce a fresh JE, not return the old one: {$repostResult->error}",
        );

        // Repost must produce a NEW JournalEntry (different id from the original)
        $this->assertNotSame(
            $originalResult->journalEntry->id,
            $repostResult->journalEntry->id,
            'Repost must create a new JE rather than reuse the original (reversed) one',
        );

        // Invoice is now linked to the reposted JE
        $invoice->update(['status' => 'posted', 'lifecycle_status' => 'posted', 'journal_entry_id' => $repostResult->journalEntry->id]);

        // P&L query exactly mirrors ProfitLossService::getAmountFromJournal
        $revenueTotal = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('je.is_active_version', true)
            ->where('coa.type', 'revenue')
            ->whereBetween('je.entry_date', [$repostDate, $repostDate])
            ->sum('jel.credit');

        // Revenue should reflect ONLY the reposted $100 — original $1000 and the
        // reversal entries are both excluded by the is_active_version filter.
        $this->assertEqualsWithDelta(100.0, (float) $revenueTotal, 0.01,
            'P&L must show only the reposted revenue (100), not the original 1000');
    }
}
