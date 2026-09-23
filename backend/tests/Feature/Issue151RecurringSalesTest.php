<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Sales\Models\SalesOrderRecurrence;
use Modules\Sales\Services\RecurringSalesService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #151 — recurring/subscription sales: a recurrence on a sales order
 * generates the next order on each billing-cycle boundary, with
 * active/paused/cancelled lifecycle and cycle caps.
 */
class Issue151RecurringSalesTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '15115151-5152-5354-5556-575859606162',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'sales_order_recurrences', 'sales_orders', 'sales_order_lines', 'customers', 'products',
        ]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create(['price' => 100.00, 'cost' => 40.00]);
    }

    private function sourceOrder(): SalesOrder
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-151-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'status' => 'invoiced',
            'order_date' => now()->subMonth()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 200,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 200,
            'created_by' => $this->user->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Monthly retainer',
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 0,
            'line_total' => 200,
        ]);

        return $order;
    }

    public function test_due_recurrence_generates_the_next_order_and_advances_the_cycle(): void
    {
        $source = $this->sourceOrder();

        $this->actingAs($this->user)->postJson('/api/v1/sales/orders/'.$source->id.'/recurrence', [
            'interval' => 'monthly',
            'start_date' => now()->toDateString(),
        ])->assertStatus(201);

        app(RecurringSalesService::class)->generateDue(now());

        $recurrence = SalesOrderRecurrence::firstOrFail();
        $this->assertSame(1, $recurrence->cycles_generated);
        $this->assertSame(1, count($recurrence->generated_order_ids));
        $this->assertSame(now()->addMonth()->toDateString(), $recurrence->next_run_date->toDateString());

        $generated = SalesOrder::findOrFail($recurrence->generated_order_ids[0]);
        $this->assertNotSame($source->id, $generated->id);
        $this->assertSame($this->customer->id, $generated->customer_id);
        $this->assertSame(2.0, round((float) $generated->lines()->first()->quantity, 4));
        $this->assertSame(100.0, round((float) $generated->lines()->first()->unit_price, 2));

        // Idempotent: running again the same day must not double-generate.
        app(RecurringSalesService::class)->generateDue(now());
        $this->assertSame(1, $recurrence->fresh()->cycles_generated);
        $this->assertSame(2, SalesOrder::count(), 'Source + one generated order only.');
    }

    public function test_max_cycles_completes_the_recurrence(): void
    {
        $source = $this->sourceOrder();

        $this->actingAs($this->user)->postJson('/api/v1/sales/orders/'.$source->id.'/recurrence', [
            'interval' => 'monthly',
            'start_date' => now()->toDateString(),
            'max_cycles' => 1,
        ])->assertStatus(201);

        app(RecurringSalesService::class)->generateDue(now());

        $recurrence = SalesOrderRecurrence::firstOrFail();
        $this->assertSame('completed', $recurrence->status);

        app(RecurringSalesService::class)->generateDue(now());
        $this->assertSame(1, $recurrence->fresh()->cycles_generated, 'A completed recurrence must not generate again.');
    }

    public function test_the_generation_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $event = $events->first(fn ($event) => str_contains((string) $event->command, 'sales:generate-recurring-orders'));

        $this->assertNotNull($event, 'sales:generate-recurring-orders must be registered on the scheduler.');
        $this->assertSame('30 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_paused_and_cancelled_recurrences_do_not_generate(): void
    {
        $source = $this->sourceOrder();

        $create = $this->actingAs($this->user)->postJson('/api/v1/sales/orders/'.$source->id.'/recurrence', [
            'interval' => 'monthly',
            'start_date' => now()->toDateString(),
        ]);
        $recurrenceId = (int) $create->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/sales/recurrences/{$recurrenceId}/pause")->assertOk();
        app(RecurringSalesService::class)->generateDue(now());
        $this->assertSame(0, SalesOrderRecurrence::findOrFail($recurrenceId)->cycles_generated);

        $this->actingAs($this->user)->postJson("/api/v1/sales/recurrences/{$recurrenceId}/resume")->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/sales/recurrences/{$recurrenceId}/cancel")->assertOk();
        app(RecurringSalesService::class)->generateDue(now());
        $this->assertSame(0, SalesOrderRecurrence::findOrFail($recurrenceId)->cycles_generated);
        $this->assertSame(1, SalesOrder::count());
    }
}
