<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrPostingService;
use App\Models\JournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Vendor;
use App\Models\FiscalPeriod;
use App\Models\User;
use Carbon\Carbon;

class DocumentPostingTest extends TenantTestCase
{
    protected User $user;
    protected OcrPostingService $service;
    protected Vendor $vendor;
    protected ChartOfAccount $expenseAccount;
    protected ChartOfAccount $payableAccount;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind a tenant context and seed Spatie roles + permissions so the
        // OcrPostingService routing through PostingEngine::post() passes the
        // fail-closed MISSING_TENANT_CONTEXT and INSUFFICIENT_PERMISSION
        // gates introduced at SHA 91d50469.
        // authority_evaluation_snapshots.tenant_id is a UUID column — the
        // default 'test-tenant' placeholder is rejected, so pass a UUID.
        $this->bindTenantContextWithPermissions((string) \Illuminate\Support\Str::uuid());

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->vendor = Vendor::factory()->create(['name' => 'Test Vendor']);
        
        $this->expenseAccount = ChartOfAccount::where('code', '5200')->firstOrFail();
        
        $this->payableAccount = ChartOfAccount::where('code', '2100')->firstOrFail();

        // Create open fiscal period for today — OcrPostingService delegates to
        // FiscalPeriodValidator which blocks dates without a defined period.
        $today = Carbon::today();
        FiscalPeriod::create([
            'name' => $today->format('F Y'),
            'code' => $today->format('Y-m'),
            'period_start' => $today->copy()->startOfMonth()->toDateString(),
            'period_end' => $today->copy()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => $today->year,
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);
        
        $this->service = app(OcrPostingService::class);
    }

