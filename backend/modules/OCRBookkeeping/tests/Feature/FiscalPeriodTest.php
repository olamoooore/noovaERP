<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrPostingService;
use App\Models\FiscalPeriod;
use App\Models\ChartOfAccount;
use App\Models\Vendor;
use App\Models\LockDateOverride;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
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

        // Bind a tenant context and seed Spatie roles + permissions so the
        // OcrPostingService routing through PostingEngine::post() passes the
        // fail-closed MISSING_TENANT_CONTEXT and INSUFFICIENT_PERMISSION
        // gates introduced at SHA 91d50469.
        // authority_evaluation_snapshots.tenant_id is a UUID column — the
        // default 'test-tenant' placeholder is rejected, so pass a UUID.
        $this->bindTenantContextWithPermissions((string) \Illuminate\Support\Str::uuid());

        $this->user = User::factory()->create();

        // Create admin role and assign fiscal.override permission to it.
        // FiscalPeriodValidator::userCanOverride() checks hasPermissionTo() first
        // (which looks at direct perms AND role perms), so the permission must be
        // assigned to the role — the hasRole() fallback is never reached.
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $overridePerm = Permission::firstOrCreate(['name' => 'fiscal.override', 'guard_name' => 'web']);
        $adminRole->givePermissionTo($overridePerm);
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('admin');

        $this->vendor = Vendor::factory()->create(['name' => 'Test Vendor']);

        $this->expenseAccount = ChartOfAccount::where('code', '5200')->firstOrFail();

        $this->service = app(OcrPostingService::class);

        // Default acting-as the regular user; override tests switch to adminUser
        $this->actingAs($this->user);
    }

    /**
     * Property 36: Locked periods reject posting
     */
    public function test_locked_periods_reject_posting()
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
        ]);

        $this->expectException(\Exception::class);

        $this->service->postDocument($document);
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

        // Verify period contains the date
        $this->assertTrue($lockedPeriod->containsDate(Carbon::parse('2024-12-15')));
    }

    /**
     * Property 38: Open periods allow posting
     */
    public function test_open_periods_allow_posting()
    {
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
            'document_date' => Carbon::parse('2025-01-15'),
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

        // Should not throw exception
        $this->service->postDocument($document);

        $document->refresh();
        $this->assertEquals('POSTED', $document->status);
    }

    /**
     * Property 39: Override permission creates audit log
     *
     * When an admin user posts to a locked period, the FiscalPeriodValidator
     * allows it (via hasRole('admin') fallback) and OcrPostingService calls
     * logOverride() which persists a LockDateOverride row.
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
        ]);

        // Switch to admin user so FiscalPeriodValidator sees the admin role
        $this->actingAs($this->adminUser);

        $this->service->postDocument($document);

        $document->refresh();

        // Should succeed with override
        $this->assertEquals('POSTED', $document->status);

        // Verify audit log row was created in lock_date_overrides
        $this->assertDatabaseHas('lock_date_overrides', [
            'user_id' => $this->adminUser->id,
            'transaction_type' => 'fiscal_period',
            'transaction_id' => $lockedPeriod->id,
        ]);
    }

    /**
     * Property 40: Draft saves bypass fiscal lock
     *
     * Updating document metadata (e.g. line_items, error_message) does NOT
     * trigger fiscal period validation — only postDocument() does.
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
            'document_date' => Carbon::parse('2024-12-15'),
            'total_amount' => 100.00,
        ]);

        // Draft save should work even in locked period
        $document->update([
            'error_message' => 'Updated error message',
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
        $this->assertEquals('Updated error message', $document->error_message);
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

        // Create quarterly period (different date range to avoid unique constraint)
        $quarterlyPeriod = FiscalPeriod::create([
            'name' => 'Q4 2024',
            'code' => '2024-Q4',
            'period_start' => '2024-10-01',
            'period_end' => '2024-12-30',
            'period_type' => 'quarterly',
            'fiscal_year' => 2024,
            'is_locked' => true,
            'status' => 'locked',
            'created_by' => $this->adminUser->id,
        ]);

        $testDate = Carbon::parse('2024-12-15');

        // Both periods should contain this date
        $this->assertTrue($monthlyPeriod->containsDate($testDate));
        $this->assertTrue($quarterlyPeriod->containsDate($testDate));
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
     * Test: Date outside locked periods but inside an open period is allowed
     *
     * FiscalPeriodValidator BLOCKS dates that have no fiscal period defined.
     * So we create an open period for the target date to prove posting works
     * when the date falls outside the *locked* period.
     */
    public function test_date_outside_locked_period_in_open_period_is_allowed()
    {
        // Create a locked period for December 2024
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

        // Create an open period for January 2025 (the target date)
        FiscalPeriod::create([
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
        ]);

        // Should be allowed — date is in an open period
        $this->service->postDocument($document);

        $document->refresh();
        $this->assertEquals('POSTED', $document->status);
    }

    /**
     * Test: Date with no fiscal period defined is rejected
     *
     * FiscalPeriodValidator blocks dates outside all defined periods.
     */
    public function test_date_with_no_fiscal_period_is_rejected()
    {
        // Create a period for December 2024
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

        // Create a Feb 2025 period so hasAnyPeriodForYear(2025) returns true
        // and the validator does NOT auto-provision Jan 2025.
        // Jan 15 falls outside Feb → blocked.
        FiscalPeriod::create([
            'name' => 'February 2025',
            'code' => '2025-02',
            'period_start' => '2025-02-01',
            'period_end' => '2025-02-28',
            'period_type' => 'monthly',
            'fiscal_year' => 2025,
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->adminUser->id,
        ]);

        $document = OcrDocument::factory()->create([
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $this->vendor->id,
            'document_date' => Carbon::parse('2025-01-15'), // No period covers this date
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

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/No fiscal period is defined/');

        $this->service->postDocument($document);
    }
}
