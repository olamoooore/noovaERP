<?php

namespace Tests\Feature;

use Tests\TenantTestCase;
use App\Models\User;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use App\Models\Tenant;
use App\Models\JournalEntryLine;

class InvoicePostingBalancedTest extends TenantTestCase
{
    use RefreshDatabase;

    public function test_invoice_posting_creates_balanced_journal_and_link()
    {
        // Basic setup: tenant and essential entities
        $tenant = Tenant::factory()->create(['id' => 't-invoice-balanced']);
        // Initialize tenancy for this test tenant
        tenancy()->initialize($tenant);
        // Ensure tenancy is active in the test context
        // in case tenancy() helper requires it

        // Tenancy bootstrap (if necessary for tests that touch tenant schema)
        // We rely on existing test harness to bootstrap tenancy; skip manual init here.

        $user = User::factory()->create(['name' => 'Invoice Balancer', 'email' => 'balancer@example.local']);
        $customer = Customer::factory()->create(['name' => 'Balancer Co', 'payment_terms' => 30]);
        $product = Product::factory()->create(['track_inventory' => false, 'name' => 'Balancer Item', 'price' => 100, 'cost' => 60]);

        // Create an invoice draft with a single line that will balance to 100
        $payload = [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'description' => 'Balancer Test Line',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
            ],
        ];

        // Step 1: create draft invoice
        $response = $this->actingAs($user)->postJson('/api/v1/accounting/invoices', $payload);
        $response->assertCreated();
        $invoiceId = $response->json('id');

        // Step 2: post to GL
        $sendResponse = $this->actingAs($user)->postJson('/api/v1/accounting/invoices/' . $invoiceId . '/send');
        $sendResponse->assertOk();

        // Step 3: validate DB state
        $invoice = Invoice::with(['journalEntry', 'lines'])->find($invoiceId);
        $this->assertNotNull($invoice->journalEntry, 'Journal entry should be created on posting');

        $je = $invoice->journalEntry;
        $this->assertEquals('posted', $je->status, 'Journal entry should be posted');
        // The number of GL lines can vary with taxes/discounts; ensure balance and minimum lines
        $this->assertGreaterThanOrEqual(2, $je->lines->count(), 'At least two GL lines required');

        // Balance check: sum of debits equals sum of credits
        $debits = $je->lines->sum(function($l){ return (float) $l->debit; });
        $credits = $je->lines->sum(function($l){ return (float) $l->credit; });
        $this->assertTrue(abs($debits - $credits) < 0.01, "Unbalanced journal: debits=$debits vs credits=$credits");

        // JournalEntryLink post mapping exists
        $link = JournalEntryLink::where('journal_entry_id', $je->id)
            ->where('relation_type', 'post')
            ->first();
        $this->assertNotNull($link, 'JournalEntryLink for post should exist');
        $this->assertEquals('invoice', $link->source_document_type);
        $this->assertEquals((string) $invoice->id, (string) $link->source_document_id);
    }
}
