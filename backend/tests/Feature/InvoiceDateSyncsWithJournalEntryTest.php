<?php
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * ADR-005 §15.5 — invoice_date / journal entry date integrity.
 *
 * If an invoice.invoice_date is changed on a posted invoice (whether via
 * API, DB write, or future bulk update), the underlying
 * JournalEntry.entry_date MUST move with it. Otherwise the JE keeps
 * reporting the sale/AR hit in the original period and the P&L is
 * wrong — the exact bug the user reported on 2026-08-27 (invoices
 * 2026/01/008, 2026/01/084, 2026/01/113 hitting Jan 2025 P&L).
 */
class InvoiceDateSyncsWithJournalEntryTest extends TenantTestCase
{
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) \Illuminate\Support\Str::uuid();
        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Invoice Date Sync Test',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_posted_invoice_date_change_syncs_journal_entry_entry_date(): void
    {
        // Create a posted invoice with a JournalEntry directly so we can test
        // the observer end-to-end without depending on the full PostingEngine
        // pipeline (which the TenantTestCase's RefreshDatabase doesn't support).

        $customer = \App\Models\Customer::first();
        if (!$customer) {
            $customer = \App\Models\Customer::create([
                'name' => 'Invoice Date Sync Posted Test Customer',
                'email' => fake()->unique()->safeEmail(),
            ]);
        }

        $user = \App\Models\User::first();
        if (!$user) {
            $user = \App\Models\User::factory()->create();
        }

        $originalDate = '2025-01-15';

        // Create the JournalEntry directly
        $je = \App\Models\JournalEntry::create([
            'entry_number' => 'JE-SYNCDATE-TEST-'.uniqid(),
            'entry_date' => $originalDate,
            'description' => 'Test entry for date sync',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $user->id,
            'lifecycle_status' => 'posted',
            'is_active_version' => true,
        ]);

        // Create the invoice with this JE
        $invoice = \App\Models\Invoice::create([
            'customer_id' => $customer->id,
            'invoice_date' => $originalDate,
            'due_date' => '2025-02-15',
            'subtotal' => 100,
            'tax_amount' => 10,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 110,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'is_active_version' => true,
            'version_number' => 1,
            'journal_entry_id' => $je->id,
            'created_by' => $user->id,
        ]);

        // Sanity: should match at start
        $this->assertEquals($originalDate, substr($invoice->invoice_date, 0, 10));
        $this->assertEquals($originalDate, substr($je->fresh()->entry_date, 0, 10));

        // Change invoice date by one month forward (use a date that won't
        // trigger any lock-date validation if it's wired up later).
        $newDate = date('Y-m-d', strtotime($originalDate . ' +1 month'));

        // Use direct update to simulate any path (DB write, future API, etc.)
        $invoice->invoice_date = $newDate;
        $invoice->save();

        // Verify JE was synced by the observer
        $je->refresh();
        $this->assertEquals(
            $newDate,
            substr($je->entry_date, 0, 10),
            'JournalEntry.entry_date must be updated to match new invoice.invoice_date.'
        );

        // Restore original date
        $invoice->invoice_date = $originalDate;
        $invoice->save();
        $je->refresh();
        $this->assertEquals($originalDate, substr($je->entry_date, 0, 10));
    }

    public function test_draft_invoice_date_change_does_not_affect_journal_entries(): void
    {
        // Draft invoices have no JE yet — observer should be a no-op
        $customer = \App\Models\Customer::first();
        if (!$customer) {
            $customer = \App\Models\Customer::create([
                'name' => 'Draft Invoice Test Customer',
                'email' => fake()->unique()->safeEmail(),
            ]);
        }

        $user = \App\Models\User::first();
        if (!$user) {
            $user = \App\Models\User::factory()->create();
        }

        $invoice = \App\Models\Invoice::create([
            'customer_id' => $customer->id,
            'invoice_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'subtotal' => 100,
            'tax_amount' => 10,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 110,
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'is_active_version' => true,
            'version_number' => 1,
            'created_by' => $user->id,
        ]);

        // No JE link
        $this->assertNull($invoice->journal_entry_id);

        // Change date — should not throw, observer should early-return
        $invoice->invoice_date = '2026-03-01';
        $invoice->save();

        // Still no JE
        $invoice->refresh();
        $this->assertNull($invoice->journal_entry_id);
    }

    public function test_invoice_update_without_date_change_does_not_touch_journal_entry(): void
    {
        $customer = \App\Models\Customer::first();
        if (!$customer) {
            $customer = \App\Models\Customer::create([
                'name' => 'No-Date Test Customer',
                'email' => fake()->unique()->safeEmail(),
            ]);
        }

        $user = \App\Models\User::first();
        if (!$user) {
            $user = \App\Models\User::factory()->create();
        }

        $originalDate = '2025-02-15';

        $je = \App\Models\JournalEntry::create([
            'entry_number' => 'JE-SYNCDATE-NODATE-'.uniqid(),
            'entry_date' => $originalDate,
            'description' => 'Test entry for no-date-change sync',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $user->id,
            'lifecycle_status' => 'posted',
            'is_active_version' => true,
        ]);

        $invoice = \App\Models\Invoice::create([
            'customer_id' => $customer->id,
            'invoice_date' => $originalDate,
            'due_date' => '2025-03-15',
            'subtotal' => 100,
            'tax_amount' => 10,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 110,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'is_active_version' => true,
            'version_number' => 1,
            'journal_entry_id' => $je->id,
            'created_by' => $user->id,
        ]);

        $originalJeDate = substr($je->entry_date, 0, 10);

        // Update something other than invoice_date
        $invoice->notes = 'Test update ' . uniqid();
        $invoice->save();

        // JE entry_date must be unchanged
        $je->refresh();
        $this->assertEquals(
            $originalJeDate,
            substr($je->entry_date, 0, 10),
            'JE.entry_date must NOT change when invoice_date is not modified.'
        );
    }
}