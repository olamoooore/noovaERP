<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Tests\TenantTestCase;

/**
 * Issue #344 (D7 pre-flight checklist) — the remaining gaps:
 *
 *  - a `fail` on the checklist is a hard gate, not just a UI hint: POST
 *    /invoices/{id}/post (and the legacy /send, which posts too) refuses
 *    with 422 when the checklist reports a failure, and leaves the invoice
 *    a draft with no journal entry. A warn-only checklist still posts.
 *  - POST /invoices/preflight runs the same checklist on the UNSAVED form
 *    payload (create and edit) without writing anything, behind the invoice
 *    create permission literal (invoices.write).
 */
class Issue344InvoicePreflightGateTest extends TenantTestCase
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
            'name' => 'Issue 344 Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->userWithRole(UserRole::ADMIN);

        // Real posting accounts, so an invoice the gate lets through really
        // posts — a refusal is the gate's, not a missing account mapping.
        $ar = ChartOfAccount::factory()->create(['code' => '1100-I344', 'name' => 'AR', 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => '4000-I344', 'name' => 'Revenue', 'type' => 'revenue', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-I344', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    // ── (1) a failing check blocks posting on the server ─────────────────────

    public function test_post_is_refused_when_the_checklist_fails_on_an_unconfirmed_likely_duplicate(): void
    {
        $customer = $this->customer();
        $this->invoiceWithLine($customer, ['po_number' => 'PO-344']);
        $twin = $this->invoiceWithLine($customer, ['po_number' => 'po-344 ', 'total' => 250, 'balance' => 250]);

        $preflight = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/invoices/{$twin->id}/preflight")
            ->assertOk();
        $this->assertSame('fail', $this->check($preflight->json('checks'), 'duplicate')['status']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$twin->id}/post");

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PREFLIGHT_FAILED');
        $this->assertSame(['duplicate'], array_column($response->json('failed_checks'), 'key'));
        $this->assertStringContainsString('Likely duplicate', $response->json('message'));

        $twin->refresh();
        $this->assertSame('draft', $twin->status);
        $this->assertNull($twin->journal_entry_id);
    }

    public function test_legacy_send_which_posts_is_refused_the_same_way(): void
    {
        $customer = $this->customer();
        $this->invoiceWithLine($customer, ['customer_reference' => 'REF-344']);
        $twin = $this->invoiceWithLine($customer, ['customer_reference' => 'REF-344', 'total' => 250, 'balance' => 250]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$twin->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PREFLIGHT_FAILED')
            ->assertJsonPath('failed_checks.0.key', 'duplicate');

        $this->assertSame('draft', $twin->fresh()->status);
        $this->assertNull($twin->fresh()->journal_entry_id);
    }

    public function test_post_is_refused_for_an_invoice_without_line_items(): void
    {
        $invoice = $this->invoice($this->customer());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PREFLIGHT_FAILED')
            ->assertJsonPath('failed_checks.0.key', 'lines');

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->journal_entry_id);
    }

    public function test_a_warn_only_checklist_still_posts(): void
    {
        // Missing TIN and a same-total invoice in the same week only warn.
        $customer = $this->customer();
        $this->invoiceWithLine($customer);
        $invoice = $this->invoiceWithLine($customer);

        $preflight = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/preflight")
            ->assertOk()
            ->assertJsonPath('ready', true);
        $this->assertSame('warn', $this->check($preflight->json('checks'), 'duplicate')['status']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $this->assertSame('posted', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->journal_entry_id);
    }

    public function test_stock_check_sums_lines_of_the_same_product_like_the_goods_issue(): void
    {
        // Two lines of 3 against 5 on hand: each line alone fits, the goods
        // issue (which deducts per product) does not — the checklist must
        // say so instead of reporting "available".
        $warehouse = Warehouse::create(['name' => 'I344 WH', 'code' => 'I344-'.uniqid(), 'is_active' => true, 'is_default' => true]);
        $product = Product::factory()->create(['name' => 'Tracked Widget', 'track_inventory' => true]);
        StockLevel::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5, 'reserved_quantity' => 0]);

        $invoice = $this->invoice($this->customer());
        foreach ([3, 3] as $quantity) {
            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'description' => 'Tracked Widget',
                'quantity' => $quantity,
                'unit_price' => 10,
                'tax_rate' => 0,
                'tax_inclusive' => false,
                'discount_amount' => 0,
                'line_total' => 10 * $quantity,
                'tax_amount' => 0,
            ]);
        }

        $checks = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/preflight")
            ->assertOk()
            ->json('checks');

        $stock = $this->check($checks, 'stock');
        $this->assertSame('fail', $stock['status'], json_encode($stock));
        $this->assertStringContainsString('Tracked Widget: short by 1.00', $stock['detail']);
    }

    // ── (2) dry run on the unsaved form payload ──────────────────────────────

    public function test_dry_run_checks_an_unsaved_payload_and_writes_nothing(): void
    {
        $customer = $this->customer();
        $existing = $this->invoiceWithLine($customer, ['po_number' => 'PO-DRY']);
        $product = Product::factory()->create(['track_inventory' => false]);

        $before = $this->rowCounts();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/invoices/preflight', [
                'customer_id' => $customer->id,
                'invoice_date' => now()->toDateString(),
                'po_number' => 'PO-DRY',
                'lines' => [[
                    'product_id' => $product->id,
                    'description' => 'Consulting',
                    'quantity' => 2,
                    'unit_price' => 75,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ]],
            ]);

        $response->assertOk()->assertJsonPath('ready', false)->assertJsonPath('total', 8);
        $checks = $response->json('checks');
        $this->assertSame('pass', $this->check($checks, 'lines')['status']);
        $this->assertSame('fail', $this->check($checks, 'duplicate')['status'], 'Same PO as an existing invoice is a likely duplicate.');
        $this->assertStringContainsString($existing->invoice_number, $this->check($checks, 'duplicate')['detail']);
        $this->assertSame('warn', $this->check($checks, 'e_invoice')['status']);
        $this->assertSame('pass', $this->check($checks, 'period')['status']);

        $this->assertSame($before, $this->rowCounts(), 'The dry run must not write anything.');
    }

    public function test_dry_run_without_lines_fails_the_lines_check(): void
    {
        $customer = $this->customer();
        $before = $this->rowCounts();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/invoices/preflight', [
                'customer_id' => $customer->id,
                'invoice_date' => now()->toDateString(),
                'lines' => [],
            ]);

        $response->assertOk()->assertJsonPath('ready', false);
        $this->assertSame('fail', $this->check($response->json('checks'), 'lines')['status']);
        $this->assertSame($before, $this->rowCounts());
    }

    public function test_dry_run_in_edit_mode_uses_the_saved_invoice_without_touching_it(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoiceWithLine($customer, ['po_number' => 'PO-EDIT', 'total' => 100, 'balance' => 100]);
        $product = Product::factory()->create(['track_inventory' => false]);
        $snapshot = $invoice->fresh()->only(['po_number', 'total', 'invoice_date', 'status', 'updated_at']);
        $before = $this->rowCounts();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/invoices/preflight', [
                'invoice_id' => $invoice->id,
                'customer_id' => $customer->id,
                'invoice_date' => now()->toDateString(),
                'po_number' => 'PO-EDIT',
                'lines' => [
                    ['product_id' => $product->id, 'description' => 'A', 'quantity' => 3, 'unit_price' => 40, 'tax_rate' => 0],
                    ['product_id' => $product->id, 'description' => 'B', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0],
                ],
            ]);

        $response->assertOk();
        $checks = $response->json('checks');
        // Its own saved row is not a duplicate of itself.
        $this->assertSame('pass', $this->check($checks, 'duplicate')['status'], json_encode($checks));
        $this->assertStringContainsString('2 line(s)', $this->check($checks, 'lines')['detail']);

        $this->assertSame($before, $this->rowCounts());
        $this->assertEquals($snapshot, $invoice->fresh()->only(['po_number', 'total', 'invoice_date', 'status', 'updated_at']));
        $this->assertSame(1, InvoiceLine::where('invoice_id', $invoice->id)->count());
    }

    public function test_dry_run_requires_the_invoice_create_permission(): void
    {
        $viewer = $this->userWithRole(UserRole::VIEWER);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/invoices/preflight', [
                'customer_id' => $this->customer()->id,
                'lines' => [],
            ])
            ->assertForbidden();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->spatieName()]);
        DB::table('public.user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantUuid,
            'role' => $role->value,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['legal_entity_id' => null, 'tax_number' => null]);
    }

    private function invoice(Customer $customer, array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'invoice_date' => now()->toDateString(),
            'currency_code' => null,
            'exchange_rate' => 1,
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'balance' => 100,
            'paid_amount' => 0,
            'requires_approval' => false,
            'po_number' => null,
            'customer_reference' => null,
            'journal_entry_id' => null,
        ], $attributes));
    }

    private function invoiceWithLine(Customer $customer, array $attributes = []): Invoice
    {
        $invoice = $this->invoice($customer, $attributes);
        $total = (float) $invoice->total;

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => Product::factory()->create(['track_inventory' => false])->id,
            'description' => 'Service fee',
            'quantity' => 1,
            'unit_price' => $total,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => $total,
            'tax_amount' => 0,
        ]);

        return $invoice;
    }

    /** @param  array<int, array<string, mixed>>  $checks */
    private function check(array $checks, string $key): array
    {
        $match = collect($checks)->firstWhere('key', $key);
        $this->assertNotNull($match, "No '{$key}' check in ".json_encode($checks));

        return $match;
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['invoices', 'invoice_lines', 'invoice_activity_log', 'journal_entries', 'customers', 'stock_movements'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
