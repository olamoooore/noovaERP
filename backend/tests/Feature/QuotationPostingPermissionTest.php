<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Confirming a quotation is the sell-side document release action (it creates
 * and confirms the SalesOrder) and follows the same Segregation-of-Duties rule
 * as invoice posting: Owner/Admin (implicit) + Accountant only, via
 * `accounting.post`. Sales and Manager prepare documents but cannot confirm.
 *
 * Exercises the real router/middleware/controller chain via postJson.
 */
class QuotationPostingPermissionTest extends TenantTestCase
{
    protected User $sales;

    protected User $manager;

    protected User $accountant;

    protected Customer $customer;

    protected Product $product;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });

        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->sales = $this->makeUser('sales', UserRole::SALES);
        $this->manager = $this->makeUser('manager', UserRole::MANAGER);
        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Quotation RBAC Widget',
            'sku' => 'QRBAC-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        // No approval threshold: confirm proceeds straight through.
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
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

    private function makeDraftQuotation(float $total = 500): Quotation
    {
        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-'.uniqid(),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'created_by' => $this->accountant->id,
        ]);

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => (int) ceil($total / 100),
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => $total,
        ]);

        return $quotation;
    }

    public function test_sales_cannot_confirm_a_quotation(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(403);

        $this->assertSame('draft', $quotation->fresh()->status);
    }

    public function test_manager_cannot_confirm_a_quotation(): void
    {
        $quotation = $this->makeDraftQuotation();

        // Managers lost accounting.post with the SoD split — they plan, they
        // don't release documents to the ledger.
        $this->actingAs($this->manager)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(403);

        $this->assertSame('draft', $quotation->fresh()->status);
    }

    public function test_accountant_can_confirm_a_quotation(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNotNull($quotation->sales_order_id);
    }
}
