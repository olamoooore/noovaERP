<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class InvoiceIdempotencyTest extends TenantTestCase
{
    use RefreshDatabase;

    public function test_invoice_posting_is_idempotent()
    {
        // Setup a tenant context
        $tenant = Tenant::factory()->create(['id' => 'idem-tenant']);
        tenancy()->initialize($tenant);

        $user = User::factory()->create(['name' => 'Idem User']);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['track_inventory' => false]);

        $payload = [
            'customer_id' => $customer->id,
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Idempotent test item',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ];

        // Create invoice (draft)
        $invoice =
            $this->actingAs($user)->postJson('/api/v1/accounting/invoices', $payload)
                ->json();

        // First posting to GL
        $this->actingAs($user)->postJson('/api/v1/accounting/invoices/'.$invoice['id'].'/send')->assertOk();
        // Second posting (should be idempotent)
        $second = $this->actingAs($user)->postJson('/api/v1/accounting/invoices/'.$invoice['id'].'/send');
        $second->assertOk();

        // Verify idempotency at DB level
        $links = JournalEntryLink::where('source_document_id', $invoice['id'])
            ->where('source_document_type', 'invoice')
            ->get();
        $this->assertCount(1, $links, 'Posting should be idempotent and not create multiple links');

        $jeId = $links->first()->journal_entry_id ?? null;
        $this->assertNotNull($jeId, 'There should be a journal entry linked to the posting');
        $this->assertEquals(1, JournalEntry::where('id', $jeId)->count(), 'There should be exactly one journal entry for this posting');
    }
}
