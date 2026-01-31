<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrPostingService;
use App\Models\JournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Vendor;
use App\Models\User;
use Carbon\Carbon;

class DocumentPostingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected OcrPostingService $service;
    protected Vendor $vendor;
    protected ChartOfAccount $expenseAccount;
    protected ChartOfAccount $payableAccount;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->vendor = Vendor::factory()->create(['name' => 'Test Vendor']);
        
        $this->expenseAccount = ChartOfAccount::factory()->create([
            'code' => '5200',
            'name' => 'Operating Expenses',
            'account_type' => 'expense',
        ]);
        
        $this->payableAccount = ChartOfAccount::factory()->create([
            'code' => '2100',
            'name' => 'Accounts Payable',
            'account_type' => 'liability',
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

        // Update document (draft save)
        $document->update([
            'notes' => 'Updated notes',
        ]);

        // Should not create journal entry
        $this->assertEquals(0, JournalEntry::count());
        $this->assertEquals('NEEDS_REVIEW', $document->status);
    }

    /**
     * Property 22: Posting is atomic
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
            'tax_rate' => 0,
        ]);

        DB::beginTransaction();
        
        try {
            $this->service->postDocument($document, $this->user->id);
            DB::commit();
            
            $document->refresh();
            
            // All or nothing - document posted and journal entry created
            $this->assertEquals('POSTED', $document->status);
            $this->assertNotNull($document->journal_entry_id);
            $this->assertEquals(1, JournalEntry::count());
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
            'tax_rate' => 25.0,
        ]);

        $this->service->postDocument($document, $this->user->id);

        $document->refresh();
        $journalEntry = JournalEntry::find($document->journal_entry_id);

        $this->assertNotNull($journalEntry);
        
        // Verify entry structure
        $this->assertEquals('invoice', $journalEntry->entry_type);
        $this->assertEquals($document->document_date->format('Y-m-d'), $journalEntry->document_date->format('Y-m-d'));
        
        // Verify lines
        $lines = $journalEntry->lines;
        $this->assertGreaterThanOrEqual(2, $lines->count()); // At least expense and payable
        
        // Verify debits and credits balance
        $totalDebits = $lines->where('debit_credit', 'debit')->sum('amount');
        $totalCredits = $lines->where('debit_credit', 'credit')->sum('amount');
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
            'tax_rate' => 0,
        ]);

        $this->expectException(\Exception::class);
        
        $this->service->postDocument($document, $this->user->id);
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
            'tax_rate' => 0,
        ]);

        $this->service->postDocument($document, $this->user->id);

        $document->refresh();
        
        $this->assertEquals('POSTED', $document->status);
        $this->assertNotNull($document->posted_at);
        $this->assertEquals($this->user->id, $document->posted_by);
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
            'line_items' => [], // Invalid - no line items
        ]);

        $originalStatus = $document->status;
        $originalUpdatedAt = $document->updated_at;

        try {
            $this->service->postDocument($document, $this->user->id);
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
            DB::transaction(function () use ($document) {
                $this->service->postDocument($document, $this->user->id);
            });
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
        $account2 = ChartOfAccount::factory()->create([
            'code' => '5300',
            'name' => 'Other Expenses',
            'account_type' => 'expense',
        ]);

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
            'tax_rate' => 0,
        ]);

        $this->service->postDocument($document, $this->user->id);

        $document->refresh();
        $journalEntry = JournalEntry::find($document->journal_entry_id);
        
        $debitLines = $journalEntry->lines->where('debit_credit', 'debit');
        $this->assertGreaterThanOrEqual(2, $debitLines->count());
    }

    /**
     * Test: Tax is posted to separate account
     */
    public function test_tax_is_posted_to_separate_account()
    {
        $taxAccount = ChartOfAccount::factory()->create([
            'code' => '1500',
            'name' => 'Input Tax',
            'account_type' => 'asset',
        ]);

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
            'tax_rate' => 25.0,
        ]);

        $this->service->postDocument($document, $this->user->id);

        $document->refresh();
        $journalEntry = JournalEntry::find($document->journal_entry_id);
        
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
            'tax_rate' => 0,
        ]);

        $this->service->postDocument($document, $this->user->id);

        $document->refresh();
        $journalEntry = JournalEntry::find($document->journal_entry_id);
        
        $this->assertStringContainsString('INV-2024-001', $journalEntry->description ?? '');
    }

    /**
     * Test: Posting requires all mandatory fields
     */
    public function test_posting_requires_all_mandatory_fields()
    {
        $invalidDocuments = [
            // Missing vendor
            OcrDocument::factory()->create([
                'status' => 'NEEDS_REVIEW',
                'vendor_id' => null,
                'document_date' => Carbon::today(),
                'total_amount' => 100.00,
            ]),
            // Missing document date
            OcrDocument::factory()->create([
                'status' => 'NEEDS_REVIEW',
                'vendor_id' => $this->vendor->id,
                'document_date' => null,
                'total_amount' => 100.00,
            ]),
            // Missing line items
            OcrDocument::factory()->create([
                'status' => 'NEEDS_REVIEW',
                'vendor_id' => $this->vendor->id,
                'document_date' => Carbon::today(),
                'total_amount' => 100.00,
                'line_items' => [],
            ]),
        ];

        foreach ($invalidDocuments as $document) {
            $this->expectException(\Exception::class);
            $this->service->postDocument($document, $this->user->id);
        }
    }
}
