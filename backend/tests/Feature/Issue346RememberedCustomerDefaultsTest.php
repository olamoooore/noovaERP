<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #346 ([SALES-DIFF] D9): per-customer remembered choices.
 *
 * Acceptance criteria:
 *  - A repeat customer's next document is pre-filled with the warehouse,
 *    contacts and notes used last time, derived on the server from the
 *    customer's most recent document (not browser storage), with a visible
 *    "from last …" hint (the defaults endpoint names the source document).
 *  - Never overrides values the user has explicitly set.
 *  - Remembered values respect the legal entity: nothing is carried from a
 *    document (or a contact) of another entity.
 *
 * Everything runs through the real HTTP routes (router → middleware →
 * controller → service).
 */
class Issue346RememberedCustomerDefaultsTest extends TenantTestCase
{
    private User $admin;

    private Product $product;

    private Product $stockProduct;

    private Warehouse $defaultWarehouse;

    private Warehouse $dockWarehouse;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantId = (string) $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId,
            'name' => 'Issue 346 Remembered Defaults Tenant',
            'email' => $this->tenantId.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create(['name' => 'Remembering Seller']);
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $this->product = Product::factory()->create([
            'name' => 'Issue 346 Service',
            'sku' => 'I346-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 10,
            'track_inventory' => false,
        ]);
        $this->stockProduct = Product::factory()->create([
            'name' => 'Issue 346 Stocked Widget',
            'sku' => 'I346S-'.Str::upper(Str::random(6)),
            'price' => 50,
            'cost' => 10,
            'track_inventory' => true,
        ]);

        $this->defaultWarehouse = Warehouse::factory()->create(['is_default' => true, 'is_active' => true, 'name' => 'Main']);
        $this->dockWarehouse = Warehouse::factory()->create(['is_default' => false, 'is_active' => true, 'name' => 'Dock 3']);

