<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Lifecycle guards on the quotation → Sales Order link.
 *
 * #292: reset-to-draft must cancel (or refuse to orphan) the live Sales Order,
 *       so re-confirming never leaves a second active order.
 * #294: bulk delete must apply the same rule as single delete — draft only —
 *       so confirmed quotations (which own a live order) are never removed.
 *
 * Exercises the real router/middleware/controller chain via postJson.
 */
class QuotationLifecycleGuardsTest extends TenantTestCase
{
    protected User $admin;

    protected Customer $customer;

    protected Product $product;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = $this->makeUser('admin', UserRole::ADMIN);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->product = Product::factory()->create([
            'name' => 'Lifecycle Widget',
            'sku' => 'LCW-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        // No approval threshold — confirm/invoice proceed straight through.
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Warehouse::factory()->create(['is_default' => true]);
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
            'created_by' => $this->admin->id,
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

    private function activeOrderCount(): int
    {
        return (int) DB::table('sales_orders')
            ->where('customer_id', $this->customer->id)
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    public function test_reset_to_draft_cancels_the_linked_order_and_reconfirm_leaves_one_active_order(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $firstOrderId = $quotation->fresh()->sales_order_id;
        $this->assertNotNull($firstOrderId);
        $this->assertSame(1, $this->activeOrderCount());

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/reset-to-draft")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('draft', $quotation->status);
        $this->assertNull($quotation->sales_order_id);
        $this->assertSame(
            'cancelled',
            DB::table('sales_orders')->where('id', $firstOrderId)->value('status'),
            'The previously linked Sales Order must be cancelled, not left active.',
        );
        $this->assertSame(0, $this->activeOrderCount(), 'No active order should remain after reset.');

        // Re-confirm creates a fresh order — and only one is active.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $secondOrderId = $quotation->fresh()->sales_order_id;
        $this->assertNotNull($secondOrderId);
        $this->assertNotSame($firstOrderId, $secondOrderId);
        $this->assertSame(1, $this->activeOrderCount(), 'Exactly one active order after confirm → reset → confirm.');
    }

    public function test_reset_to_draft_is_refused_once_the_order_is_invoiced(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();
        $orderId = $quotation->fresh()->sales_order_id;

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        // The order is now invoiced and can no longer be cancelled — reset is
        // refused rather than silently orphaning an invoiced order.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/reset-to-draft")
            ->assertStatus(422);

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status, 'Quotation must stay confirmed when reset is refused.');
        $this->assertSame($orderId, $quotation->sales_order_id, 'The Sales Order link must be preserved.');
        $this->assertNotSame('cancelled', DB::table('sales_orders')->where('id', $orderId)->value('status'));
    }

    public function test_bulk_delete_only_removes_draft_quotations(): void
    {
        $draft = $this->makeDraftQuotation();

        $confirmed = $this->makeDraftQuotation();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$confirmed->id}/confirm")
            ->assertOk();
        $this->assertSame('confirmed', $confirmed->fresh()->status);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/quotations/bulk-delete', ['ids' => [$draft->id, $confirmed->id]])
            ->assertOk();

        $this->assertSame([$draft->id], $response->json('deleted'));
        $skippedIds = collect($response->json('skipped'))->pluck('id')->all();
        $this->assertContains($confirmed->id, $skippedIds, 'A confirmed quotation must be skipped.');

        $this->assertNull(Quotation::find($draft->id), 'Draft quotation should be deleted.');
        $survivor = Quotation::find($confirmed->id);
        $this->assertNotNull($survivor, 'Confirmed quotation must survive bulk delete.');
        $this->assertSame('confirmed', $survivor->status);
    }
}
