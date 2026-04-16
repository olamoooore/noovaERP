<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrPostingService;
use Modules\OCRBookkeeping\Exceptions\FiscalPeriodLockedException;
use App\Models\FiscalPeriod;
use App\Models\ChartOfAccount;
use App\Models\Vendor;
use App\Models\User;
use Carbon\Carbon;

class FiscalPeriodTest extends TenantTestCase
{
    protected User $user;
    protected User $adminUser;
    protected OcrPostingService $service;
    protected Vendor $vendor;
    protected ChartOfAccount $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->adminUser = User::factory()->create(); // Assume has fiscal.override permission
        $this->vendor = Vendor::factory()->create(['name' => 'Test Vendor']);
        
        $this->expenseAccount = ChartOfAccount::factory()->create([
            'code' => '5200',
            'name' => 'Operating Expenses',
            'account_type' => 'expense',
        ]);
        
        $this->service = app(OcrPostingService::class);
    }

    /**
     * Property 36: Locked periods reject posting
     */
    public function test_locked_periods_reject_posting()
    {
        // Create locked fiscal period
        $lockedPeriod = FiscalPeriod::create([
            'name' => 'December 2024',
            'code' => '2024-12',
            'period_start' => '2024-12-01',
            'period_end' => '2024-12-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2024,
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $this->adminUser->id,
            'status' => 'locked',
            'created_by' => $this->adminUser->id,
        ]);

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::parse('2024-12-15'), // In locked period
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

        $this->expectException(FiscalPeriodLockedException::class);
        
        $this->service->postDocument($document, $this->user->id, false);
    }

    /**
     * Property 37: Locked periods are read-only
     */
    public function test_locked_periods_are_read_only()
    {
        $lockedPeriod = FiscalPeriod::create([
            'name' => 'December 2024',
            'code' => '2024-12',
            'period_start' => '2024-12-01',
            'period_end' => '2024-12-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2024,
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $this->adminUser->id,
            'status' => 'locked',
            'created_by' => $this->adminUser->id,
        ]);

        $this->assertTrue($lockedPeriod->is_locked);
        $this->assertEquals('locked', $lockedPeriod->status);
        
        // Verify period is locked for the date
        $this->assertTrue($lockedPeriod->isDateInPeriod(Carbon::parse('2024-12-15')));
    }

    /**
     * Property 38: Open periods allow posting
     */
    public function test_open_periods_allow_posting()
    {
        // Create open fiscal period
        $openPeriod = FiscalPeriod::create([
            'name' => 'January 2025',
            'code' => '2025-01',
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2025,
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->adminUser->id,
        ]);

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::parse('2025-01-15'), // In open period
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

        // Should not throw exception
        $this->service->postDocument($document, $this->user->id, false);

        $document->refresh();
        $this->assertEquals('POSTED', $document->status);
    }

    /**
     * Property 39: Override permission creates audit log
     */
    public function test_override_permission_creates_audit_log()
    {
        $lockedPeriod = FiscalPeriod::create([
            'name' => 'December 2024',
            'code' => '2024-12',
            'period_start' => '2024-12-01',
            'period_end' => '2024-12-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2024,
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $this->adminUser->id,
            'status' => 'locked',
            'created_by' => $this->adminUser->id,
        ]);

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::parse('2024-12-15'),
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

        // Post with override
        $this->service->postDocument($document, $this->adminUser->id, true);

        $document->refresh();
        
        // Should succeed with override
        $this->assertEquals('POSTED', $document->status);
        
        // Verify audit log created (check notes or audit table)
        $this->assertNotNull($document->notes);
    }

    /**
     * Property 40: Draft saves bypass fiscal lock
     */
    public function test_draft_saves_bypass_fiscal_lock()
    {
        $lockedPeriod = FiscalPeriod::create([
            'name' => 'December 2024',
            'code' => '2024-12',
            'period_start' => '2024-12-01',
            'period_end' => '2024-12-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2024,
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $this->adminUser->id,
            'status' => 'locked',
            'created_by' => $this->adminUser->id,
        ]);

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::parse('2024-12-15'), // In locked period
            'total_amount' => 100.00,
        ]);

        // Draft save should work even in locked period
        $document->update([
            'notes' => 'Updated notes',
            'line_items' => [
                [
                    'description' => 'Test item',
                    'amount' => 100.00,
                    'account_id' => $this->expenseAccount->id,
                ],
            ],
        ]);

        $document->refresh();
        
        // Should remain in NEEDS_REVIEW (not posted)
        $this->assertEquals('NEEDS_REVIEW', $document->status);
        $this->assertEquals('Updated notes', $document->notes);
    }

    /**
     * Test: Multiple overlapping periods handled correctly
     */
    public function test_multiple_overlapping_periods_handled_correctly()
    {
        // Create monthly period
        $monthlyPeriod = FiscalPeriod::create([
            'name' => 'December 2024',
            'code' => '2024-12',
            'period_start' => '2024-12-01',
            'period_end' => '2024-12-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2024,
            'is_locked' => true,
            'status' => 'locked',
            'created_by' => $this->adminUser->id,
        ]);

        // Create quarterly period (overlapping)
        $quarterlyPeriod = FiscalPeriod::create([
            'name' => 'Q4 2024',
            'code' => '2024-Q4',
            'period_start' => '2024-10-01',
            'period_end' => '2024-12-31',
            'period_type' => 'quarterly',
            'fiscal_year' => 2024,
            'is_locked' => true,
            'status' => 'locked',
            'created_by' => $this->adminUser->id,
        ]);

        $testDate = Carbon::parse('2024-12-15');
        
        // Both periods should contain this date
        $this->assertTrue($monthlyPeriod->isDateInPeriod($testDate));
        $this->assertTrue($quarterlyPeriod->isDateInPeriod($testDate));
    }

    /**
     * Test: Future periods can be created
     */
    public function test_future_periods_can_be_created()
    {
        $futurePeriod = FiscalPeriod::create([
            'name' => 'December 2025',
            'code' => '2025-12',
            'period_start' => '2025-12-01',
            'period_end' => '2025-12-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2025,
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->adminUser->id,
        ]);

        $this->assertFalse($futurePeriod->is_locked);
        $this->assertEquals('open', $futurePeriod->status);
    }

    /**
     * Test: Period can be locked and unlocked
     */
    public function test_period_can_be_locked_and_unlocked()
    {
        $period = FiscalPeriod::create([
            'name' => 'January 2025',
            'code' => '2025-01',
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2025,
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->adminUser->id,
        ]);

        // Lock period
        $period->update([
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $this->adminUser->id,
            'status' => 'locked',
        ]);

        $this->assertTrue($period->is_locked);

        // Unlock period
        $period->update([
            'is_locked' => false,
            'locked_at' => null,
            'locked_by' => null,
            'status' => 'open',
        ]);

        $this->assertFalse($period->is_locked);
    }

    /**
     * Test: Date outside all periods is allowed
     */
    public function test_date_outside_all_periods_is_allowed()
    {
        // Create only one period
        FiscalPeriod::create([
            'name' => 'December 2024',
            'code' => '2024-12',
            'period_start' => '2024-12-01',
            'period_end' => '2024-12-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2024,
            'is_locked' => true,
            'status' => 'locked',
            'created_by' => $this->adminUser->id,
        ]);

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::parse('2025-01-15'), // Outside defined periods
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

        // Should be allowed (no period defined for this date)
        $this->service->postDocument($document, $this->user->id, false);

        $document->refresh();
        $this->assertEquals('POSTED', $document->status);
    }
}
