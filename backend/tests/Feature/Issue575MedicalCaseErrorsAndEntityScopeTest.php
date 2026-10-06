<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #575 (MC11):
 *  - business-rule failures (a case line that does not belong to the case)
 *    surfaced as 500 with the raw exception message instead of 422;
 *  - medical cases were not filtered by legal entity (same pattern as #462 /
 *    #434): a user confined to entity A could list, read and act on entity
 *    B's cases.
 *
 * Acceptance: validation and business-rule failures → 422; entity scoping
 * applied with the platform's entity filter (EntityAccessService).
 *
 * Real HTTP through the router (/api/v1/medical-cases…).
 */
class Issue575MedicalCaseErrorsAndEntityScopeTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private User $admin;

    private Customer $customerA;

    private Customer $customerB;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = '57557557-0000-1111-2222-333333333333';
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $this->tenantId]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'sales_order_lines', 'legal_entities',
            'user_entity_access', 'delivery_orders', 'delivery_order_lines', 'invoices', 'invoice_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 575 Group',
            'code' => 'G575-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'E575A-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'E575B-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Unscoped admin (no user_entity_access rows) — historical fail-open view.
        $this->admin = $this->makeUser('admin', []);

        $this->customerA = Customer::factory()->create(['legal_entity_id' => $this->entityA]);
        $this->customerB = Customer::factory()->create(['legal_entity_id' => $this->entityB]);
        $this->product = Product::factory()->create();
    }

    /**
     * @param  int[]  $entityIds
     */
    private function makeUser(string $label, array $entityIds): User
    {
        $user = User::create([
            'name' => "Issue575 {$label}",
            'email' => "issue575-{$label}-".uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('admin');

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($entityIds as $index => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => 'editor',
                'is_default' => $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function salesOrderLineFor(Customer $customer): SalesOrderLine
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-575-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
        ]);

        return SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 575 line',
            'product_id' => $this->product->id,
            'quantity' => 100,
            'unit_price' => 25,
        ]);
    }

    private function caseFor(Customer $customer, string $status = 'draft', float $allocated = 5, float $delivered = 0): MedicalCaseItem
    {
        $case = MedicalCase::create([
            'customer_id' => $customer->id,
            'case_date' => '2026-09-15',
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);

        return MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLineFor($customer)->id,
            'product_id' => $this->product->id,
            'allocated_qty' => $allocated,
            'delivered_qty' => $delivered,
            'unit_price' => 25,
        ]);
    }

    // ─── 500 → 422 ────────────────────────────────────────────────────

    public function test_allocating_a_line_of_another_case_is_a_422_not_a_500(): void
    {
        $item = $this->caseFor($this->customerA);
        $foreign = $this->caseFor($this->customerA);

        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/medical-cases/'.$item->medical_case_id.'/allocate',
            ['items' => [['id' => $foreign->id, 'allocated_qty' => 1]]],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.id']);
        $this->assertSame('draft', MedicalCase::findOrFail($item->medical_case_id)->status);
    }

    public function test_delivering_a_line_of_another_case_is_a_422_not_a_500(): void
    {
        $item = $this->caseFor($this->customerA, 'allocated');
        $foreign = $this->caseFor($this->customerA, 'allocated');

        $do = DeliveryOrder::create([
            'customer_id' => $this->customerA->id,
            'status' => 'done',
            'created_by' => $this->admin->id,
        ]);
        $doLine = DeliveryOrderLine::create([
            'delivery_order_id' => $do->id,
            'line_type' => 'product',
            'product_id' => $this->product->id,
            'quantity_ordered' => 5,
            'quantity_delivered' => 5,
            'unit_price' => 25,
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/medical-cases/'.$item->medical_case_id.'/deliver',
            ['items' => [['id' => $foreign->id, 'delivered_qty' => 1, 'delivery_order_item_id' => $doLine->id]]],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.id']);
        $this->assertSame(0.0, round((float) $foreign->fresh()->delivered_qty, 4));
    }

    public function test_invoicing_a_line_of_another_case_is_a_422_not_a_500(): void
    {
        $item = $this->caseFor($this->customerA, 'delivered', 5, 5);
        $foreign = $this->caseFor($this->customerA, 'delivered', 5, 5);

        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customerA->id,
            'status' => 'posted',
            'created_by' => $this->admin->id,
        ]);
        $invoiceLine = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'description' => 'Issue 575 invoice line',
            'quantity' => 5,
            'unit_price' => 25,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 125,
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/medical-cases/'.$item->medical_case_id.'/invoice',
            [
                'invoice_id' => $invoice->id,
                'items' => [['id' => $foreign->id, 'invoiced_qty' => 1, 'invoice_item_id' => $invoiceLine->id]],
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.id']);
        $this->assertSame(0.0, round((float) $foreign->fresh()->invoiced_qty, 4));
    }

    // ─── Authorship ───────────────────────────────────────────────────

    public function test_created_by_is_the_tenant_user(): void
    {
        $line = $this->salesOrderLineFor($this->customerA);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/medical-cases', [
            'customer_id' => $this->customerA->id,
            'items' => [[
                'sales_order_item_id' => $line->id,
                'product_id' => $this->product->id,
                'allocated_qty' => 1,
            ]],
        ]);

        $response->assertCreated();

        $tenantUserId = (int) DB::table('users')->where('email', $this->admin->email)->value('id');
        $this->assertSame($tenantUserId, (int) MedicalCase::findOrFail($response->json('data.id'))->created_by);
    }

    // ─── Legal-entity scoping ─────────────────────────────────────────

    public function test_a_new_case_is_stamped_with_the_customers_legal_entity(): void
    {
        $line = $this->salesOrderLineFor($this->customerB);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/medical-cases', [
            'customer_id' => $this->customerB->id,
            'items' => [[
                'sales_order_item_id' => $line->id,
                'product_id' => $this->product->id,
                'allocated_qty' => 1,
            ]],
        ]);

        $response->assertCreated();
        $this->assertSame(
            $this->entityB,
            (int) DB::table('medical_cases')->where('id', $response->json('data.id'))->value('legal_entity_id'),
        );
    }

    public function test_a_scoped_user_only_lists_and_reads_their_entitys_cases(): void
    {
        $caseA = $this->caseFor($this->customerA)->medicalCase;
        $caseB = $this->caseFor($this->customerB)->medicalCase;
        $user = $this->makeUser('scoped-a', [$this->entityA]);

        $list = $this->actingAs($user)->getJson('/api/v1/medical-cases');
        $list->assertOk();

        $ids = collect($list->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($ids->contains($caseA->id));
        $this->assertFalse($ids->contains($caseB->id), 'Entity B case must not leak into an entity A user\'s list.');

        $this->actingAs($user)->getJson('/api/v1/medical-cases/'.$caseA->id)->assertOk();
        $this->actingAs($user)->getJson('/api/v1/medical-cases/'.$caseB->id)->assertNotFound();
    }

    public function test_a_legacy_case_without_an_entity_follows_its_customers_entity(): void
    {
        $legacyB = $this->caseFor($this->customerB)->medicalCase;
        DB::table('medical_cases')->where('id', $legacyB->id)->update(['legal_entity_id' => null]);

        $user = $this->makeUser('scoped-a-legacy', [$this->entityA]);

        $ids = collect($this->actingAs($user)->getJson('/api/v1/medical-cases')->json('data'))
            ->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertFalse($ids->contains($legacyB->id));
        $this->actingAs($user)->getJson('/api/v1/medical-cases/'.$legacyB->id)->assertNotFound();
    }

    public function test_a_scoped_user_cannot_act_on_another_entitys_case(): void
    {
        $itemB = $this->caseFor($this->customerB);
        $caseB = $itemB->medicalCase;
        $user = $this->makeUser('scoped-a-act', [$this->entityA]);

        $this->actingAs($user)->putJson('/api/v1/medical-cases/'.$caseB->id, ['notes' => 'x'])->assertNotFound();
        $this->actingAs($user)->postJson('/api/v1/medical-cases/'.$caseB->id.'/allocate', [
            'items' => [['id' => $itemB->id, 'allocated_qty' => 1]],
        ])->assertNotFound();
        $this->actingAs($user)->postJson('/api/v1/medical-cases/'.$caseB->id.'/cancel')->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/v1/medical-cases/'.$caseB->id)->assertNotFound();

        $fresh = MedicalCase::findOrFail($caseB->id);
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->notes);
    }

    public function test_a_scoped_user_cannot_create_a_case_for_another_entitys_customer(): void
    {
        $line = $this->salesOrderLineFor($this->customerB);
        $user = $this->makeUser('scoped-a-store', [$this->entityA]);

        $response = $this->actingAs($user)->postJson('/api/v1/medical-cases', [
            'customer_id' => $this->customerB->id,
            'items' => [[
                'sales_order_item_id' => $line->id,
                'product_id' => $this->product->id,
                'allocated_qty' => 1,
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['customer_id']);
        $this->assertSame(0, MedicalCase::where('customer_id', $this->customerB->id)->count());
    }

    public function test_the_performance_report_only_counts_the_users_entities(): void
    {
        $this->caseFor($this->customerA);
        $this->caseFor($this->customerB);
        $user = $this->makeUser('scoped-a-report', [$this->entityA]);

        $response = $this->actingAs($user)->getJson('/api/v1/medical-cases/reports/performance?from_date=2026-09-01&to_date=2026-09-30');
        $response->assertOk();

        $customers = collect($response->json('data.hospital_totals'))->pluck('customer_id')->map(fn ($id) => (int) $id);
        $this->assertTrue($customers->contains($this->customerA->id));
        $this->assertFalse($customers->contains($this->customerB->id), 'Entity B cases must not be reported to an entity A user.');
    }
}
