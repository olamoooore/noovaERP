<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #634 (SALES-CHAT / UX1): edits to commercially important fields on
 * quotations, sales orders and invoices left no "who changed what, from → to"
 * entry in the chatter — the History feed only said "Updated …" with a list
 * of column names.
 *
 * Acceptance criteria asserted here, through the real HTTP routes:
 *  - a declarative list of tracked fields per model (header and lines);
 *  - one chatter entry per save listing every changed tracked field with its
 *    old → new value and the actor;
 *  - line changes are grouped ("Line 2: qty 5 → 8, price 120.00 → 110.00").
 */
class Issue634ChatterFieldChangeTrackingTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Customer $otherCustomer;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.orders.auto_lock_on_confirm', false);

        $this->admin = User::factory()->create(['name' => 'Tracy Tracker']);
        DB::table('public.users')->updateOrInsert(
            ['email' => $this->admin->email],
            ['name' => $this->admin->name, 'password' => $this->admin->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $this->admin->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->admin->assignRole(UserRole::ADMIN->spatieName());

        $this->customer = Customer::factory()->create([
            'name' => 'Acme Before',
            'payment_terms' => 30,
            'credit_limit' => 1000000,
        ]);
        $this->otherCustomer = Customer::factory()->create([
            'name' => 'Globex After',
            'payment_terms' => 30,
            'credit_limit' => 1000000,
        ]);

        $this->productA = Product::factory()->create([
            'name' => 'Tracked Widget A',
            'price' => 120,
            'cost' => 1,
            'track_inventory' => false,
        ]);
        $this->productB = Product::factory()->create([
            'name' => 'Tracked Widget B',
            'price' => 50,
            'cost' => 1,
            'track_inventory' => false,
        ]);

        $this->actingAs($this->admin);
    }

    // ── Quotation ──────────────────────────────────────────────────────────

    public function test_quotation_edit_logs_one_entry_with_old_new_header_values_and_grouped_line_changes(): void
    {
        $create = $this->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => '2026-10-01',
            'valid_until' => '2026-10-31',
            'salesperson' => 'Alice',
            'lines' => [
                $this->line($this->productB, 1, 50),
                $this->line($this->productA, 5, 120),
            ],
        ]);
        $create->assertCreated();
        $id = (int) ($create->json('data.id') ?? $create->json('id'));

        $this->putJson("/api/v1/quotations/{$id}", [
            'customer_id' => $this->otherCustomer->id,
            'valid_until' => '2026-11-15',
            'salesperson' => 'Bob',
            'lines' => [
                $this->line($this->productB, 1, 50),
                $this->line($this->productA, 8, 110),
            ],
        ])->assertOk();

        $entry = $this->singleUpdateEntry(Quotation::class, $id);

        $this->assertSame('Tracy Tracker', $entry['actor']['name'] ?? null, 'The entry must name the user who saved.');

        $changes = $this->changesByField($entry);
        $this->assertSame(['old' => 'Acme Before', 'new' => 'Globex After'], $changes['customer_id'] ?? null);
        $this->assertSame(['old' => '2026-10-31', 'new' => '2026-11-15'], $changes['valid_until'] ?? null);
        $this->assertSame(['old' => 'Alice', 'new' => 'Bob'], $changes['salesperson'] ?? null);
        $this->assertArrayNotHasKey('quotation_date', $changes, 'An unchanged tracked field must not be listed.');

        $lineChanges = $entry['properties']['line_changes'] ?? [];
        $this->assertCount(1, $lineChanges, 'Only the changed line is listed; the unchanged line 1 is not.');
        $this->assertSame(2, $lineChanges[0]['line'] ?? null);
        $this->assertSame('Line 2: qty 5 → 8, price 120.00 → 110.00', $lineChanges[0]['summary'] ?? null);
    }

    public function test_quotation_save_without_tracked_changes_adds_no_field_change_list(): void
    {
        $create = $this->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => '2026-10-01',
            'valid_until' => '2026-10-31',
            'lines' => [$this->line($this->productA, 5, 120)],
        ]);
        $create->assertCreated();
        $id = (int) ($create->json('data.id') ?? $create->json('id'));

        $this->putJson("/api/v1/quotations/{$id}", ['notes' => 'Just a note'])->assertOk();

        foreach ($this->logs(Quotation::class, $id) as $entry) {
            $this->assertEmpty($entry['properties']['changes'] ?? [], 'Untracked fields must not produce old → new rows.');
            $this->assertEmpty($entry['properties']['line_changes'] ?? []);
        }
    }

    // ── Sales order ────────────────────────────────────────────────────────

    public function test_draft_sales_order_header_edit_is_tracked(): void
    {
        $orderId = $this->createDraftOrder();

        $this->putJson("/api/v1/sales/orders/{$orderId}", [
            'customer_id' => $this->otherCustomer->id,
            'required_date' => '2026-12-01',
            'discount_amount' => 15,
        ])->assertOk();

        $entry = $this->singleUpdateEntry(SalesOrder::class, $orderId);
        $changes = $this->changesByField($entry);

        $this->assertSame(['old' => 'Acme Before', 'new' => 'Globex After'], $changes['customer_id'] ?? null);
        $this->assertSame('2026-12-01', $changes['required_date']['new'] ?? null);
        $this->assertSame(['old' => '0.00', 'new' => '15.00'], $changes['discount_amount'] ?? null);
        $this->assertSame('Tracy Tracker', $entry['actor']['name'] ?? null);
    }

    public function test_confirmed_sales_order_amendment_groups_line_changes(): void
    {
        $orderId = $this->createDraftOrder();
        $this->postJson("/api/v1/sales/orders/{$orderId}/confirm")->assertOk();

        $order = SalesOrder::with('lines')->findOrFail($orderId);
        $lines = $order->lines->sortBy('id')->values();

        $this->putJson("/api/v1/sales/orders/{$orderId}", [
            'lines' => [
                ['id' => $lines[1]->id, 'quantity' => 8, 'unit_price' => 110],
            ],
        ])->assertOk();

        $entry = $this->singleUpdateEntry(SalesOrder::class, $orderId);

        $lineChanges = $entry['properties']['line_changes'] ?? [];
        $this->assertCount(1, $lineChanges);
        $this->assertSame('Line 2: qty 5 → 8, price 120.00 → 110.00', $lineChanges[0]['summary'] ?? null);
    }

    // ── Invoice ────────────────────────────────────────────────────────────

    public function test_invoice_edit_tracks_due_date_discount_and_line_changes(): void
    {
        $create = $this->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'lines' => [
                $this->line($this->productB, 1, 50),
                $this->line($this->productA, 5, 120),
            ],
        ]);
        $create->assertCreated();
        $id = (int) ($create->json('data.id') ?? $create->json('id'));

        $this->putJson("/api/v1/invoices/{$id}", [
            'due_date' => '2026-11-30',
            'discount_amount' => 20,
            'lines' => [
                $this->line($this->productB, 1, 50),
                $this->line($this->productA, 8, 110),
            ],
        ])->assertOk();

        $entry = $this->singleUpdateEntry(Invoice::class, $id);
        $changes = $this->changesByField($entry);

        $this->assertSame(['old' => '2026-10-31', 'new' => '2026-11-30'], $changes['due_date'] ?? null);
        $this->assertSame(['old' => '0.00', 'new' => '20.00'], $changes['discount_amount'] ?? null);
        $this->assertArrayNotHasKey('invoice_date', $changes);

        $lineChanges = $entry['properties']['line_changes'] ?? [];
        $this->assertCount(1, $lineChanges);
        $this->assertSame('Line 2: qty 5 → 8, price 120.00 → 110.00', $lineChanges[0]['summary'] ?? null);
        $this->assertSame('Tracy Tracker', $entry['actor']['name'] ?? null);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function line(Product $product, float $qty, float $price): array
    {
        return [
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => $qty,
            'unit_price' => $price,
            'price_mode' => 'manual',
            'tax_rate' => 0,
            'discount_amount' => 0,
        ];
    }

    private function createDraftOrder(): int
    {
        $warehouse = Warehouse::create([
            'name' => 'Issue 634 Warehouse',
            'code' => 'I634-'.uniqid(),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $warehouse->id,
            'lines' => [
                $this->line($this->productB, 1, 50),
                $this->line($this->productA, 5, 120),
            ],
        ]);
        $response->assertCreated();

        return (int) $response->json('id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function logs(string $subjectType, int $id): array
    {
        $response = $this->getJson('/api/v1/chatter/'.rawurlencode($subjectType)."/{$id}");
        $response->assertOk();

        return $response->json('data.logs') ?? [];
    }

    /**
     * Exactly one chatter entry per save carries the field-change list.
     *
     * @return array<string, mixed>
     */
    private function singleUpdateEntry(string $subjectType, int $id): array
    {
        $tracked = array_values(array_filter(
            $this->logs($subjectType, $id),
            fn (array $entry) => ! empty($entry['properties']['changes'] ?? null)
                || ! empty($entry['properties']['line_changes'] ?? null),
        ));

        $this->assertCount(1, $tracked, 'One save must produce exactly one chatter entry listing the changed fields.');

        return $tracked[0];
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changesByField(array $entry): array
    {
        $out = [];
        foreach ($entry['properties']['changes'] ?? [] as $change) {
            $out[$change['field']] = ['old' => $change['old'], 'new' => $change['new']];
        }

        return $out;
    }
}