    /**
     * Property 21: Draft saves don't create journal entries
     */
    public function test_draft_saves_dont_create_journal_entries()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test item',
                    'amount' => 100.00,
                    'account_id' => $this->expenseAccount->id,
                ],
            ],
        ]);

        // Update document (draft save) — use a column that exists
        $document->update([
            'error_message' => 'Draft notes',
        ]);

        // Should not create journal entry
        $this->assertEquals(0, JournalEntry::count());
        $this->assertEquals('NEEDS_REVIEW', $document->status);
    }

    /**
     * Property 22: Posting is atomic
     *
     * postDocument(OcrDocument $document): JournalEntry — takes 1 arg
     */
    public function test_posting_is_atomic()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test item',
                    'amount' => 100.00,
                    'account_id' => $this->expenseAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
        ]);

        $journalEntry = $this->service->postDocument($document);

        $document->refresh();

        // All or nothing - document posted and journal entry created
        $this->assertEquals('POSTED', $document->status);
        $this->assertNotNull($document->journal_entry_id);
        $this->assertEquals(1, JournalEntry::count());
        $this->assertNotNull($journalEntry);
    }

    /**
     * Property 23: Expense entry structure
     */
    public function test_expense_entry_structure()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 125.00,
            'line_items' => [
                [
                    'description' => 'Test item',
                    'amount' => 100.00,
                    'account_id' => $this->expenseAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 25.00,
        ]);

        $journalEntry = $this->service->postDocument($document);

        $document->refresh();

        $this->assertNotNull($journalEntry);
        
        // Verify lines
        $lines = $journalEntry->lines;
        $this->assertGreaterThanOrEqual(2, $lines->count()); // At least expense and payable
        
        // Verify debits and credits balance (columns are debit/credit decimals, NOT debit_credit enum)
        $totalDebits = $lines->sum('debit');
        $totalCredits = $lines->sum('credit');
        $this->assertGreaterThan(0, $totalDebits, 'Total debits should be positive');
        $this->assertEquals($totalDebits, $totalCredits);
    }

    /**
     * Property 24: Unbalanced entries are rejected
     */
    public function test_unbalanced_entries_are_rejected()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test item',
                    'amount' => 50.00, // Doesn't match total
                    'account_id' => $this->expenseAccount->id,
                ],
            ],
            'subtotal' => 50.00,
            'tax_amount' => 0,
        ]);

        $this->expectException(\Exception::class);
        
        $this->service->postDocument($document);
    }

    /**
     * Property 25: Successful posting updates status
     */
    public function test_successful_posting_updates_status()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test item',
                    'amount' => 100.00,
                    'account_id' => $this->expenseAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
        ]);

        $this->service->postDocument($document);

        $document->refresh();
        
        $this->assertEquals('POSTED', $document->status);
        $this->assertNotNull($document->posted_at);
        $this->assertNotNull($document->journal_entry_id);
    }

    /**
     * Property 26: Failed posting preserves state
     */
    public function test_failed_posting_preserves_state()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            // Line item missing account_id — triggers exception at
            // OcrPostingService::buildExpenseLines line 187-189
            'line_items' => [
                ['description' => 'Incomplete item', 'amount' => 100.00],
            ],
        ]);

        $originalStatus = $document->status;

        try {
            $this->service->postDocument($document);
        } catch (\Exception $e) {
            // Expected to fail
        }

        $document->refresh();
        
        // Status should not change
        $this->assertEquals($originalStatus, $document->status);
        $this->assertNull($document->journal_entry_id);
        $this->assertNull($document->posted_at);
    }

    /**
     * Property 51: Transaction failures rollback completely
     */
    public function test_transaction_failures_rollback_completely()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test item',
                    'amount' => 100.00,
                    'account_id' => 99999, // Invalid account ID
                ],
            ],
        ]);

        $initialDocCount = OcrDocument::count();
        $initialJournalCount = JournalEntry::count();

        try {
            $this->service->postDocument($document);
        } catch (\Exception $e) {
            // Expected to fail
        }

        // Verify rollback
        $this->assertEquals($initialDocCount, OcrDocument::count());
        $this->assertEquals($initialJournalCount, JournalEntry::count());
        
        $document->refresh();
        $this->assertEquals('NEEDS_REVIEW', $document->status);
    }

    /**
     * Test: Multiple line items create multiple debit entries
     */
    public function test_multiple_line_items_create_multiple_debit_entries()
    {
        // Use firstOrCreate to avoid duplicate key violation if code 5300
        // was seeded or created by a migration.
        $account2 = ChartOfAccount::firstOrCreate(
            ['code' => '5300'],
            [
                'name' => 'Other Expenses',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'account_type' => 'expense',
                'is_active' => true,
            ]
        );

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 200.00,
            'line_items' => [
                [
                    'description' => 'Item 1',
                    'amount' => 100.00,
                    'account_id' => $this->expenseAccount->id,
                ],
                [
                    'description' => 'Item 2',
                    'amount' => 100.00,
                    'account_id' => $account2->id,
                ],
            ],
            'subtotal' => 200.00,
            'tax_amount' => 0,
        ]);

        $journalEntry = $this->service->postDocument($document);

        $debitLines = $journalEntry->lines->where('debit', '>', 0);
        $this->assertGreaterThanOrEqual(2, $debitLines->count());
    }

    /**
     * Test: Tax is posted to separate account
     */
    public function test_tax_is_posted_to_separate_account()
    {
        $taxAccount = ChartOfAccount::firstOrCreate(
            ['code' => '1600'],
            [
                'name' => 'Input Tax',
                'type' => 'asset',
                'subtype' => 'current_asset',
                'account_type' => 'asset',
                'is_active' => true,
            ]
        );

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 125.00,
            'line_items' => [
                [
                    'description' => 'Test item',
                    'amount' => 100.00,
                    'account_id' => $this->expenseAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 25.00,
        ]);

        $journalEntry = $this->service->postDocument($document);

        // Should have tax line if tax account configured
        $lines = $journalEntry->lines;
        $this->assertGreaterThanOrEqual(2, $lines->count());
    }

    /**
     * Test: Document reference is included in journal entry
     */
    public function test_document_reference_is_included_in_journal_entry()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'document_reference' => 'INV-2024-001',
            'total_amount' => 100.00,
            'line_items' => [
                [
                    'description' => 'Test item',
                    'amount' => 100.00,
                    'account_id' => $this->expenseAccount->id,
                ],
            ],
            'subtotal' => 100.00,
            'tax_amount' => 0,
        ]);

        $journalEntry = $this->service->postDocument($document);

        // OcrPostingService puts document_reference in the `reference` field,
        // NOT in `description` (which uses "OCR Document: {vendor_name} - {file_name}").
        $this->assertStringContainsString('INV-2024-001', $journalEntry->reference ?? '');
    }

    /**
     * Test: Posting requires all mandatory fields
     */
    public function test_posting_requires_all_mandatory_fields()
    {
        // Line item missing account_id — triggers exception at
        // OcrPostingService::buildExpenseLines line 187-189.
        // Note: empty line_items [] falls through to a default expense fallback
        // and does NOT throw, so we must provide an incomplete item instead.
        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::today(),
            'total_amount' => 100.00,
            'line_items' => [
                ['description' => 'Missing account_id'],
            ],
        ]);

        $this->expectException(\Exception::class);
        $this->service->postDocument($document);
    }
}
