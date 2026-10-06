<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #365 [SALES-DASH]: the Sales landing page tells a salesperson what to
 * do next — quotes to send / expiring in 7 days, approvals pending, orders to
 * deliver / invoice (by invoicing policy), deliveries to invoice, invoices to
 * send and overdue invoices.
 *
 * Acceptance, through the real HTTP routes (router → middleware → controller):
 *   • one endpoint returns each queue's count (plus the top N rows), scoped by
 *     permission, by the caller's legal entities, and optionally to the
 *     caller's own documents;
 *   • each tile's link opens the matching filtered list — the list endpoint
 *     given the tile's filter returns exactly the documents the tile counted.
 */
class Issue365SalesActionQueuesTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private User $admin;

    private User $salesUser;

    private Customer $customerA;

    private Customer $customerB;

    private Product $product;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $this->tenantId]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        CompanySettings::query()->create(['base_currency' => 'USD']);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 365 Group',
            'code' => 'G365-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'E365A-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'E365B-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->admin = $this->makeUser('admin');
        $this->salesUser = $this->makeUser('sales');

        $this->customerA = Customer::factory()->create(['name' => '365 Customer A', 'legal_entity_id' => $this->entityA]);
        // Customer B is owned by the salesperson — "my documents" includes it.
        $this->customerB = Customer::factory()->create([
            'name' => '365 Customer B',
            'legal_entity_id' => $this->entityB,
            'salesperson_id' => $this->salesUser->id,
        ]);
        $this->product = Product::factory()->create([
            'name' => '365 Widget', 'sku' => '365-'.uniqid(), 'price' => 100, 'cost' => 40, 'track_inventory' => false,
        ]);

        $this->seedDocuments();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @param  int[]  $entityIds */
    private function makeUser(string $role, array $entityIds = []): User
    {
        $user = User::create([
            'name' => "Issue365 {$role}",
            'email' => "issue365-{$role}-".uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole(\App\Enums\UserRole::from($role)->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
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

    private function quotation(string $key, Customer $customer, string $status, int $validInDays): void
    {
        $this->ids[$key] = Quotation::create([
            'quotation_number' => 'QT-365-'.$key,
            'customer_id' => $customer->id,
            'quotation_date' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->addDays($validInDays)->toDateString(),
            'status' => $status,
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ])->id;
    }

    private function salesOrder(string $key, Customer $customer, int $entityId, string $status, array $line): SalesOrder
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-365-'.$key,
            'customer_id' => $customer->id,
            'legal_entity_id' => $entityId,
            'channel' => 'admin',
            'status' => $status,
            'accounting_status' => 'not_posted',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1000,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'order_date' => now()->subDays(5)->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        SalesOrderLine::create(array_merge([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'description' => '365 order line',
            'quantity' => 10,
            'quantity_cancelled' => 0,
            'quantity_delivered' => 0,
            'quantity_invoiced' => 0,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 1000,
            'invoicing_policy' => 'ordered_qty',
        ], $line));

        $this->ids[$key] = $order->id;

        return $order;
    }

    private function invoice(string $key, Customer $customer, string $status, float $balance, int $dueInDays, string $delivery): void
    {
        $this->ids[$key] = Invoice::create([
            'invoice_number' => 'INV-365-'.$key,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subDays(30)->toDateString(),
            'due_date' => now()->addDays($dueInDays)->toDateString(),
            'status' => $status,
            'lifecycle_status' => $status === 'draft' ? 'draft' : 'posted',
            'delivery_status' => $delivery,
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'balance' => $balance,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ])->id;
    }

    private function seedDocuments(): void
    {
        // Quotations.
        $this->quotation('q_draft', $this->customerA, 'draft', 30);          // to send
        $this->quotation('q_expiring', $this->customerA, 'sent', 3);         // expiring
        $this->quotation('q_draft_expiring', $this->customerB, 'draft', 5);  // to send + expiring
        $this->quotation('q_far', $this->customerA, 'sent', 20);             // neither
        $this->quotation('q_past', $this->customerA, 'sent', -1);            // already past: neither
        $this->quotation('q_confirmed', $this->customerA, 'confirmed', 2);   // neither

        // Sales orders.
        $this->salesOrder('so_to_deliver', $this->customerA, $this->entityA, 'confirmed', [
            'invoicing_policy' => 'delivered_qty',           // nothing delivered → nothing invoiceable yet
        ]);
        $toInvoice = $this->salesOrder('so_to_invoice', $this->customerA, $this->entityA, 'fulfilled', [
            'invoicing_policy' => 'delivered_qty', 'quantity_delivered' => 10,
        ]);
        $this->salesOrder('so_ordered_policy', $this->customerB, $this->entityB, 'confirmed', [
            'invoicing_policy' => 'ordered_qty',             // invoiceable now AND still to deliver
        ]);
        $this->salesOrder('so_draft', $this->customerA, $this->entityA, 'draft', []);
        $this->salesOrder('so_done', $this->customerA, $this->entityA, 'invoiced', [
            'quantity_delivered' => 10, 'quantity_invoiced' => 10,
        ]);
        $this->salesOrder('so_cancelled', $this->customerA, $this->entityA, 'cancelled', []);

        // A completed delivery of so_to_invoice that nobody billed (#688).
        $deliveryOrder = DeliveryOrder::create([
            'delivery_order_number' => 'DO-365-1',
            'sales_order_id' => $toInvoice->id,
            'customer_id' => $this->customerA->id,
            'legal_entity_id' => $this->entityA,
            'status' => 'done',
            'source' => 'sales_order',
            'delivery_date' => now()->subDays(2)->toDateString(),
            'completed_at' => now()->subDays(2),
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);
        DeliveryOrderLine::create([
            'delivery_order_id' => $deliveryOrder->id,
            'sales_order_line_id' => $toInvoice->lines()->value('id'),
            'product_id' => $this->product->id,
            'description' => '365 delivery line',
            'quantity_ordered' => 10,
            'quantity_delivered' => 10,
            'quantity_invoiced' => 0,
            'unit_price' => 100,
            'line_total' => 1000,
        ]);
        $this->ids['do_unbilled'] = $deliveryOrder->id;

        // Invoices.
        $this->invoice('inv_overdue', $this->customerA, 'posted', 100, -10, 'sent');      // overdue
        $this->invoice('inv_to_send', $this->customerA, 'posted', 50, 10, 'not_sent');    // to send
        $this->invoice('inv_overdue_b', $this->customerB, 'partial', 70, -3, 'failed');   // overdue + to send
        $this->invoice('inv_paid', $this->customerA, 'paid', 0, -10, 'not_sent');         // neither
        $this->invoice('inv_draft', $this->customerA, 'draft', 100, -10, 'not_sent');     // neither

        // Approval requests: one pending sales request, one already decided,
        // one expired — only the first is in the queue.
        $base = [
            'request_type' => 'discount',
            'subject_type' => Quotation::class,
            'subject_id' => $this->ids['q_draft'],
            'amount' => 100,
            'reason' => 'Issue 365 approval fixture request',
            'requested_by' => $this->salesUser->id,
            'requested_at' => now()->subDay(),
        ];
        $this->ids['apr_pending'] = ApprovalRequest::create($base + [
            'request_number' => 'APR-365-1', 'status' => 'pending', 'expires_at' => now()->addDays(3),
        ])->id;
        ApprovalRequest::create($base + [
            'request_number' => 'APR-365-2', 'status' => 'approved', 'expires_at' => now()->addDays(3),
        ]);
        ApprovalRequest::create($base + [
            'request_number' => 'APR-365-3', 'status' => 'pending', 'expires_at' => now()->subDay(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> queue key => queue payload */
    private function queues(User $user, string $query = ''): array
    {
        $response = $this->actingAs($user)->getJson('/api/v1/sales/dashboard/action-queues'.$query);
        $response->assertOk();

        return collect($response->json('data.queues'))->keyBy('key')->all();
    }

    /** @return int[] */
    private function itemIds(array $queue): array
    {
        $ids = array_map(fn ($item) => (int) $item['id'], $queue['items']);
        sort($ids);

        return $ids;
    }

    /** @return int[] */
    private function expected(string ...$keys): array
    {
        $ids = array_map(fn ($key) => $this->ids[$key], $keys);
        sort($ids);

        return $ids;
    }

    /** @return int[] ids returned by a list endpoint */
    private function listIds(User $user, string $url): array
    {
        $response = $this->actingAs($user)->getJson($url);
        $response->assertOk();

        $ids = array_map(fn ($row) => (int) $row['id'], $response->json('data'));
        sort($ids);

        return $ids;
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    public function test_endpoint_returns_each_queue_count_with_top_rows_and_links(): void
    {
        $queues = $this->queues($this->admin);

        $expected = [
            'quotes_to_send' => ['q_draft', 'q_draft_expiring'],
            'quotes_expiring' => ['q_expiring', 'q_draft_expiring'],
            'approvals_pending' => ['apr_pending'],
            'orders_to_deliver' => ['so_to_deliver', 'so_ordered_policy'],
            'orders_to_invoice' => ['so_to_invoice', 'so_ordered_policy'],
            'deliveries_to_invoice' => ['do_unbilled'],
            'invoices_to_send' => ['inv_to_send', 'inv_overdue_b'],
            'overdue_invoices' => ['inv_overdue', 'inv_overdue_b'],
        ];

        $this->assertSame(array_keys($expected), array_keys($queues));

        foreach ($expected as $key => $documents) {
            $this->assertSame(count($documents), $queues[$key]['count'], "count of {$key}");
            $this->assertSame($this->expected(...$documents), $this->itemIds($queues[$key]), "rows of {$key}");
            $this->assertNotEmpty($queues[$key]['link'], "{$key} links to a filtered list");

            foreach ($queues[$key]['items'] as $item) {
                $this->assertNotEmpty($item['link'], "{$key} row links to its document");
                $this->assertNotEmpty($item['number']);
            }
        }

        $this->assertSame('/approvals', $queues['approvals_pending']['link']);
        $this->assertSame("/invoices/{$this->ids['inv_overdue']}", collect($queues['overdue_invoices']['items'])
            ->firstWhere('id', $this->ids['inv_overdue'])['link']);
        // Overdue invoices link on to customer follow-up (#328).
        $this->assertSame('/accounting/customers/followup-reports', $queues['overdue_invoices']['secondary_link']);
    }

    public function test_top_n_keeps_the_full_count_and_shows_the_most_urgent_first(): void
    {
        $queues = $this->queues($this->admin, '?limit=1');

        $this->assertSame(2, $queues['overdue_invoices']['count']);
        $this->assertCount(1, $queues['overdue_invoices']['items']);
        // Oldest due first: 10 days overdue before 3 days overdue.
        $this->assertSame($this->ids['inv_overdue'], $queues['overdue_invoices']['items'][0]['id']);

        $this->assertSame(2, $queues['quotes_expiring']['count']);
        // Soonest expiry first: 3 days before 5 days.
        $this->assertSame($this->ids['q_expiring'], $queues['quotes_expiring']['items'][0]['id']);
    }

    public function test_each_tile_opens_the_matching_filtered_list(): void
    {
        $queues = $this->queues($this->admin);

        foreach ($queues as $key => $queue) {
            if ($key === 'approvals_pending') {
                continue; // the approvals inbox is itself the pending queue
            }

            // The tile link is a frontend route; its query string is what the
            // list page forwards to its API endpoint.
            $query = parse_url($queue['link'], PHP_URL_QUERY);
            $api = match (true) {
                str_starts_with($queue['link'], '/sales/quotes') => '/api/v1/quotations',
                str_starts_with($queue['link'], '/sales/orders') => '/api/v1/sales/orders',
                str_starts_with($queue['link'], '/delivery-orders') => '/api/v1/sales/delivery-orders',
                str_starts_with($queue['link'], '/invoices') => '/api/v1/invoices',
            };

            $this->assertSame(
                $this->itemIds($queue),
                $this->listIds($this->admin, $api.'?per_page=100&'.$query),
                "{$key}: the list opened by the tile shows exactly the counted documents"
            );
        }
    }

    public function test_queues_are_scoped_to_the_callers_legal_entities(): void
    {
        $scoped = $this->makeUser('admin', [$this->entityA]);

        $queues = $this->queues($scoped);

        $this->assertSame($this->expected('q_draft'), $this->itemIds($queues['quotes_to_send']));
        $this->assertSame($this->expected('q_expiring'), $this->itemIds($queues['quotes_expiring']));
        $this->assertSame($this->expected('so_to_deliver'), $this->itemIds($queues['orders_to_deliver']));
        $this->assertSame($this->expected('so_to_invoice'), $this->itemIds($queues['orders_to_invoice']));
        $this->assertSame($this->expected('inv_to_send'), $this->itemIds($queues['invoices_to_send']));
        $this->assertSame($this->expected('inv_overdue'), $this->itemIds($queues['overdue_invoices']));

        // The list behind the tile is scoped the same way.
        $this->assertSame(
            $this->expected('inv_overdue'),
            $this->listIds($scoped, '/api/v1/invoices?'.parse_url($queues['overdue_invoices']['link'], PHP_URL_QUERY)),
        );
    }

    public function test_mine_restricts_to_the_callers_own_documents(): void
    {
        $queues = $this->queues($this->salesUser, '?mine=1');

        // Customer B is the salesperson's customer; the approval was raised by them.
        $this->assertSame($this->expected('q_draft_expiring'), $this->itemIds($queues['quotes_to_send']));
        $this->assertSame($this->expected('so_ordered_policy'), $this->itemIds($queues['orders_to_deliver']));
        $this->assertSame($this->expected('inv_overdue_b'), $this->itemIds($queues['overdue_invoices']));
        $this->assertSame([], $this->itemIds($queues['deliveries_to_invoice']));
        $this->assertSame($this->expected('apr_pending'), $this->itemIds($queues['approvals_pending']));
        $this->assertStringContainsString('mine=1', $queues['quotes_to_send']['link']);
    }

    public function test_queues_follow_the_callers_permissions(): void
    {
        // Accountant: reports.view but not sales.view — quotation and invoice
        // queues only, no order/delivery queues.
        $accountant = $this->makeUser('accountant');
        $queues = $this->queues($accountant);
        $this->assertArrayHasKey('quotes_to_send', $queues);
        $this->assertArrayHasKey('overdue_invoices', $queues);
        $this->assertArrayNotHasKey('orders_to_deliver', $queues);
        $this->assertArrayNotHasKey('orders_to_invoice', $queues);
        $this->assertArrayNotHasKey('deliveries_to_invoice', $queues);

        // Cashier holds neither reports.view nor sales.view.
        $cashier = $this->makeUser('cashier');
        $this->actingAs($cashier)->getJson('/api/v1/sales/dashboard/action-queues')->assertForbidden();
    }
}
