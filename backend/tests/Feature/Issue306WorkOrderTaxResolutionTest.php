<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxCountryPackService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FieldService\Models\TimeLog;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\WorkOrderPart;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * #306 (X6): converting a Field Service work order to a Sales Order hard-coded
 * `tax_rate => 0` on labour, parts and the fallback line, so MY service tax /
 * SG GST was never charged on work-order invoices.
 *
 * Every assertion runs through the real HTTP route the Field Service UI uses —
 * POST /api/v1/field-service/work-orders/{id}/invoice (router → middleware →
 * controller → SalesOrderService::createFromWorkOrder) — and only ever expects
 * rates declared by the activated country pack, never an invented one.
 */
class Issue306WorkOrderTaxResolutionTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $this->tenantId]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'field_service_work_orders', 'fs_time_logs', 'fs_work_order_parts',
            'sales_orders', 'sales_order_lines', 'invoices', 'invoice_lines',
        ]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create([
            'email' => Str::lower(Str::random(3)).'-'.uniqid().'@issue306.test',
        ]);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->grantFeature('field_service');
    }

    /**
     * MY-SVC-8 (8%) is in force 2025-07-01 → 2025-12-31. Travelling to a date
     * inside that window keeps the assertion anchored to a rate the pack
     * actually declares (config/tax_packs.php) — no statutory data is invented
     * to make the test pass.
     */
    private const MY_SERVICE_TAX_DATE = '2025-08-15';

    public function test_work_order_labour_line_resolves_my_service_tax_through_the_engine(): void
    {
        $this->travelTo(self::MY_SERVICE_TAX_DATE.' 10:00:00');
        app(TaxCountryPackService::class)->activate('MY');

        $expectedServiceRate = $this->packRuleRate('MY', 'service', self::MY_SERVICE_TAX_DATE);
        $this->assertGreaterThan(0.0, $expectedServiceRate,
            'The MY pack must declare a service tax rule in force on the test date.');

        $customer = Customer::factory()->create(['payment_terms' => 30]);
        $workOrder = $this->completedWorkOrder($customer);

        TimeLog::create([
            'work_order_id' => $workOrder->id,
            'user_id' => $this->user->id,
            'description' => 'On-site diagnostics',
            'date' => self::MY_SERVICE_TAX_DATE,
            'duration_hours' => 2,
            'is_billable' => true,
            'hourly_rate' => 150,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/field-service/work-orders/{$workOrder->id}/invoice");

        $response->assertCreated();

        $order = SalesOrder::with('lines')->findOrFail($workOrder->fresh()->sales_order_id);
        $labourLine = $order->lines->firstWhere('source_line_type', 'WorkOrderTimeLog');
        $this->assertNotNull($labourLine, 'The work-order time log must convert to a sales-order line.');

        $this->assertSame(
            $expectedServiceRate,
            (float) $labourLine->tax_rate,
            'Field Service labour must resolve MY service tax through the engine, not hard-code 0%.',
        );

        $invoice = Invoice::with('lines')->findOrFail((int) $response->json('id'));
        $invoiceLine = $invoice->lines->firstWhere('sales_order_line_id', $labourLine->id);
        $this->assertNotNull($invoiceLine, 'The converted labour line must be billed on the work-order invoice.');
        $this->assertSame($expectedServiceRate, (float) $invoiceLine->tax_rate);
        $this->assertSame(
            round(300.0 * $expectedServiceRate / 100, 2),
            (float) $invoiceLine->tax_amount,
            'The service tax the pack declares must be charged on the labour amount.',
        );
    }

    public function test_work_order_part_line_resolves_the_products_tax_rule(): void
    {
        $this->travelTo(self::MY_SERVICE_TAX_DATE.' 10:00:00');
        app(TaxCountryPackService::class)->activate('MY');

        // Pin the part's product to an activated pack rule so "parts by
        // product" is asserted against known rule data.
        $salesRule = Tax::query()->where('code', 'MY-SALES-10')->firstOrFail();
        $expectedPartRate = (float) $salesRule->rate;
        $this->assertGreaterThan(0.0, $expectedPartRate);

        $customer = Customer::factory()->create(['payment_terms' => 30]);
        $product = Product::factory()->create([
            'name' => 'Compressor',
            'type' => 'product',
            'track_inventory' => false,
        ]);
        $product->update(['tax_id' => $salesRule->id]);

        $workOrder = $this->completedWorkOrder($customer);
        WorkOrderPart::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'description' => 'Compressor',
            'quantity' => 2,
            'unit_price' => 200,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/field-service/work-orders/{$workOrder->id}/invoice");

        $response->assertCreated();

        $order = SalesOrder::with('lines')->findOrFail($workOrder->fresh()->sales_order_id);
        $partLine = $order->lines->firstWhere('source_line_type', 'WorkOrderPart');
        $this->assertNotNull($partLine, 'The work-order part must convert to a sales-order line.');

        $this->assertSame(
            $expectedPartRate,
            (float) $partLine->tax_rate,
            'Field Service parts must resolve the product tax rule through the engine, not hard-code 0%.',
        );

        $invoice = Invoice::with('lines')->findOrFail((int) $response->json('id'));
        $invoiceLine = $invoice->lines->firstWhere('sales_order_line_id', $partLine->id);
        $this->assertNotNull($invoiceLine);
        $this->assertSame($expectedPartRate, (float) $invoiceLine->tax_rate);
        $this->assertSame(round(400.0 * $expectedPartRate / 100, 2), (float) $invoiceLine->tax_amount);
    }

    private function completedWorkOrder(Customer $customer): WorkOrder
    {
        return WorkOrder::create([
            'reference' => 'FS-'.Str::upper(Str::random(8)),
            'title' => 'Field Service Visit',
            'status' => 'completed',
            'priority' => 'normal',
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
            'completed_at' => now(),
        ]);
    }

    /**
     * The rate the activated pack declares for a line type on the given date.
     * Reads config/tax_packs.php (the pack data source), so the expected value
     * can never drift from the reviewed statutory data.
     */
    private function packRuleRate(string $jurisdiction, string $type, string $date): float
    {
        $rule = collect(config("tax_packs.{$jurisdiction}.rules"))
            ->filter(fn (array $rule) => strtolower((string) ($rule['type'] ?? '')) === $type)
            ->first(function (array $rule) use ($date) {
                $from = $rule['effective_from'] ?? null;
                $to = $rule['effective_to'] ?? null;

                return ($from === null || $from <= $date)
                    && ($to === null || $to >= $date);
            });

        $this->assertNotNull($rule, "The {$jurisdiction} pack must define a {$type} rule in force on {$date}.");

        return (float) $rule['rate'];
    }

    /**
     * The route's `entitlement:field_service` middleware reads the canonical
     * tenant_entitlements snapshot — grant the module the same way SuperAdmin
     * provisioning does.
     */
    private function grantFeature(string $key): void
    {
        $typeId = DB::table('public.catalog_item_types')->where('key', 'module')->value('id')
            ?? DB::table('public.catalog_item_types')->insertGetId([
                'key' => 'module', 'name' => 'Module', 'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $itemId = DB::table('public.catalog_items')->where('key', $key)->value('id')
            ?? DB::table('public.catalog_items')->insertGetId([
                'key' => $key, 'name' => ucfirst($key), 'type_id' => $typeId, 'is_active' => true,
                'effective_from' => now()->toDateString(), 'source' => 'catalog',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('public.tenant_entitlements')->updateOrInsert(
            ['tenant_id' => $this->tenantId, 'catalog_item_id' => $itemId],
            ['is_entitled' => true, 'source' => 'override_include', 'resolved_at' => now(),
                'created_at' => now(), 'updated_at' => now()],
        );

        app(\App\SuperAdmin\Application\FeatureGateService::class)->invalidate($this->tenantId);
    }
}