        // Invoices allocate their backing order, so the stocked widget needs
        // stock in both warehouses.
        foreach ([$this->defaultWarehouse, $this->dockWarehouse] as $warehouse) {
            StockLevel::create(['product_id' => $this->stockProduct->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100, 'reserved_quantity' => 0, 'min_quantity' => 0]);
        }
    }

    // ── Sales orders ──────────────────────────────────────────────────────

    public function test_a_new_sales_order_remembers_the_customers_last_warehouse_notes_and_incoterms(): void
    {
        $customer = $this->customer();

        $this->storeOrder($customer, [
            'warehouse_id' => $this->dockWarehouse->id,
            'notes' => 'Deliver to loading bay 3',
            'incoterm_code' => 'FOB',
            'incoterm_place' => 'Port Klang',
        ])->assertCreated();

        $second = $this->storeOrder($customer)->assertCreated();
        $order = SalesOrder::query()->findOrFail($this->idOf($second));

        $this->assertSame($this->dockWarehouse->id, (int) $order->warehouse_id, 'the last order\'s warehouse is remembered (not the tenant default)');
        $this->assertSame('Deliver to loading bay 3', $order->notes);
        $this->assertSame('FOB', $order->incoterm_code);
        $this->assertSame('Port Klang', $order->incoterm_place);
    }

    public function test_explicit_sales_order_values_win_over_remembered_ones(): void
    {
        $customer = $this->customer();

        $this->storeOrder($customer, [
            'warehouse_id' => $this->dockWarehouse->id,
            'notes' => 'Deliver to loading bay 3',
            'incoterm_code' => 'FOB',
        ])->assertCreated();

        $second = $this->storeOrder($customer, [
            'warehouse_id' => $this->defaultWarehouse->id,
            'notes' => null,
            'incoterm_code' => 'EXW',
        ])->assertCreated();
        $order = SalesOrder::query()->findOrFail($this->idOf($second));

        $this->assertSame($this->defaultWarehouse->id, (int) $order->warehouse_id);
        $this->assertNull($order->notes, 'an explicitly cleared field stays cleared');
        $this->assertSame('EXW', $order->incoterm_code);
    }

    public function test_remembered_sales_order_values_never_cross_legal_entities(): void
    {
        [$entityA, $entityB] = $this->twoEntities();
        $customer = $this->customer(['legal_entity_id' => $entityB->id]);

        // The customer's last order was booked in entity B …
        $this->storeOrder($customer, [
            'warehouse_id' => $this->dockWarehouse->id,
            'notes' => 'Entity B dock',
        ])->assertCreated();

        // … then the customer moved to entity A: nothing from B carries over.
        $customer->update(['legal_entity_id' => $entityA->id]);

        $second = $this->storeOrder($customer)->assertCreated();
        $order = SalesOrder::query()->findOrFail($this->idOf($second));

        $this->assertSame($entityA->id, (int) $order->legal_entity_id);
        $this->assertSame($this->defaultWarehouse->id, (int) $order->warehouse_id, 'the tenant default applies, not entity B\'s warehouse');
        $this->assertNull($order->notes);
    }

    public function test_another_customers_order_is_never_remembered(): void
    {
        $customer = $this->customer();
        $other = $this->customer();

        $this->storeOrder($other, [
            'warehouse_id' => $this->dockWarehouse->id,
            'notes' => 'Other customer notes',
        ])->assertCreated();

        $order = SalesOrder::query()->findOrFail($this->idOf($this->storeOrder($customer)->assertCreated()));

        $this->assertSame($this->defaultWarehouse->id, (int) $order->warehouse_id);
        $this->assertNull($order->notes);
    }

    // ── Quotations ────────────────────────────────────────────────────────

    public function test_a_new_quotation_remembers_the_last_quotations_contacts_and_shipping(): void
    {
        $customer = $this->customer();
        $billing = $this->coreContact('Billing Person');
        $shipping = $this->coreContact('Receiving Clerk');

        $this->storeQuotation($customer, [
            'billing_contact_id' => $billing,
            'shipping_contact_id' => $shipping,
            'shipping_method' => 'Courier',
            'notes' => 'Quote via procurement portal',
        ])->assertCreated();

        $quotation = Quotation::query()->findOrFail($this->idOf($this->storeQuotation($customer)->assertCreated()));

        $this->assertSame($billing, $quotation->billing_contact_id);
        $this->assertSame($shipping, $quotation->shipping_contact_id);
        $this->assertSame('Courier', $quotation->shipping_method);
        $this->assertSame('Quote via procurement portal', $quotation->notes);
    }

    public function test_a_remembered_contact_from_another_entity_is_dropped(): void
    {
        [$entityA, $entityB] = $this->twoEntities();
        $customer = $this->customer(['legal_entity_id' => $entityA->id]);
        $foreign = $this->coreContact('Entity B Buyer', $entityB->id);
        $own = $this->coreContact('Entity A Receiver', $entityA->id);

        $this->storeQuotation($customer, [
            'billing_contact_id' => $foreign,
            'shipping_contact_id' => $own,
        ])->assertCreated();

        $quotation = Quotation::query()->findOrFail($this->idOf($this->storeQuotation($customer)->assertCreated()));

        $this->assertNull($quotation->billing_contact_id, 'a contact of another legal entity is never remembered');
        $this->assertSame($own, $quotation->shipping_contact_id);
    }

    // ── Invoices ──────────────────────────────────────────────────────────

    public function test_a_new_invoice_remembers_the_last_invoices_warehouse_contacts_and_notes(): void
    {
        $customer = $this->customer();
        $billing = $this->coreContact('Accounts Payable');

        $this->storeInvoice($customer, [
            'warehouse_id' => $this->dockWarehouse->id,
            'billing_contact_id' => $billing,
            'notes' => 'Quote PO on remittance',
        ])->assertCreated();

        $invoice = Invoice::query()->with('salesOrder')->findOrFail($this->idOf($this->storeInvoice($customer)->assertCreated()));

        $this->assertSame($this->dockWarehouse->id, (int) $invoice->salesOrder->warehouse_id);
        $this->assertSame($billing, $invoice->billing_contact_id);
        $this->assertSame('Quote PO on remittance', $invoice->notes);
    }

    public function test_explicit_invoice_values_win_over_remembered_ones(): void
    {
        $customer = $this->customer();

        $this->storeInvoice($customer, [
            'warehouse_id' => $this->dockWarehouse->id,
            'notes' => 'Quote PO on remittance',
        ])->assertCreated();

        $invoice = Invoice::query()->with('salesOrder')->findOrFail($this->idOf($this->storeInvoice($customer, [
            'warehouse_id' => $this->defaultWarehouse->id,
            'notes' => 'This time: pay by card',
        ])->assertCreated()));

        $this->assertSame($this->defaultWarehouse->id, (int) $invoice->salesOrder->warehouse_id);
        $this->assertSame('This time: pay by card', $invoice->notes);
    }

    // ── Defaults endpoint (form prefill + hint) ───────────────────────────

    public function test_the_defaults_endpoint_returns_the_remembered_values_and_their_source(): void
    {
        $customer = $this->customer();

        $first = $this->storeOrder($customer, [
            'warehouse_id' => $this->dockWarehouse->id,
            'notes' => 'Deliver to loading bay 3',
            'incoterm_code' => 'FOB',
        ])->assertCreated();
        $orderNumber = SalesOrder::query()->whereKey($this->idOf($first))->value('sales_order_number');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/settings/sales/defaults?customer_id='.$customer->id.'&document=sales_order')
            ->assertOk()
            ->assertJsonPath('data.remembered.document', 'sales_order')
            ->assertJsonPath('data.remembered.source.type', 'sales_order')
            ->assertJsonPath('data.remembered.source.number', $orderNumber)
            ->assertJsonPath('data.remembered.values.warehouse_id', $this->dockWarehouse->id)
            ->assertJsonPath('data.remembered.values.notes', 'Deliver to loading bay 3')
            ->assertJsonPath('data.remembered.values.incoterm_code', 'FOB');

        // A first-time customer has nothing remembered.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/settings/sales/defaults?customer_id='.$this->customer()->id.'&document=sales_order')
            ->assertOk()
            ->assertJsonPath('data.remembered.source', null)
            ->assertJsonPath('data.remembered.values', []);

        // Without a customer the endpoint keeps its historical shape.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/settings/sales/defaults')
            ->assertOk()
            ->assertJsonMissingPath('data.remembered');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'legal_entity_id' => null,
            'payment_terms' => 30,
            'credit_limit' => null,
            'total_outstanding' => 0,
        ], $attributes));
    }

    /** @return array{0: LegalEntity, 1: LegalEntity} */
    private function twoEntities(): array
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 346 Group',
            'code' => 'I346-'.Str::upper(Str::random(6)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            LegalEntity::create(['group_id' => $groupId, 'name' => 'Entity A 346', 'code' => 'A346-'.Str::upper(Str::random(4)), 'status' => 'active']),
            LegalEntity::create(['group_id' => $groupId, 'name' => 'Entity B 346', 'code' => 'B346-'.Str::upper(Str::random(4)), 'status' => 'active']),
        ];
    }

    private function coreContact(string $name, ?int $legalEntityId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('core_contacts')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'contact_type' => 'individual',
            'display_name' => $name,
            'first_name' => $name,
            'is_active' => true,
            'legal_entity_id' => $legalEntityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function idOf($response): int
    {
        return (int) ($response->json('data.id') ?? $response->json('id'));
    }

    private function storeOrder(Customer $customer, array $overrides = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/orders', array_merge([
            'customer_id' => $customer->id,
            'channel' => 'admin',
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->stockProduct->id,
                'description' => $this->stockProduct->name,
                'quantity' => 1,
                'unit_price' => 50,
                'tax_rate' => 0,
            ]],
        ], $overrides));
    }

    private function storeQuotation(Customer $customer, array $overrides = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/quotations', array_merge([
            'customer_id' => $customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
            ]],
        ], $overrides));
    }

    private function storeInvoice(Customer $customer, array $overrides = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', array_merge([
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->stockProduct->id,
                'description' => $this->stockProduct->name,
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $overrides));
    }
}
