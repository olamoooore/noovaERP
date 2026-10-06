<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
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
 * Issue #540 (ST3) — the invoice and delivery-order create pages read the
 * tenant's document defaults from GET /settings/sales, which is gated by
 * settings.view (OWNER/ADMIN only). Every other role got a 403, so default
 * notes/terms and the approval policy were silently skipped and the DO page
 * sent requires_approval=false explicitly.
 *
 * The fix is a read-only GET /settings/sales/defaults gated by
 * sales.view|invoices.write that returns only the document defaults; the
 * full settings endpoint stays OWNER/ADMIN-only.
 *
 * Router-level: every request goes through auth:sanctum → tenant middleware
 * → RequirePermission → controller.
 */
class Issue540SalesDocumentDefaultsReadTest extends TenantTestCase
{
    private string $tenantId;

    private User $sales;

    private User $accountant;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->sales = $this->makeUser('sales', UserRole::SALES);
        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);
        $this->cashier = $this->makeUser('cashier', UserRole::CASHIER);

        Setting::setValue('sales.invoice.default_requires_approval', true);
        Setting::setValue('sales.invoice.default_notes', 'Thank you for your business.');
        Setting::setValue('sales.invoice.default_terms', 'Net 14.');
        Setting::setValue('sales.invoice.default_payment_terms_days', 14);
        Setting::setValue('sales.invoice.field_profile', 'trading');
        Setting::setValue('sales.invoice.allow_custom_number', false);
        Setting::setValue('sales.delivery_order.default_requires_approval', true);
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

    public function test_full_sales_settings_stay_locked_to_settings_view(): void
    {
        $this->actingAs($this->sales)
            ->getJson('/api/v1/settings/sales')
            ->assertForbidden();
    }

    public function test_sales_user_reads_the_document_defaults(): void
    {
        $response = $this->actingAs($this->sales)->getJson('/api/v1/settings/sales/defaults');

        $response->assertOk()
            ->assertJsonPath('data.invoice.default_requires_approval', true)
            ->assertJsonPath('data.invoice.default_notes', 'Thank you for your business.')
            ->assertJsonPath('data.invoice.default_terms', 'Net 14.')
            ->assertJsonPath('data.invoice.default_payment_terms_days', 14)
            ->assertJsonPath('data.invoice.field_profile', 'trading')
            ->assertJsonPath('data.invoice.allow_custom_number', false)
            ->assertJsonPath('data.delivery_order.default_requires_approval', true);

        // Only document defaults — no reviewer pools, user directory or
        // other administrative settings leak through the read path.
        $this->assertArrayNotHasKey('reviewer_user_ids', $response->json('data.invoice'));
        $this->assertArrayNotHasKey('available_reviewers', $response->json('data.invoice'));
        $this->assertArrayNotHasKey('reviewer_user_ids', $response->json('data.delivery_order'));
        $this->assertArrayNotHasKey('portal', $response->json('data'));
        $this->assertArrayNotHasKey('cash_rounding', $response->json('data'));
    }

    public function test_accountant_with_invoices_write_reads_the_document_defaults(): void
    {
        $this->actingAs($this->accountant)
            ->getJson('/api/v1/settings/sales/defaults')
            ->assertOk()
            ->assertJsonPath('data.invoice.default_requires_approval', true);
    }

    public function test_role_without_sales_view_or_invoices_write_is_refused(): void
    {
        $this->actingAs($this->cashier)
            ->getJson('/api/v1/settings/sales/defaults')
            ->assertForbidden();
    }

    public function test_sales_user_delivery_order_honours_the_tenant_approval_default(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => 100000]);
        $warehouse = Warehouse::factory()->create(['is_default' => true]);
        $product = Product::factory()->create([
            'sku' => 'ST3-'.uniqid(),
            'price' => 50,
            'cost' => 20,
            'track_inventory' => false,
        ]);

        // The payload the create page sends: no requires_approval — the
        // server applies the tenant default.
        $response = $this->actingAs($this->sales)->postJson('/api/v1/sales/delivery-orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $product->id,
                'description' => 'ST3 widget',
                'quantity_delivered' => 2,
                'unit_price' => 50,
            ]],
        ]);

        $response->assertCreated();

        $deliveryOrder = DeliveryOrder::findOrFail($response->json('data.id'));
        $this->assertTrue((bool) $deliveryOrder->requires_approval);
        $this->assertSame('pending', $deliveryOrder->approval_status);
    }
}
