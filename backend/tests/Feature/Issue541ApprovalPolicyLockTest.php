<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Tests\TenantTestCase;

/**
 * Issue #541 (ST4) — the tenant approval policy set in Sales Settings could
 * be switched off document by document: a draft invoice edit accepted
 * requires_approval=false, and a delivery-order create with an explicit
 * false overrode sales.delivery_order.default_requires_approval=true.
 *
 * Router-level: every request goes through auth:sanctum → tenant middleware
 * → RequirePermission → controller.
 */
class Issue541ApprovalPolicyLockTest extends TenantTestCase
{
    private string $tenantId;

    private User $sales;

    /** Invoice edits are accounting writes (EnsureAccountingWriteAccess). */
    private User $accountant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->sales = $this->makeUser('sales', UserRole::SALES);
        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);

        $this->customer = Customer::factory()->create(['credit_limit' => 100000]);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function makeDraftInvoice(array $overrides = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-ST4-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'requires_approval' => true,
            'created_by' => $this->accountant->id,
        ], $overrides));

        $invoice->lines()->create([
            'line_type' => 'product',
            'description' => 'ST4 widget',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        $invoice->refresh();
        $invoice->calculateTotals();

        return $invoice;
    }

    private function deliveryOrderPayload(array $overrides = []): array
    {
        $warehouse = Warehouse::factory()->create(['is_default' => true]);
        $product = Product::factory()->create([
            'sku' => 'ST4-'.uniqid(),
            'price' => 50,
            'cost' => 20,
            'track_inventory' => false,
        ]);

        return array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $warehouse->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $product->id,
                'description' => 'ST4 widget',
                'quantity_delivered' => 1,
                'unit_price' => 50,
            ]],
        ], $overrides);
    }

    public function test_invoice_update_cannot_clear_approval_while_tenant_policy_is_on(): void
    {
        Setting::setValue('sales.invoice.default_requires_approval', true);

        $invoice = $this->makeDraftInvoice();

        $this->actingAs($this->accountant)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['requires_approval' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requires_approval');

        $this->assertTrue((bool) $invoice->fresh()->requires_approval);
    }

    public function test_invoice_update_cannot_clear_a_pending_approval_request(): void
    {
        Setting::setValue('sales.invoice.default_requires_approval', false);

        $invoice = $this->makeDraftInvoice(['approval_status' => Invoice::APPROVAL_PENDING]);

        $this->actingAs($this->accountant)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['requires_approval' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requires_approval');

        $fresh = $invoice->fresh();
        $this->assertTrue((bool) $fresh->requires_approval);
        $this->assertSame(Invoice::APPROVAL_PENDING, $fresh->approval_status);
    }

    public function test_invoice_update_keeps_working_when_the_unchanged_flag_is_echoed(): void
    {
        Setting::setValue('sales.invoice.default_requires_approval', true);

        $invoice = $this->makeDraftInvoice();

        $this->actingAs($this->accountant)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'requires_approval' => true,
                'notes' => 'Edited under the approval policy',
            ])
            ->assertOk();

        $fresh = $invoice->fresh();
        $this->assertTrue((bool) $fresh->requires_approval);
        $this->assertSame('Edited under the approval policy', $fresh->notes);
    }

    public function test_invoice_update_may_clear_approval_when_policy_is_off_and_nothing_is_pending(): void
    {
        Setting::setValue('sales.invoice.default_requires_approval', false);

        $invoice = $this->makeDraftInvoice(['approval_status' => Invoice::APPROVAL_NOT_REQUIRED]);

        $this->actingAs($this->accountant)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['requires_approval' => false])
            ->assertOk();

        $this->assertFalse((bool) $invoice->fresh()->requires_approval);
    }

    public function test_delivery_order_create_with_explicit_false_stays_true_under_the_policy(): void
    {
        Setting::setValue('sales.delivery_order.default_requires_approval', true);

        $response = $this->actingAs($this->sales)
            ->postJson('/api/v1/sales/delivery-orders', $this->deliveryOrderPayload(['requires_approval' => false]));

        $response->assertCreated();

        $deliveryOrder = DeliveryOrder::findOrFail($response->json('data.id'));
        $this->assertTrue((bool) $deliveryOrder->requires_approval);
        $this->assertSame('pending', $deliveryOrder->approval_status);
    }

    public function test_delivery_order_create_may_opt_in_when_the_policy_is_off(): void
    {
        Setting::setValue('sales.delivery_order.default_requires_approval', false);

        $response = $this->actingAs($this->sales)
            ->postJson('/api/v1/sales/delivery-orders', $this->deliveryOrderPayload(['requires_approval' => true]));

        $response->assertCreated();

        $deliveryOrder = DeliveryOrder::findOrFail($response->json('data.id'));
        $this->assertTrue((bool) $deliveryOrder->requires_approval);
        $this->assertSame('pending', $deliveryOrder->approval_status);
    }
}
