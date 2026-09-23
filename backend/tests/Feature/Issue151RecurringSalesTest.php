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

    /**
     * Issue #151 (hostile re-review): the generated order dropped pricing and
     * tax fields — every cycle was repriced without its discount or tax.
     */
    public function test_generated_order_preserves_pricing_and_tax_fields(): void
    {
        $source = SalesOrder::create([
            'sales_order_number' => 'SO-151-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'status' => 'invoiced',
            'order_date' => now()->subMonth()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 180,
            'tax_amount' => 18,
            'discount_amount' => 20,
            'total' => 198,
            'created_by' => $this->user->id,
        ]);

        $sourceLine = SalesOrderLine::create([
            'sales_order_id' => $source->id,
            'line_type' => 'product',
            'sequence' => 3,
            'product_id' => $this->product->id,
            'model_number' => 'MOD-151',
            'description' => 'Monthly retainer with discount',
            'product_notes' => 'Handle with care',
            'sku' => 'SKU-151',
            'quantity' => 2,
            'unit' => 'box',
            'unit_price' => 100,
            'list_price' => 110,
            'discount_type' => 'fixed',
            // discount_amount is derived from discount_value when value > 0
            // (calculateLineTotal), so the fixture must be internally
            // consistent for a preservation assertion to be meaningful.
            'discount_value' => 20,
            'discount_amount' => 20,
            'tax_rate' => 9,
            'tax_inclusive' => false,
            'invoicing_policy' => 'ordered_qty',
            'line_total' => 196.2,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/sales/orders/'.$source->id.'/recurrence', [
            'interval' => 'monthly',
            'start_date' => now()->toDateString(),
        ])->assertStatus(201);

        app(RecurringSalesService::class)->generateDue(now());

        $recurrence = SalesOrderRecurrence::firstOrFail();
        $generated = SalesOrder::findOrFail($recurrence->generated_order_ids[0]);
        $line = $generated->lines()->firstOrFail();

        $this->assertSame('product', $line->line_type);
        $this->assertSame(3, (int) $line->sequence);
        $this->assertSame('MOD-151', $line->model_number);
        $this->assertSame('SKU-151', $line->sku);
        $this->assertSame('Handle with care', $line->product_notes);
        $this->assertSame('box', $line->unit);
        $this->assertSame(110.0, round((float) $line->list_price, 2), 'list_price must be preserved.');
        $this->assertSame('fixed', $line->discount_type, 'discount_type must be preserved.');
        $this->assertSame(20.0, round((float) $line->discount_value, 2), 'discount_value must be preserved.');
        $this->assertSame(20.0, round((float) $line->discount_amount, 2), 'discount_amount must be preserved.');
        $this->assertSame(9.0, round((float) $line->tax_rate, 2), 'tax_rate must be preserved.');
        $this->assertSame('ordered_qty', $line->invoicing_policy);

        // 2 x 100 - 20 discount = 180 net, + 9% tax = 196.20.
        $this->assertSame(196.2, round((float) $line->line_total, 2), 'Discounted + taxed line total must survive generation.');
    }

    /**
     * Issue #151 (hostile re-review): addMonth() overflows month ends —
     * 31 Jan drifted to 3 Mar and kept drifting.
     */
    public function test_month_end_recurrence_does_not_overflow(): void
    {
        $source = $this->sourceOrder();

        $recurrence = SalesOrderRecurrence::create([
            'sales_order_id' => $source->id,
            'customer_id' => $this->customer->id,
            'interval' => 'monthly',
            'start_date' => '2026-01-31',
            'next_run_date' => '2026-01-31',
            'status' => 'active',
            'cycles_generated' => 0,
            'created_by' => $this->user->id,
        ]);

        $this->assertSame('2026-02-28', $recurrence->advanceNextRunDate()->toDateString(),
            '31 Jan + 1 month must clamp to 28 Feb, not overflow to 3 Mar.');

        $recurrence->update(['next_run_date' => '2026-02-28']);
        $this->assertSame('2026-03-28', $recurrence->fresh()->advanceNextRunDate()->toDateString(),
            'The clamped date must not drift on the next cycle.');

        $recurrence->update(['next_run_date' => '2026-01-31', 'interval' => 'quarterly']);
        $this->assertSame('2026-04-30', $recurrence->fresh()->advanceNextRunDate()->toDateString(),
            '31 Jan + 1 quarter must clamp to 30 Apr.');

        $recurrence->update(['next_run_date' => '2024-02-29', 'interval' => 'annual']);
        $this->assertSame('2025-02-28', $recurrence->fresh()->advanceNextRunDate()->toDateString(),
            '29 Feb + 1 year must clamp to 28 Feb, not overflow to 1 Mar.');
    }
}
