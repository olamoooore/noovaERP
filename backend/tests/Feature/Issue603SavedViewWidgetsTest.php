<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LegalEntity;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #603 (RPT-UX3 companion) — favourite saved views and dashboard
 * widgets on the Sales cockpit.
 *
 * Everything below exercises the real HTTP routes (router → middleware →
 * controller) against the #598 `report_saved_views` storage. A favourite is
 * a view-level flag (owner-only, mirrors every other saved-view mutation);
 * a dashboard widget is a per-user pin of a view the user can see and is
 * re-authorised on every render (reports.view comes from the route group,
 * the view's visibility and its legal-entity filter are checked per request).
 */
class Issue603SavedViewWidgetsTest extends TenantTestCase
{
    private const BASE = '/api/v1/reports/saved-views';

    private const WIDGETS = '/api/v1/reports/dashboard/widgets';

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));

        $this->tenantId = (string) Str::uuid();

        Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => $this->tenantId,
            'name' => 'Issue 603 Tenant',
        ]));

        $this->bindTenantContextWithPermissions($this->tenantId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            [
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $user;
    }

    private function createView(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Issue 603 view',
            'report_key' => 'sales-analytics',
            'tab' => 'by-customer',
            'filters' => [
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-05',
            ],
            'grouping' => null,
            'visibility' => 'personal',
            'is_default' => false,
        ], $overrides);

        $response = $this->postJson(self::BASE, $payload);

        $response->assertStatus(201);

        return $response->json('data');
    }

    private function pinView(int $viewId, int $expectedStatus = 201): array
    {
        $response = $this->postJson(self::WIDGETS, ['view_id' => $viewId]);

        $response->assertStatus($expectedStatus);

        return $response->json('data');
    }

    private function makeSale(User $seller, string $customerName, float $total, ?int $entityId = null): void
    {
        $product = Product::factory()->create(['name' => 'Issue 603 Product', 'price' => 1, 'cost' => 0]);

        $customer = \App\Models\Customer::factory()->create([
            'name' => $customerName,
            'salesperson_id' => $seller->id,
            'legal_entity_id' => $entityId,
        ]);

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-603-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'legal_entity_id' => $entityId,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'order_date' => '2026-10-03',
            'created_by' => $seller->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'description' => 'Issue 603 line',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);
    }

    // ── Favourites ────────────────────────────────────────────────────────

    public function test_favourite_toggle_persists_and_favourite_views_list_first(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $alpha = $this->createView(['name' => 'Alpha view', 'is_default' => false]);
        $bravo = $this->createView(['name' => 'Bravo view', 'is_default' => true]);

        // Before favouriting, the default view lists first.
        $before = $this->getJson(self::BASE)->assertStatus(200);
        $this->assertSame($bravo['id'], $before->json('data.views.0.id'));
        $this->assertFalse($before->json('data.views.0.is_favourite'));

        $toggled = $this->postJson(self::BASE."/{$alpha['id']}/favourite")->assertStatus(200);
        $this->assertTrue($toggled->json('data.is_favourite'));

        $this->assertDatabaseHas('report_saved_views', [
            'id' => $alpha['id'],
            'is_favourite' => true,
        ]);

        $after = $this->getJson(self::BASE)->assertStatus(200);
        $this->assertSame($alpha['id'], $after->json('data.views.0.id'), 'Favourite views must list first.');
        $this->assertTrue($after->json('data.views.0.is_favourite'));

        // Favouriting must not move the default: the default view still
        // applies (default_view_id + is_default flags are untouched).
        $this->assertSame($bravo['id'], $after->json('data.default_view_id'));
        $this->assertTrue($after->json('data.views.1.is_default'));

        // Toggling again clears the flag.
        $toggledOff = $this->postJson(self::BASE."/{$alpha['id']}/favourite")->assertStatus(200);
        $this->assertFalse($toggledOff->json('data.is_favourite'));
        $this->assertDatabaseHas('report_saved_views', [
            'id' => $alpha['id'],
            'is_favourite' => false,
        ]);
    }

    public function test_favourite_toggle_is_owner_only(): void
    {
        $admin = $this->makeUser('admin');
        $viewer = $this->makeUser('viewer');

        $this->actingAs($admin);
        $shared = $this->createView(['name' => 'Team favourite', 'visibility' => 'shared']);

        $this->actingAs($viewer);
        $this->postJson(self::BASE."/{$shared['id']}/favourite")->assertStatus(403);
        $this->assertDatabaseHas('report_saved_views', [
            'id' => $shared['id'],
            'is_favourite' => false,
        ]);

        $this->actingAs($admin);
        $this->postJson(self::BASE."/{$shared['id']}/favourite")->assertStatus(200);
    }

    // ── Dashboard widgets ─────────────────────────────────────────────────

    public function test_pin_places_a_widget_on_the_dashboard(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $view = $this->createView(['name' => 'Pinned view']);
        $widget = $this->pinView($view['id']);

        $this->assertSame($view['id'], $widget['view_id']);
        $this->assertSame($view['id'], $widget['view']['id']);
        $this->assertSame('Pinned view', $widget['view']['name']);
        $this->assertTrue($widget['view']['is_pinned']);

        $this->assertDatabaseHas('report_dashboard_widgets', [
            'user_id' => $admin->id,
            'view_id' => $view['id'],
        ]);

        // Pinning the same view twice is idempotent (no duplicate widget).
        $this->pinView($view['id'], 200);
        $this->assertSame(1, DB::table('report_dashboard_widgets')
            ->where('user_id', $admin->id)
            ->where('view_id', $view['id'])
            ->count());

        $list = $this->getJson(self::WIDGETS)->assertStatus(200);
        $this->assertCount(1, $list->json('data'));
        $this->assertTrue($list->json('data.0.view.is_pinned'));
    }

    public function test_shared_views_can_be_pinned_by_other_users_but_personal_views_cannot(): void
    {
        $admin = $this->makeUser('admin');
        $viewer = $this->makeUser('viewer');

        $this->actingAs($admin);
        $shared = $this->createView(['name' => 'Shared cockpit view', 'visibility' => 'shared']);
        $private = $this->createView(['name' => 'Private cockpit view', 'visibility' => 'personal']);

        $this->actingAs($viewer);
        $this->pinView($shared['id']);

        // Another user's personal view is not visible and cannot be pinned.
        $this->postJson(self::WIDGETS, ['view_id' => $private['id']])->assertStatus(404);

        $viewerWidgets = $this->getJson(self::WIDGETS)->assertStatus(200);
        $this->assertCount(1, $viewerWidgets->json('data'));
        $this->assertSame($shared['id'], $viewerWidgets->json('data.0.view_id'));

        // The pin is personal: the owner's dashboard is unaffected.
        $this->actingAs($admin);
        $this->assertCount(0, $this->getJson(self::WIDGETS)->assertStatus(200)->json('data'));
    }

    public function test_widget_renders_the_saved_views_data(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $this->makeSale($admin, 'Cockpit Customer', 432.10);

        $view = $this->createView([
            'name' => 'Cockpit customers',
            'tab' => 'by-customer',
            'filters' => ['start_date' => '2026-10-01', 'end_date' => '2026-10-05'],
        ]);
        $widget = $this->pinView($view['id']);

        $response = $this->getJson(self::WIDGETS."/{$widget['id']}/data")->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame($widget['id'], $data['widget_id']);
        $this->assertSame($view['id'], $data['view']['id']);
        $this->assertSame('Sales by Customer', $data['title']);
        $this->assertSame('by-customer', $data['tab']);
        $this->assertSame(1, $data['row_count']);
        $this->assertContains('Customer', $data['columns']);
        $this->assertSame('Cockpit Customer', $data['rows'][0]['Customer']);
    }

    public function test_widget_data_respects_entity_access(): void
    {
        $admin = $this->makeUser('admin');
        $viewer = $this->makeUser('viewer');

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 603 Group',
            'code' => 'I603-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entityA = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => 'A603-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ]);
        $entityB = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => 'B603-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ]);

        // The viewer is scoped to entity B only.
        DB::table('user_entity_access')->insert([
            'user_id' => $viewer->id,
            'legal_entity_id' => $entityB->id,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeSale($admin, 'Entity A Customer', 100.00, $entityA->id);

        $this->actingAs($admin);
        $view = $this->createView([
            'name' => 'Entity A view',
            'visibility' => 'shared',
            'filters' => [
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-05',
                'legal_entity_id' => $entityA->id,
            ],
        ]);

        // The viewer may pin the shared view...
        $this->actingAs($viewer);
        $viewerWidget = $this->pinView($view['id']);

        // ...but rendering it must refuse: the saved filters point at an
        // entity the viewer has no grant for.
        $this->getJson(self::WIDGETS."/{$viewerWidget['id']}/data")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        // The owner (unrestricted entity scope) renders their own pin of the
        // same view. Widgets are per-user, so the owner pins it separately.
        $this->actingAs($admin);
        $adminWidget = $this->pinView($view['id']);
        $data = $this->getJson(self::WIDGETS."/{$adminWidget['id']}/data")
            ->assertStatus(200)
            ->json('data');
        $this->assertSame('Entity A Customer', $data['rows'][0]['Customer']);
    }

    public function test_widgets_require_reports_view_permission(): void
    {
        $cashier = $this->makeUser('cashier');
        $this->actingAs($cashier);

        $this->getJson(self::WIDGETS)->assertStatus(403);
        $this->postJson(self::WIDGETS, ['view_id' => 1])->assertStatus(403);
    }

    public function test_widget_data_and_unpin_are_private_to_the_pinning_user(): void
    {
        $admin = $this->makeUser('admin');
        $viewer = $this->makeUser('viewer');

        $this->actingAs($admin);
        $view = $this->createView(['name' => 'Owner widget', 'visibility' => 'shared']);
        $widget = $this->pinView($view['id']);

        $this->actingAs($viewer);
        $this->getJson(self::WIDGETS."/{$widget['id']}/data")->assertStatus(404);
        $this->deleteJson(self::WIDGETS."/{$widget['id']}")->assertStatus(404);
        $this->assertDatabaseHas('report_dashboard_widgets', ['id' => $widget['id']]);

        $this->actingAs($admin);
        $this->deleteJson(self::WIDGETS."/{$widget['id']}")->assertStatus(200);
        $this->assertDatabaseMissing('report_dashboard_widgets', ['id' => $widget['id']]);
        $this->assertCount(0, $this->getJson(self::WIDGETS)->assertStatus(200)->json('data'));
    }

    public function test_reorder_changes_widget_order_and_rejects_foreign_widgets(): void
    {
        $admin = $this->makeUser('admin');
        $viewer = $this->makeUser('viewer');

        $this->actingAs($admin);
        $first = $this->createView(['name' => 'First cockpit view']);
        $second = $this->createView(['name' => 'Second cockpit view']);

        $firstWidget = $this->pinView($first['id']);
        $secondWidget = $this->pinView($second['id']);

        $reordered = $this->putJson(self::WIDGETS.'/reorder', [
            'widget_ids' => [$secondWidget['id'], $firstWidget['id']],
        ])->assertStatus(200);

        $this->assertSame($secondWidget['id'], $reordered->json('data.0.id'));
        $this->assertSame($firstWidget['id'], $reordered->json('data.1.id'));
        $this->assertSame([0, 1], [
            $reordered->json('data.0.position'),
            $reordered->json('data.1.position'),
        ]);

        $list = $this->getJson(self::WIDGETS)->assertStatus(200);
        $this->assertSame($secondWidget['id'], $list->json('data.0.id'));

        // A foreign widget id cannot be smuggled into the reorder.
        $this->actingAs($viewer);
        $this->putJson(self::WIDGETS.'/reorder', [
            'widget_ids' => [$firstWidget['id'], $secondWidget['id']],
        ])->assertStatus(403);

        $this->assertDatabaseHas('report_dashboard_widgets', [
            'id' => $firstWidget['id'],
            'position' => 1,
        ]);
    }
}
