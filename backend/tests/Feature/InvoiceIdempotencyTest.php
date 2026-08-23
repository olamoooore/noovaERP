<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class InvoiceIdempotencyTest extends TenantTestCase
{
    public function test_invoice_posting_is_idempotent()
    {
        // Setup a tenant context. We use withoutEvents to skip the
        // TenantCreated pipeline (CreateDatabase + MigrateDatabase) because
        // TenantTestCase already populated the public schema with the same
        // tables and re-running migrations would race with the in-progress
        // test transaction.
        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => '11111111-1111-1111-1111-111111111111']);
        });
        $this->bindTenantContext($tenant->id);

        $user = User::factory()->create(['name' => 'Idem User']);

        // Grant tenant-scoped accounting write access for this user.
        // The accounting middleware (EnsureAccountingWriteAccess) consults
        // user_company_access when the user is not a Spatie super-admin.
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
        $response = $this->actingAs($user)->postJson('/api/v1/invoices', $payload);
        $response->assertCreated();
        $invoice = $response->json();

        // First posting to GL
        $this->actingAs($user)->postJson('/api/v1/invoices/'.$invoice['id'].'/send')->assertOk();
        // Second posting (should be idempotent)
        $second = $this->actingAs($user)->postJson('/api/v1/invoices/'.$invoice['id'].'/send');
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
