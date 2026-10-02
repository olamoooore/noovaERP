<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Features\ActivityLogging\Models\ActivityLogEntry;
use App\Models\ApprovalRequest;
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
 * Issue #646 — the approval gate trusted ANY approved request for a document,
 * regardless of the document's current amount/content. A quotation stays
 * `draft` and editable after its approval, so a preparer could get a RM 10k
 * quote approved, raise it to RM 90k, and confirm it without a fresh approval.
 *
 * These are router-level tests (per the repo SOP §3): the request is created
 * and approved through the real HTTP routes, the document is edited through
 * its real update route, and the gate is re-run through the confirm route.
 */
class QuotationApprovalStaleAfterEditTest extends TenantTestCase
{
    protected User $sales;

    protected User $reviewer;

    protected Customer $customer;

    protected Product $product;

    protected Product $replacementProduct;

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
        $this->reviewer = $this->makeUser('manager', UserRole::MANAGER);

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Invalidation Widget',
            'sku' => 'INV-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->replacementProduct = Product::factory()->create([
            'name' => 'Invalidation Widget Mk II',
            'sku' => 'INV2-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);
        Setting::setValue('sales.quotation.reviewer_roles', []);
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

    private function makeDraftQuotation(float $total = 2500): Quotation
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
            'currency_code' => 'MYR',
            'base_currency_code' => 'MYR',
            'exchange_rate' => 1,
            'created_by' => $this->sales->id,
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

    private function approveAt(float $total = 2500): Quotation
    {
        $quotation = $this->makeDraftQuotation($total);

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(202);

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->firstOrFail();

        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $approval->fresh()->status);

        return $quotation;
    }

    // ─────────────────────────────────────────────────────────────────────
    // #646 acceptance: approve at RM 10k, edit to RM 90k → new approval
    // ─────────────────────────────────────────────────────────────────────

    public function test_growing_an_approved_quotation_requires_a_fresh_approval(): void
    {
        $quotation = $this->approveAt(2500);

        $first = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->firstOrFail();

        // Raise the total from 2,500 to 9,000 through the real edit route.
        $this->actingAs($this->sales)
            ->putJson("/api/v1/quotations/{$quotation->id}", [
                'lines' => [[
                    'line_type' => 'product',
                    'product_id' => $this->product->id,
                    'description' => $this->product->name,
                    'quantity' => 90,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ]],
            ])
            ->assertOk();

        $this->assertSame(9000.0, (float) $quotation->fresh()->total);

        // The stale approval must be invalidated, not left standing.
        $this->assertSame('withdrawn', $first->fresh()->status, 'The approval covering the old amount must be invalidated.');

        // ... and the invalidation must be visible in the document's chatter.
        $chatter = ActivityLogEntry::query()
            ->where('subject_type', Quotation::class)
            ->where('subject_id', $quotation->id)
            ->where('event', 'approval_invalidated')
            ->first();

        $this->assertNotNull($chatter, 'Invalidating an approval must leave a chatter entry.');

        // Confirming now must NOT sail through on the old approval: a new
        // request is raised, the quotation stays draft, no order is created.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(202)
            ->assertJsonPath('requires_approval', true);

        $this->assertSame('draft', $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->sales_order_id, 'No SalesOrder may be created without a fresh approval.');

        $this->assertSame(
            2,
            ApprovalRequest::query()
                ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
                ->where('subject_id', $quotation->id)
                ->count(),
            'A second approval request is required for the new amount.'
        );

        $second = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->where('status', 'pending')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(9000.0, (float) $second->amount);

        // Once the fresh request is approved the quotation confirms normally.
        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$second->id}/approve")
            ->assertOk();

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $this->assertSame('confirmed', $quotation->fresh()->status);
        $this->assertNotNull($quotation->fresh()->sales_order_id);
    }

    public function test_editing_a_line_without_changing_the_total_still_requires_a_fresh_approval(): void
    {
        $quotation = $this->approveAt(2500);

        $first = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->firstOrFail();

        // Same quantity and price (same total) but a different product — what
        // was approved is no longer what the document says.
        $this->actingAs($this->sales)
            ->putJson("/api/v1/quotations/{$quotation->id}", [
                'lines' => [[
                    'line_type' => 'product',
                    'product_id' => $this->replacementProduct->id,
                    'description' => $this->replacementProduct->name,
                    'quantity' => 25,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ]],
            ])
            ->assertOk();

        $this->assertSame(2500.0, (float) $quotation->fresh()->total);
        $this->assertSame('withdrawn', $first->fresh()->status);

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(202)
            ->assertJsonPath('requires_approval', true);

        $this->assertSame('draft', $quotation->fresh()->status);
    }

    public function test_editing_non_approval_fields_keeps_the_approval_valid(): void
    {
        $quotation = $this->approveAt(2500);

        $first = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->firstOrFail();

        $this->actingAs($this->sales)
            ->putJson("/api/v1/quotations/{$quotation->id}", [
                'notes' => 'Updated internal note only',
            ])
            ->assertOk();

        $this->assertSame('approved', $first->fresh()->status, 'A non-approval edit must not invalidate the approval.');

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $this->assertSame('confirmed', $quotation->fresh()->status);
        $this->assertSame(
            1,
            ApprovalRequest::query()
                ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
                ->where('subject_id', $quotation->id)
                ->count(),
            'An unchanged, approved document must reuse its request.'
        );
    }
}
