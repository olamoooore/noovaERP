<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #112 (MEDIUM) — the Master Dashboard Reports tab reads orders_today
 * (falling back to pos_orders_today) but the backend never returned either, so
 * the metric and its CSV export were silently always 0.
 */
class HR112DashboardOrdersTodayTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'HR112 Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function makeSalesOrder(): void
    {
        SalesOrder::create([
            'sales_order_number' => 'SO-HR112-'.Str::upper(Str::random(6)),
            'customer_id' => Customer::factory()->create()->id,
            'channel' => 'admin',
            'status' => 'draft',
            'accounting_status' => 'not_posted',
            'order_date' => now()->toDateString(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'created_by' => $this->user->id,
        ]);
    }

    private function makePosOrder(): void
    {
        // The schema requires a real terminal + session (FKs), so create the
        // minimal chain rather than faking ids.
        $terminalId = DB::table('pos_terminals')->insertGetId([
            'name' => 'HR112 Terminal',
            'code' => 'HR112-'.Str::upper(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionId = DB::table('pos_sessions')->insertGetId([
            'terminal_id' => $terminalId,
            'user_id' => $this->user->id,
            'session_number' => 'SESS-'.Str::upper(Str::random(6)),
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pos_orders')->insert([
            'external_id' => (string) Str::uuid(),
            'order_number' => 'POS-HR112-'.Str::upper(Str::random(6)),
            'session_id' => $sessionId,
            'terminal_id' => $terminalId,
            'status' => 'paid',
            'subtotal' => 0,
            'total' => 0,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeWebOrder(): void
    {
        DB::table('web_orders')->insert([
            'order_number' => 'WEB-HR112-'.Str::upper(Str::random(6)),
            'email' => 'web-'.uniqid().'@test.local',
            'status' => 'pending',
            'payment_status' => 'pending',
            'subtotal' => 0,
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_dashboard_returns_orders_today_across_channels(): void
    {
        $this->makeSalesOrder();
        $this->makeSalesOrder();
        $this->makePosOrder();
        $this->makeWebOrder();

        $response = $this->actingAs($this->user)->getJson('/api/v1/dashboard');
        $response->assertStatus(200, $response->getContent());

        $this->assertSame(4, (int) $response->json('orders_today'), '2 sales + 1 POS + 1 web order');
        $this->assertSame(1, (int) $response->json('pos_orders_today'));
    }

    public function test_orders_today_is_zero_when_no_orders_exist(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/dashboard');
        $response->assertStatus(200);

        $this->assertSame(0, (int) $response->json('orders_today'));
        $this->assertSame(0, (int) $response->json('pos_orders_today'));
    }

    public function test_yesterdays_orders_are_not_counted(): void
    {
        $this->makeSalesOrder();

        SalesOrder::query()->update(['created_at' => now()->subDay()]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/dashboard');
        $response->assertStatus(200);

        $this->assertSame(0, (int) $response->json('orders_today'));
    }
}
