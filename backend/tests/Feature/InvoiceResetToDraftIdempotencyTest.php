<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * ADR-005 §15.3 — reversal idempotency across repeated cancel→reset cycles.
 *
 * The composite unique index `journal_entry_links_source_post_unique` on
 * (source_document_type, source_document_id, relation_type) only allows
 * ONE reversal link per source document. Previously, running cancel→reset
 * a second time on the same invoice failed with:
 *
 *   SQLSTATE[23505]: Unique violation:
 *     duplicate key value violates unique constraint
 *     "journal_entry_links_source_post_unique"
 *
 * because createReversalLink() unconditionally created a new reversal
 * JournalEntryLink row without first clearing the prior cycle's stale
 * link. ReversalCorrectionEngine::createReversalLink() now deletes any
 * pre-existing reversal link for the source document before creating
 * the new one (safe because the prior reversal JE is already marked
 * is_active_version=false and inactive).
 */
class InvoiceResetToDraftIdempotencyTest extends TenantTestCase
{
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) \Illuminate\Support\Str::uuid();
        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Reset Idempotency Test',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_reset_to_draft_can_run_multiple_times_for_same_invoice(): void
    {
        $customer = \App\Models\Customer::first();
        if (!$customer) {
            $customer = \App\Models\Customer::create([
                'name' => 'Reset Idempotency Test Customer',
                'email' => fake()->unique()->safeEmail(),
            ]);
        }

        $user = \App\Models\User::first();
        if (!$user) {
            $user = \App\Models\User::factory()->create();
        }

        $controller = app(\App\Http\Controllers\Api\InvoiceController::class);

        // Create a posted invoice with a JE + post link so the reset→reversal
        // path has something to operate on.
        $je1 = \App\Models\JournalEntry::create([
            'entry_number' => 'JE-RESET-IDEMP-1-'.uniqid(),
            'entry_date' => '2025-03-15',
            'description' => 'Reset idempotency test cycle 1',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $user->id,
            'lifecycle_status' => 'posted',
            'is_active_version' => true,
        ]);

        $invoice = \App\Models\Invoice::create([
            'customer_id' => $customer->id,
            'invoice_date' => '2025-03-15',
            'due_date' => '2025-04-15',
            'subtotal' => 100,
            'tax_amount' => 9,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 109,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'is_active_version' => true,
            'version_number' => 1,
            'journal_entry_id' => $je1->id,
            'created_by' => $user->id,
        ]);

        \App\Models\JournalEntryLink::create([
            'source_document_type' => 'invoice',
            'source_document_id' => $invoice->id,
            'journal_entry_id' => $je1->id,
            'original_journal_entry_id' => $je1->id,
            'relation_type' => 'post',
        ]);

        // Cycle 1: cancel → reset
        $resp = $controller->cancel($invoice->id);
        $this->assertSame(200, $resp->getStatusCode(), 'Cancel 1 should succeed');

        $resp = $controller->resetToDraft($invoice->id);
        $this->assertSame(200, $resp->getStatusCode(), 'Reset 1 should succeed');

        $invoice->refresh();
        $this->assertSame('draft', $invoice->status, 'Invoice should be draft after reset');
        $this->assertNull($invoice->journal_entry_id, 'Invoice journal_entry_id should be cleared');

        // Verify first reversal link was created
        $revLinks1 = \App\Models\JournalEntryLink::where('source_document_type', 'invoice')
            ->where('source_document_id', $invoice->id)
            ->where('relation_type', 'reversal')
            ->get();
        $this->assertCount(1, $revLinks1, 'Cycle 1 should leave exactly one reversal link');

        // Set up cycle 2: create a new JE + post link, mark invoice as posted
        $je2 = \App\Models\JournalEntry::create([
            'entry_number' => 'JE-RESET-IDEMP-2-'.uniqid(),
            'entry_date' => '2025-03-15',
            'description' => 'Reset idempotency test cycle 2',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $user->id,
            'lifecycle_status' => 'posted',
            'is_active_version' => true,
        ]);
        $invoice->update(['status' => 'posted', 'journal_entry_id' => $je2->id, 'lifecycle_status' => 'posted']);

        \App\Models\JournalEntryLink::create([
            'source_document_type' => 'invoice',
            'source_document_id' => $invoice->id,
            'journal_entry_id' => $je2->id,
            'original_journal_entry_id' => $je2->id,
            'relation_type' => 'post',
        ]);

        // Cycle 2: cancel → reset — this previously failed with
        // SQLSTATE[23505] unique constraint violation on
        // journal_entry_links_source_post_unique
        $resp = $controller->cancel($invoice->id);
        $this->assertSame(200, $resp->getStatusCode(), 'Cancel 2 should succeed');

        $resp = $controller->resetToDraft($invoice->id);
        $this->assertSame(
            200,
            $resp->getStatusCode(),
            'Reset 2 should succeed — was previously failing with unique violation on '
            .'journal_entry_links_source_post_unique'
        );

        $invoice->refresh();
        $this->assertSame('draft', $invoice->status, 'Invoice should be draft after reset 2');

        // Verify: still exactly one reversal link (the previous one was
        // deleted before the new one was created by the fix).
        $revLinks2 = \App\Models\JournalEntryLink::where('source_document_type', 'invoice')
            ->where('source_document_id', $invoice->id)
            ->where('relation_type', 'reversal')
            ->get();
        $this->assertCount(1, $revLinks2, 'Should still have exactly one reversal link after cycle 2');

        // Cycle 3: another full cancel→reset
        $je3 = \App\Models\JournalEntry::create([
            'entry_number' => 'JE-RESET-IDEMP-3-'.uniqid(),
            'entry_date' => '2025-03-15',
            'description' => 'Reset idempotency test cycle 3',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $user->id,
            'lifecycle_status' => 'posted',
            'is_active_version' => true,
        ]);
        $invoice->update(['status' => 'posted', 'journal_entry_id' => $je3->id, 'lifecycle_status' => 'posted']);

        \App\Models\JournalEntryLink::create([
            'source_document_type' => 'invoice',
            'source_document_id' => $invoice->id,
            'journal_entry_id' => $je3->id,
            'original_journal_entry_id' => $je3->id,
            'relation_type' => 'post',
        ]);

        $resp = $controller->cancel($invoice->id);
        $this->assertSame(200, $resp->getStatusCode());

        $resp = $controller->resetToDraft($invoice->id);
        $this->assertSame(200, $resp->getStatusCode(), 'Reset 3 should succeed — repeating cancel→reset cycles must be idempotent');

        // Final verification
        $links = \App\Models\JournalEntryLink::where('source_document_type', 'invoice')
            ->where('source_document_id', $invoice->id)
            ->get();

        $postLinks = $links->where('relation_type', 'post');
        $reversalLinks = $links->where('relation_type', 'reversal');

        $this->assertCount(0, $postLinks, 'After reset, no active post link should remain');
        $this->assertCount(1, $reversalLinks, 'Exactly one reversal link should remain (no duplicates)');
    }
}