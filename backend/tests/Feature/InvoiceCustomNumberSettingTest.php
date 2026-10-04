<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #315: sales.invoice.allow_custom_number is a real gate, not just a settings
 * screen switch. When the tenant disables it, the invoice store route must
 * reject a client-supplied number with a 422 that names the setting (rather
 * than silently renumbering), auto-numbering still works, and a draft edit
 * that submits the unchanged number must keep working (the edit form always
 * echoes the current number back).
 *
 * Exercises the real router/controller path via postJson/putJson.
 */
class InvoiceCustomNumberSettingTest extends TenantTestCase
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
            'name' => 'Custom Number Tenant',
            'email' => 'custom-number@example.com',
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
            'description' => 'Custom number widget',
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
    public function store_rejects_a_custom_number_when_the_setting_is_disabled(): void
    {
        Setting::setValue('sales.invoice.allow_custom_number', false);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-CUSTOM-BLOCKED',
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Blocked custom number',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('invoice_number');
        $this->assertStringContainsString(
            'sales.invoice.allow_custom_number',
            (string) $response->json('errors.invoice_number.0'),
        );
        $this->assertNull(Invoice::where('invoice_number', 'INV-CUSTOM-BLOCKED')->first());
    }

    /** @test */
    public function store_auto_numbers_when_the_setting_is_disabled_and_no_number_is_supplied(): void
    {
        Setting::setValue('sales.invoice.allow_custom_number', false);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Auto numbered',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated();

        $invoice = Invoice::find($response->json('id'));
        $this->assertNotNull($invoice);
        $this->assertNotSame('', trim((string) $invoice->invoice_number));
    }

    /** @test */
    public function store_still_accepts_a_custom_number_when_the_setting_is_enabled(): void
    {
        Setting::setValue('sales.invoice.allow_custom_number', true);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-CUSTOM-ALLOWED',
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Allowed custom number',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated();
        $this->assertSame('INV-CUSTOM-ALLOWED', Invoice::find($response->json('id'))->invoice_number);
    }

    /** @test */
    public function update_rejects_a_renumber_when_the_setting_is_disabled(): void
    {
        Setting::setValue('sales.invoice.allow_custom_number', false);

        $invoice = $this->makeInvoice('INV-KEEP-1');

        $response = $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['invoice_number' => 'INV-RENAMED-1']);

        $response->assertStatus(422)->assertJsonValidationErrors('invoice_number');
        $this->assertStringContainsString(
            'sales.invoice.allow_custom_number',
            (string) $response->json('errors.invoice_number.0'),
        );
        $this->assertSame('INV-KEEP-1', $invoice->fresh()->invoice_number);
    }

    /** @test */
    public function update_allows_the_unchanged_number_when_the_setting_is_disabled(): void
    {
        Setting::setValue('sales.invoice.allow_custom_number', false);

        $invoice = $this->makeInvoice('INV-KEEP-2');

        // The edit form always echoes the current number back; that is not a
        // renumber and must not be rejected.
        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'invoice_number' => 'INV-KEEP-2',
                'notes' => 'Edited while custom numbering is off',
            ])
            ->assertOk();

        $this->assertSame('INV-KEEP-2', $invoice->fresh()->invoice_number);
        $this->assertSame('Edited while custom numbering is off', $invoice->fresh()->notes);
    }
}
