<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Platform\Workflow\WorkflowDeniedException;
use App\Services\InvoiceService;
use App\Services\SalesApprovalGate;
use App\Services\SalesWorkflowService;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderRecurrence;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #900 (PC6) — every sales status transition now goes through the shared
 * WorkflowEngine: schemas with guards/handlers, a transition log, and the
 * audit-trail feed (#895).
 *
 * These tests drive the REAL routes (router → middleware → controller) for
 * invoice post/cancel/reset, quotation confirm/cancel, sales-order
 * confirm/cancel/reset, delivery-order confirm/complete/reset and recurrence
 * pause/resume/cancel, and assert the engine's transition log + the audit
 * trail. The static companion test (SalesStatusLiteralWritesStaticTest)
 * enforces that the guarded files carry no raw status writes.
 */
class SalesWorkflowEngineTransitionsTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Workflow Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => UserRole::ADMIN->value,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
            ]
        );

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Workflow Warehouse',
            'code' => 'WW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Workflow Widget',
            'sku' => 'WW-'.uniqid(),
            'track_inventory' => true,
            'cost' => 10,
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key,
            'is_entitled' => $isEntitled,
            'source' => $isEntitled ? 'tier_included' : null,
            'name' => strtoupper($key),
        ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    private function apiPost(string $uri, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson($uri, $payload);
    }

    private function draftDirectInvoice(float $quantity, float $taxRate = 0): Invoice
    {
        $response = $this->apiPost('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 25,
                'tax_rate' => $taxRate,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        return Invoice::query()->findOrFail((int) $response->json('id'));
    }

    private function draftQuotation(): Quotation
    {
        $response = $this->apiPost('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 2,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        return Quotation::query()->findOrFail((int) $response->json('data.id'));
    }

    private function draftSalesOrder(): SalesOrder
    {
        $response = $this->apiPost('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 2,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();

        $order = SalesOrder::query()->findOrFail((int) $response->json('id'));
        $order->update(['warehouse_id' => $this->warehouse->id]);

        return $order;
    }

    private function workflowLog(string $entityType, int $entityId, string $action): ?object
    {
        return DB::table('workflow_logs')
            ->where('entity_type', $entityType)
            ->where('entity_id', (string) $entityId)
            ->where('action', $action)
            ->where('result', 'applied')
            ->latest('id')
            ->first();
    }

    private function assertWorkflowApplied(string $entityType, int $entityId, string $action, ?string $old = null, ?string $new = null): void
    {
        $log = $this->workflowLog($entityType, $entityId, $action);

        $this->assertNotNull($log, "workflow_logs must contain {$entityType}#{$entityId} action '{$action}'");

        if ($old !== null) {
            $this->assertSame($old, $log->old_status);
        }

        if ($new !== null) {
            $this->assertSame($new, $log->new_status);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Invoice
    // ═════════════════════════════════════════════════════════════════════

    public function test_invoice_post_cancel_reset_are_engine_owned_and_audited(): void
    {
        $invoice = $this->draftDirectInvoice(2, 10);

        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();
        $invoice->refresh();
        $this->assertSame('posted', $invoice->status);
        $this->assertSame('posted', $invoice->lifecycle_status);
        $this->assertWorkflowApplied('invoice', $invoice->id, 'post', 'draft', 'posted');

        $this->apiPost("/api/v1/invoices/{$invoice->id}/cancel")->assertOk();
        $invoice->refresh();
        $this->assertSame('cancelled', $invoice->status);
        $this->assertSame('cancelled', $invoice->lifecycle_status);
        $this->assertWorkflowApplied('invoice', $invoice->id, 'cancel', 'posted', 'cancelled');

        $this->apiPost("/api/v1/invoices/{$invoice->id}/reset-to-draft")->assertOk();
        $invoice->refresh();
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('draft', $invoice->lifecycle_status);
        $this->assertNull($invoice->journal_entry_id);
        $this->assertWorkflowApplied('invoice', $invoice->id, 'reset_to_draft', 'cancelled', 'draft');

        // #895: the transition log feeds the document audit trail.
        $this->assertSame(
            3,
            DB::table('audit_logs')
                ->where('reference_type', 'invoice')
                ->where('reference_id', $invoice->id)
                ->whereIn('operation', ['workflow_post', 'workflow_cancel', 'workflow_reset_to_draft'])
                ->count(),
            'post + cancel + reset transitions must appear on the audit trail'
        );

        $this->actingAs($this->admin)
            ->getJson("/api/v1/invoices/{$invoice->id}/audit")
            ->assertOk()
            ->assertSee('workflow_post');
    }

    public function test_invoice_settlement_status_change_is_engine_logged(): void
    {
        $invoice = $this->draftDirectInvoice(2);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        app(InvoiceService::class)->applyAllocatedPayment($invoice->fresh(), 10.0);

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);
        $this->assertSame('posted', $invoice->lifecycle_status, 'Settlement must not touch the posting lifecycle');
        $this->assertWorkflowApplied('invoice', $invoice->id, 'sync_partial', 'posted', 'partial');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Quotation
    // ═════════════════════════════════════════════════════════════════════

    public function test_quotation_confirm_and_cancel_are_engine_owned(): void
    {
        $quotation = $this->draftQuotation();

        $this->apiPost("/api/v1/quotations/{$quotation->id}/confirm")->assertOk();
        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertWorkflowApplied('quotation', $quotation->id, 'confirm', 'draft', 'confirmed');

        $orderId = (int) $quotation->sales_order_id;
        $this->assertGreaterThan(0, $orderId);
        $this->assertSame('confirmed', SalesOrder::query()->findOrFail($orderId)->status);

        // The engine's cancel handler cancels the linked sales order first.
        $this->apiPost("/api/v1/quotations/{$quotation->id}/cancel")->assertOk();
        $quotation->refresh();
        $this->assertSame('cancelled', $quotation->status);
        $this->assertWorkflowApplied('quotation', $quotation->id, 'cancel', 'confirmed', 'cancelled');
        $this->assertSame('cancelled', SalesOrder::query()->findOrFail($orderId)->status);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Sales order
    // ═════════════════════════════════════════════════════════════════════

    public function test_sales_order_lifecycle_is_engine_owned(): void
    {
        $order = $this->draftSalesOrder();

        $this->apiPost("/api/v1/sales/orders/{$order->id}/confirm")->assertOk();
        $this->assertWorkflowApplied('sales_order', $order->id, 'confirm', 'draft', 'confirmed');

        $this->apiPost("/api/v1/sales/orders/{$order->id}/cancel", ['reason' => 'Engine test cancellation'])->assertOk();
        $this->assertWorkflowApplied('sales_order', $order->id, 'cancel', 'confirmed', 'cancelled');

        $this->apiPost("/api/v1/sales/orders/{$order->id}/reset-to-draft")->assertOk();
        $order->refresh();
        $this->assertSame('draft', $order->status);
        $this->assertWorkflowApplied('sales_order', $order->id, 'reset_to_draft', 'cancelled', 'draft');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Delivery order
    // ═════════════════════════════════════════════════════════════════════

    public function test_delivery_order_lifecycle_is_engine_owned(): void
    {
        $order = $this->draftSalesOrder();
        $this->apiPost("/api/v1/sales/orders/{$order->id}/confirm")->assertOk();

        $order->refresh();
        $lineId = (int) $order->lines()->firstOrFail()->id;

        $create = $this->apiPost('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'sales_order_line_id' => $lineId,
                'quantity_delivered' => 2,
            ]],
        ]);
        $create->assertCreated();
        $deliveryOrderId = (int) $create->json('data.id');

        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/confirm")->assertOk();
        $this->assertWorkflowApplied('delivery_order', $deliveryOrderId, 'confirm', 'draft', 'confirmed');

        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")->assertOk();
        $this->assertWorkflowApplied('delivery_order', $deliveryOrderId, 'complete', 'confirmed', 'done');

        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/reset-to-draft")->assertOk();
        $deliveryOrder = DeliveryOrder::query()->findOrFail($deliveryOrderId);
        $this->assertSame('draft', $deliveryOrder->status);
        // done → cancelled (canonical reversal) → draft.
        $this->assertWorkflowApplied('delivery_order', $deliveryOrderId, 'reverse', 'done', 'cancelled');
        $this->assertWorkflowApplied('delivery_order', $deliveryOrderId, 'reset_to_draft', 'cancelled', 'draft');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Recurrence
    // ═════════════════════════════════════════════════════════════════════

    public function test_recurrence_lifecycle_is_engine_owned(): void
    {
        $order = $this->draftSalesOrder();

        $create = $this->apiPost("/api/v1/sales/orders/{$order->id}/recurrence", [
            'interval' => 'monthly',
            'start_date' => now()->addMonth()->toDateString(),
            'max_cycles' => 2,
        ]);
        $create->assertCreated();
        $recurrenceId = (int) $create->json('data.id');
        $this->assertSame('active', SalesOrderRecurrence::query()->findOrFail($recurrenceId)->status);
        $this->assertWorkflowApplied('sales_order_recurrence', $recurrenceId, 'initialize', null, 'active');

        $this->apiPost("/api/v1/sales/recurrences/{$recurrenceId}/pause")->assertOk();
        $this->assertWorkflowApplied('sales_order_recurrence', $recurrenceId, 'pause', 'active', 'paused');

        $this->apiPost("/api/v1/sales/recurrences/{$recurrenceId}/resume")->assertOk();
        $this->assertWorkflowApplied('sales_order_recurrence', $recurrenceId, 'resume', 'paused', 'active');

        $this->apiPost("/api/v1/sales/recurrences/{$recurrenceId}/cancel")->assertOk();
        $this->assertWorkflowApplied('sales_order_recurrence', $recurrenceId, 'cancel', 'active', 'cancelled');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Enforcement
    // ═════════════════════════════════════════════════════════════════════

    public function test_undefined_transition_is_rejected_and_leaves_status_untouched(): void
    {
        $invoice = $this->draftDirectInvoice(1);
        $workflow = app(SalesWorkflowService::class);

        try {
            $workflow->transition($invoice, 'cancel');
            $this->fail('Cancelling a draft invoice through the engine must be denied.');
        } catch (WorkflowDeniedException $e) {
            $this->assertStringContainsString('cancel', $e->getMessage());
        }

        $this->assertSame('draft', $invoice->fresh()->status, 'A denied transition must not mutate the document');

        $rejected = DB::table('workflow_logs')
            ->where('entity_type', 'invoice')
            ->where('entity_id', (string) $invoice->id)
            ->where('action', 'cancel')
            ->where('result', 'rejected')
            ->latest('id')
            ->first();

        $this->assertNotNull($rejected, 'A denied transition must be recorded on the workflow log');
    }
}
