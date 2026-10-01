<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Invoice number editing: a draft invoice may be renumbered, but the number
 * must never duplicate another invoice (soft-deleted ones included), and the
 * change must be recorded on the invoice timeline AND the chatter History
 * feed. Exercises the real router/controller path via putJson.
 */
class InvoiceNumberEditTest extends TenantTestCase
{
    private string $tenantId;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Invoice Number Tenant',
            'email' => 'invoice-number@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenantId,
            'role' => 'owner',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
    }

    private function makeInvoice(string $number, array $overrides = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'customer_id' => $this->customer->id,
            'invoice_number' => $number,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ], $overrides));

        $invoice->lines()->create([
            'line_type' => 'product',
            'description' => 'Renumber widget',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        $invoice->refresh();
        $invoice->calculateTotals();

        return $invoice;
    }

    /** @test */
    public function editing_a_draft_invoice_number_persists_and_is_recorded_in_history(): void
    {
        $invoice = $this->makeInvoice('INV-OLD-9001');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['invoice_number' => 'INV-NEW-9002'])
            ->assertOk();

        $this->assertSame('INV-NEW-9002', $invoice->fresh()->invoice_number);

        // Chatter History feed entry (activity_log) with the from → to pair.
        $log = DB::table('activity_log')
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->where('event', 'number_changed')
            ->first();

        $this->assertNotNull($log, 'Renumbering must be mirrored into the chatter History feed');
        $properties = is_string($log->properties) ? json_decode($log->properties, true) : (array) $log->properties;
        $this->assertSame('INV-OLD-9001', $properties['invoice_number_from'] ?? null);
        $this->assertSame('INV-NEW-9002', $properties['invoice_number_to'] ?? null);

        // Invoice timeline entry keeps the same pair for record purposes.
        $timeline = DB::table('invoice_activity_log')
            ->where('invoice_id', $invoice->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($timeline);
        $metadata = is_string($timeline->metadata) ? json_decode($timeline->metadata, true) : (array) $timeline->metadata;
        $this->assertSame('INV-NEW-9002', $metadata['invoice_number_to'] ?? null);
    }

    /** @test */
    public function a_duplicate_invoice_number_is_rejected(): void
    {
        $existing = $this->makeInvoice('INV-TAKEN-1');
        $target = $this->makeInvoice('INV-FREE-1');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$target->id}", ['invoice_number' => 'INV-TAKEN-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number');

        $this->assertSame('INV-FREE-1', $target->fresh()->invoice_number);
        $this->assertSame('INV-TAKEN-1', $existing->fresh()->invoice_number);
    }

    /** @test */
    public function creating_an_invoice_keeps_the_typed_invoice_number(): void
    {
        // Store used to drop invoice_number from the validated payload, so a
        // hand-typed number (INV-2026-10-001) was silently renumbered to the
        // auto sequence on save — and the PDF then printed the auto number.
        $response = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-2026-10-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Typed number line',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 9,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated();

        $invoice = Invoice::find($response->json('id'));
        $this->assertSame('INV-2026-10-001', $invoice->invoice_number);
    }

    /** @test */
    public function creating_an_invoice_with_a_duplicate_number_is_rejected(): void
    {
        $this->makeInvoice('INV-TAKEN-2');

        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-TAKEN-2',
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Duplicate number line',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_number');
    }

    /** @test */
    public function creating_an_invoice_persists_the_chosen_payment_terms(): void
    {
        $this->customer->update(['payment_terms' => 7]);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'payment_terms' => 30,
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Terms line',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated();

        // The editor's 30-day choice beats the customer's 7-day default.
        $this->assertSame('30', (string) Invoice::find($response->json('id'))->payment_terms_snapshot);
    }

    /** @test */
    public function a_soft_deleted_invoice_number_is_still_reserved(): void
    {
        $deleted = $this->makeInvoice('INV-GONE-1');
        $deleted->delete();

        $target = $this->makeInvoice('INV-FREE-2');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$target->id}", ['invoice_number' => 'INV-GONE-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number');

        $this->assertSame('INV-FREE-2', $target->fresh()->invoice_number);
    }

    /** @test */
    public function posted_invoices_still_cannot_be_edited(): void
    {
        $invoice = $this->makeInvoice('INV-POSTED-1', ['status' => 'posted']);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['invoice_number' => 'INV-RENAMED-1'])
            ->assertForbidden();

        $this->assertSame('INV-POSTED-1', $invoice->fresh()->invoice_number);
    }
}
