<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\DeferredRevenueSchedule;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #882 (SA9) — deferred revenue.
 *
 * Every invoice used to recognise its full revenue on the invoice date: an
 * annual service contract / subscription / prepaid maintenance hit the P&L
 * at once. A line can now name its service period (or inherit one from the
 * product's deferred settings); at posting the unearned amount credits the
 * deferred-revenue role account and a monthly job releases the earned
 * portion to the line's income account through PostingEngine. Credit notes
 * and cancellations reverse the unreleased balance, and the schedule is
 * reportable per legal entity.
 *
 * Everything below goes through the real HTTP routes / Artisan entry points,
 * never a service instantiated by the test.
 */
class DeferredRevenueScheduleTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private ChartOfAccount $deferredRevenue;

    private ChartOfAccount $servicesRevenue;

    private ChartOfAccount $receivable;

    private Product $deferredProduct;

    private int $legalEntityId;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->admin = User::factory()->create(['email' => 'sa9-'.uniqid().'@example.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        CompanySettings::query()->create([
            'company_name' => 'SA9 Deferred Revenue Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $entityGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'SA9 Group',
            'code' => 'SA9-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $entityGroupId,
            'name' => 'SA9 Entity',
            'code' => 'SA9-E-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => $this->legalEntityId,
            'payment_terms' => 30,
        ]);

        $this->servicesRevenue = $this->account('SA9-4110', 'SA9 Services Income', 'revenue');
        $this->deferredRevenue = $this->account('SA9-2300', 'SA9 Deferred Revenue', 'liability');
        $this->receivable = $this->account('SA9-1240', 'SA9 Accounts Receivable', 'asset');

        // Deterministic role → account wiring for this fixture.
        foreach ([
            'accounts_receivable' => $this->receivable->id,
            'sales_revenue' => $this->servicesRevenue->id,
            'deferred_revenue' => $this->deferredRevenue->id,
        ] as $key => $accountId) {
            DB::table('account_mappings')->where('mapping_key', $key)->delete();
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => $accountId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The service product is deferred by default for 12 months.
        $this->deferredProduct = Product::factory()->create([
            'name' => 'SA9 Annual Maintenance',
            'sku' => 'SA9-SRV-'.Str::upper(Str::random(5)),
            'price' => 1200,
            'cost' => 0,
            'track_inventory' => false,
            'income_account_id' => $this->servicesRevenue->id,
            'is_deferred' => true,
            'deferred_months' => 12,
        ]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /**
     * AC1+AC2: a deferred line defaults its period from the product's
     * deferred setting, and posting credits the deferred-revenue role account
     * instead of income — with a schedule created for the release job.
     */
    public function test_deferred_invoice_credits_deferred_revenue_and_creates_a_schedule(): void
    {
        $invoice = $this->createInvoice();
        $line = $invoice->lines()->firstOrFail();

        $this->assertTrue((bool) $line->is_deferred, 'The product deferred setting must default the line to deferred.');

        $this->postInvoice($invoice);

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $this->assertEqualsWithDelta(
            1200.0,
            (float) $lines->where('account_id', $this->deferredRevenue->id)->sum('credit'),
            0.001,
            'The unearned amount must credit the deferred-revenue role account.'
        );
        $this->assertSame(
            0.0,
            round((float) $lines->where('account_id', $this->servicesRevenue->id)->sum('credit'), 2),
            'No revenue may be recognised on the invoice date.'
        );
        $this->assertEqualsWithDelta(
            1200.0,
            (float) $lines->where('account_id', $this->receivable->id)->sum('debit'),
            0.001
        );

        $debits = (float) $lines->sum('debit');
        $credits = (float) $lines->sum('credit');
        $this->assertEqualsWithDelta($debits, $credits, 0.001, 'The journal entry must stay balanced (debits == credits).');

        $schedule = DeferredRevenueSchedule::where('invoice_line_id', $line->id)->firstOrFail();
        $this->assertEqualsWithDelta(1200.0, (float) $schedule->amount, 0.001);
        $this->assertSame(0.0, round((float) $schedule->recognized_amount, 2));
        $this->assertSame('active', $schedule->status);
        $this->assertSame(now()->toDateString(), $schedule->start_date->toDateString());
        $this->assertSame(
            now()->addMonthsNoOverflow(12)->toDateString(),
            $schedule->end_date->toDateString(),
            'The deferred period must default to the product deferred months.'
        );
        $this->assertSame((int) $this->deferredRevenue->id, (int) $schedule->deferred_account_id);
        $this->assertSame((int) $this->servicesRevenue->id, (int) $schedule->income_account_id);
    }

    /**
     * AC2: the monthly job releases the earned portion to the line's income
     * account through PostingEngine and records it on the schedule.
     */
    public function test_monthly_job_releases_the_earned_portion(): void
    {
        [$invoice, $line] = $this->postDeferredInvoiceWithExplicitPeriod();

        $this->artisan('accounting:recognize-deferred-revenue', [
            '--as-of' => now()->toDateString(),
            '--tenant' => $this->tenantId,
        ])->assertExitCode(0);

        $schedule = DeferredRevenueSchedule::where('invoice_line_id', $line->id)->firstOrFail();

        // 181/365 of a 365-day, 1200.00 period.
        $this->assertEqualsWithDelta(595.07, (float) $schedule->recognized_amount, 0.001);
        $this->assertSame('active', $schedule->status);

        $release = \App\Models\JournalEntry::where('type', 'deferred_revenue_recognition')
            ->with('lines')
            ->latest('id')
            ->firstOrFail();

        $deferredDebit = (float) $release->lines->where('account_id', $this->deferredRevenue->id)->sum('debit');
        $incomeCredit = (float) $release->lines->where('account_id', $this->servicesRevenue->id)->sum('credit');

        $this->assertEqualsWithDelta(595.07, $deferredDebit, 0.001, 'The release must debit deferred revenue.');
        $this->assertEqualsWithDelta(595.07, $incomeCredit, 0.001, 'The release must credit the line income account.');

        $debits = (float) $release->lines->sum('debit');
        $credits = (float) $release->lines->sum('credit');
        $this->assertEqualsWithDelta($debits, $credits, 0.001, 'The release entry must be balanced.');

        // A second run on the same date must not double-release.
        $this->artisan('accounting:recognize-deferred-revenue', [
            '--as-of' => now()->toDateString(),
            '--tenant' => $this->tenantId,
        ])->assertExitCode(0);

        $this->assertSame(
            595.07,
            round((float) $schedule->fresh()->recognized_amount, 2),
            'A repeated run must not recognise anything twice.'
        );
    }

    /**
     * AC3: a credit note of a deferred line reverses the unreleased balance
     * from the deferred account, not from income.
     */
    public function test_credit_note_reverses_the_unreleased_balance(): void
    {
        [$invoice, $line] = $this->postDeferredInvoiceWithExplicitPeriod();

        $create = $this->actingAs($this->admin)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'lines' => [[
                'invoice_line_id' => $line->id,
                'description' => 'Annual maintenance credit',
                'quantity' => 1,
                'unit_price' => 1200,
                'tax_rate' => 0,
            ]],
        ]);
        $create->assertCreated($create->getContent());

        $creditNoteId = $create->json('id');
        $post = $this->actingAs($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/post");
        $post->assertOk($post->getContent());

        $creditNote = \App\Models\CustomerCreditNote::with('journalEntry.lines')->findOrFail($creditNoteId);
        $lines = $creditNote->journalEntry->lines;

        $this->assertEqualsWithDelta(
            1200.0,
            (float) $lines->where('account_id', $this->deferredRevenue->id)->sum('debit'),
            0.001,
            'The credit note must reverse the unreleased balance from deferred revenue.'
        );
        $this->assertSame(
            0.0,
            round((float) $lines->where('account_id', $this->servicesRevenue->id)->sum('debit'), 2),
            'Revenue that was never recognised must not be debited to income.'
        );

        $debits = (float) $lines->sum('debit');
        $credits = (float) $lines->sum('credit');
        $this->assertEqualsWithDelta($debits, $credits, 0.001, 'The credit note entry must be balanced.');

        $schedule = DeferredRevenueSchedule::where('invoice_line_id', $line->id)->firstOrFail();
        $this->assertSame(
            0.0,
            round((float) $schedule->amount - (float) $schedule->recognized_amount - (float) $schedule->reversed_amount, 2),
            'The schedule must have no unreleased balance left.'
        );
        $this->assertContains($schedule->status, ['completed', 'reversed']);
    }

    /**
     * AC3: cancelling a posted deferred invoice reverses the whole posting
     * and stops the schedule from ever releasing.
     */
    public function test_cancelling_a_deferred_invoice_stops_the_schedule(): void
    {
        [$invoice, $line] = $this->postDeferredInvoiceWithExplicitPeriod();

        $cancel = $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel");
        $cancel->assertOk($cancel->getContent());

        $schedule = DeferredRevenueSchedule::where('invoice_line_id', $line->id)->firstOrFail();
        $this->assertSame('cancelled', $schedule->status, 'Cancelling the invoice must close the schedule.');

        $this->artisan('accounting:recognize-deferred-revenue', [
            '--as-of' => now()->toDateString(),
            '--tenant' => $this->tenantId,
        ])->assertExitCode(0);

        $this->assertSame(
            0.0,
            round((float) $schedule->fresh()->recognized_amount, 2),
            'A cancelled schedule must never release revenue.'
        );

        // The reversal mirrors the original: net effect on the deferred
        // account across the invoice entry + its reversal is nil.
        $reversal = \App\Models\JournalEntryLink::where('original_journal_entry_id', $invoice->journal_entry_id)
            ->where('relation_type', 'reversal')
            ->firstOrFail();

        $net = (float) DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', [$invoice->journal_entry_id, $reversal->journal_entry_id])
            ->where('account_id', $this->deferredRevenue->id)
            ->sum(DB::raw('credit - debit'));

        $this->assertSame(0.0, round($net, 2), 'Original + reversal must net to zero on deferred revenue.');
    }

    /**
     * Reset-to-draft reverses the posting and must close the schedule with
     * it; reposting replaces the closed schedule with a fresh one instead of
     * colliding with (or releasing from) the stale row.
     */
    public function test_reset_to_draft_closes_the_schedule_and_repost_recreates_it(): void
    {
        [$invoice, $line] = $this->postDeferredInvoiceWithExplicitPeriod();

        $reset = $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/reset-to-draft");
        $reset->assertOk($reset->getContent());

        $this->assertSame(
            'cancelled',
            DeferredRevenueSchedule::where('invoice_line_id', $line->id)->value('status'),
            'The reset reversal must close the schedule so nothing can be released.'
        );

        $this->postInvoice(Invoice::findOrFail($invoice->id));

        $schedule = DeferredRevenueSchedule::where('invoice_line_id', $line->id)->firstOrFail();
        $this->assertSame('active', $schedule->status);
        $this->assertSame(1200.0, round((float) $schedule->amount, 2));
        $this->assertSame(0.0, round((float) $schedule->recognized_amount, 2));
    }

    /**
     * AC4: the deferred-revenue schedule is reportable per legal entity.
     */
    public function test_deferred_revenue_schedule_report_is_entity_scoped(): void
    {
        [$invoice, $line] = $this->postDeferredInvoiceWithExplicitPeriod();

        $this->artisan('accounting:recognize-deferred-revenue', [
            '--as-of' => now()->toDateString(),
            '--tenant' => $this->tenantId,
        ])->assertExitCode(0);

        $response = $this->actingAs($this->admin)->getJson(
            '/api/v1/accounting/deferred-revenue?legal_entity_id='.$this->legalEntityId
        );
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('invoice_line_id', $line->id);
        $this->assertNotNull($row, 'The report must include the deferred schedule for the entity.');
        $this->assertEqualsWithDelta(1200.0, (float) $row['amount'], 0.001);
        $this->assertEqualsWithDelta(595.07, (float) $row['recognized_amount'], 0.001);
        $this->assertEqualsWithDelta(604.93, (float) $row['remaining_amount'], 0.001);

        // Another entity sees none of this entity's schedules.
        $otherEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => DB::table('legal_entities')->where('id', $this->legalEntityId)->value('group_id'),
            'name' => 'SA9 Other Entity',
            'code' => 'SA9-O-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $other = $this->actingAs($this->admin)->getJson(
            '/api/v1/accounting/deferred-revenue?legal_entity_id='.$otherEntityId
        );
        $other->assertOk();
        $this->assertSame([], $other->json('data'), 'A different entity must not see these schedules.');
    }

    /**
     * @return array{0: Invoice, 1: \App\Models\InvoiceLine}
     */
    private function postDeferredInvoiceWithExplicitPeriod(): array
    {
        $invoice = $this->createInvoice([
            'service_start_date' => now()->subDays(181)->toDateString(),
            'service_end_date' => now()->addDays(184)->toDateString(),
        ]);

        $line = $invoice->lines()->firstOrFail();
        $this->postInvoice($invoice);

        return [Invoice::findOrFail($invoice->id), $line];
    }

    private function createInvoice(array $lineOverrides = []): Invoice
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [array_merge([
                'product_id' => $this->deferredProduct->id,
                'description' => 'Annual maintenance contract',
                'quantity' => 1,
                'unit_price' => 1200,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ], $lineOverrides)],
        ]);
        $response->assertCreated($response->getContent());

        return Invoice::with('lines')->findOrFail($response->json('id'));
    }

    private function postInvoice(Invoice $invoice): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post");
        $response->assertOk($response->getContent());
    }

    private function account(string $code, string $name, string $type): ChartOfAccount
    {
        return ChartOfAccount::factory()->create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }
}
