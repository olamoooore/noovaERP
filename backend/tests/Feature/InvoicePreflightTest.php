<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * D7 (Oct-2026 invoice review): GET /invoices/{id}/preflight powers the badge
 * on Post. It must report ready/passed/total plus a per-check status, warn
 * about a duplicate and a missing customer TIN, and fail when approval is
 * still pending.
 */
class InvoicePreflightTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Preflight Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function invoiceFor(Customer $customer, array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'currency_code' => null,
            'exchange_rate' => 1,
            'total' => 100,
            'balance' => 100,
            'requires_approval' => false,
        ], $attributes));
    }

    private function addLine(Invoice $invoice): void
    {
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => Product::factory()->create(['track_inventory' => false])->id,
            'description' => 'Service fee',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => 100,
            'tax_amount' => 0,
        ]);
    }

    public function test_preflight_reports_ready_with_a_tin_warning(): void
    {
        $customer = Customer::factory()->create(['legal_entity_id' => null, 'tax_number' => null]);
        $invoice = $this->invoiceFor($customer);
        $this->addLine($invoice);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/preflight")
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('total', 8);

        $checks = collect($response->json('checks'))->keyBy('key');

        $this->assertSame('warn', $checks['e_invoice']['status'], 'Missing customer TIN must warn.');
        $this->assertSame('pass', $checks['approval']['status']);
        $this->assertSame('pass', $checks['period']['status']);
        $this->assertSame('pass', $checks['duplicate']['status']);
        $this->assertSame('pass', $checks['credit']['status']);
        $this->assertSame('pass', $checks['stock']['status']);
    }

    public function test_preflight_fails_when_approval_is_pending_and_warns_on_duplicate(): void
    {
        $customer = Customer::factory()->create(['legal_entity_id' => null, 'tax_number' => 'TIN-123']);
        $invoice = $this->invoiceFor($customer, [
            'requires_approval' => true,
            'approval_status' => 'pending',
        ]);
        $this->addLine($invoice);

        // A twin invoice (same customer, same total, same week) must surface
        // as a possible duplicate.
        $twin = $this->invoiceFor($customer);
        $this->addLine($twin);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/preflight")
            ->assertOk()
            ->assertJsonPath('ready', false);

        $checks = collect($response->json('checks'))->keyBy('key');

        $this->assertSame('fail', $checks['approval']['status'], 'Pending approval must fail the checklist.');
        $this->assertSame('warn', $checks['duplicate']['status'], 'A same-amount invoice in the same week must warn.');
        $this->assertStringContainsString($twin->invoice_number, $checks['duplicate']['detail']);
        $this->assertSame('pass', $checks['e_invoice']['status'], 'A TIN on file must pass.');
    }
}
