<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Models\DeliveryOrder;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #464 (DO12): DO COGS was posted with `now()` for both the transaction and
 * posting date, and the reversal used `now()` too — so a back-dated delivery
 * booked its cost in the current period while the invoice revenue sat in the
 * delivery period, and the period close never matched.
 *
 * Policy: COGS books on the shipping document's delivery_date when set,
 * otherwise the completion timestamp. The engine's lock-date/fiscal-period
 * gates then reject a delivery dated inside a locked period instead of
 * silently shifting the cost to today. The reversal always posts in the
 * current (open) period and carries the original COGS entry/date in its
 * ledger description.
 *
 * Router-level: postJson through middleware -> controller, not source strings.
 */
class DeliveryOrderCogsDeliveryDateTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'DO COGS Date Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->warehouse = Warehouse::factory()->create(['is_default' => true]);
        $this->product = Product::factory()->create([
            'name' => 'COGS Date Widget',
            'sku' => 'CDW-'.uniqid(),
            'price' => 100,
            'cost' => 10,
            'track_inventory' => true,
        ]);
        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.delivery_order.default_requires_approval', false);
    }

    private function createConfirmedDeliveryOrder(string $deliveryDate): int
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => $deliveryDate,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => 'COGS date line',
                'quantity_delivered' => 2,
                'unit_price' => 100,
            ]],
        ]);
        $create->assertStatus(201, $create->getContent());
        $deliveryOrderId = (int) $create->json('data.id');

        $confirm = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/confirm", []);
        $confirm->assertStatus(200, $confirm->getContent());

        return $deliveryOrderId;
    }

    private function cogsPostLink(int $deliveryOrderId): ?JournalEntryLink
    {
        return JournalEntryLink::query()
            ->where('source_document_type', 'delivery_order')
            ->where('source_document_id', $deliveryOrderId)
            ->where('relation_type', 'post')
            ->first();
    }

    private function stockOnHand(): float
    {
        return (float) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    public function test_back_dated_delivery_posts_cogs_on_the_delivery_date(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));
        $deliveryDate = '2026-05-20';

        $deliveryOrderId = $this->createConfirmedDeliveryOrder($deliveryDate);

        $complete = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete");
        $complete->assertStatus(200, $complete->getContent());

        $link = $this->cogsPostLink($deliveryOrderId);
        $this->assertNotNull($link, 'Completing a delivery must post a COGS journal entry.');

        $entry = JournalEntry::findOrFail($link->journal_entry_id);
        $this->assertSame(
            $deliveryDate,
            $entry->entry_date->toDateString(),
            'Back-dated DO COGS must post on the delivery date, not the completion date.'
        );
        $this->assertSame(20.0, round((float) $entry->lines()->sum('debit'), 2), '2 units x cost 10 = 20.00 COGS.');
    }

    public function test_same_period_delivery_books_cogs_on_that_date(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));
        $deliveryDate = '2026-06-15';

        $deliveryOrderId = $this->createConfirmedDeliveryOrder($deliveryDate);

        $complete = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete");
        $complete->assertStatus(200, $complete->getContent());

        $link = $this->cogsPostLink($deliveryOrderId);
        $this->assertNotNull($link);
        $entry = JournalEntry::findOrFail($link->journal_entry_id);

        $this->assertSame($deliveryDate, $entry->entry_date->toDateString());
    }

    public function test_reversal_after_period_close_posts_in_the_open_period_and_references_the_original_date(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));
        $deliveryDate = '2026-05-20';
        $today = '2026-06-15';

        $deliveryOrderId = $this->createConfirmedDeliveryOrder($deliveryDate);

        $complete = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete");
        $complete->assertStatus(200, $complete->getContent());

        $originalLink = $this->cogsPostLink($deliveryOrderId);
        $this->assertNotNull($originalLink);
        $originalEntry = JournalEntry::findOrFail($originalLink->journal_entry_id);
        $this->assertSame($deliveryDate, $originalEntry->entry_date->toDateString());

        // Close the delivery period (the COGS posting auto-provisioned it open).
        $deliveryPeriod = FiscalPeriod::query()
            ->where('period_start', '<=', $deliveryDate)
            ->where('period_end', '>=', $deliveryDate)
            ->firstOrFail();
        $deliveryPeriod->close();

        // The current period the reversal must land in is open.
        $this->assertTrue(
            FiscalPeriod::query()
                ->where('status', 'open')
                ->where('period_start', '<=', $today)
                ->where('period_end', '>=', $today)
                ->exists(),
            'The test needs an open current period for the reversal to post in.'
        );

        $reverse = $this->actingAs($this->admin)->postJson(
            "/api/v1/sales/delivery-orders/{$deliveryOrderId}/reverse",
            ['reason' => 'Back-dated delivery cancelled'],
        );
        $reverse->assertStatus(200, $reverse->getContent());

        $reversalLink = JournalEntryLink::query()
            ->where('original_journal_entry_id', $originalEntry->id)
            ->where('relation_type', 'reversal')
            ->firstOrFail();
        $reversal = JournalEntry::findOrFail($reversalLink->journal_entry_id);

        $this->assertSame(
            $today,
            $reversal->entry_date->toDateString(),
            'The reversal must post in the current (open) period, never back into the closed delivery period.'
        );
        $this->assertStringContainsString(
            $originalEntry->entry_number,
            (string) $reversal->reference,
            'The reversal must reference the original COGS entry.'
        );
        $this->assertStringContainsString(
            $deliveryDate,
            (string) $reversal->description,
            'The reversal ledger description must carry the original COGS date.'
        );
    }

    public function test_completing_a_delivery_dated_in_a_locked_period_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));
        $deliveryDate = '2026-05-20';

        // The delivery period is locked; the current period stays open so the
        // pre-fix "post at now()" path would have succeeded by bypassing it.
        FiscalPeriod::create([
            'code' => '2026-05-464',
            'name' => 'May 2026 (locked)',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2026,
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => $this->admin->id,
            'status' => 'locked',
            'created_by' => $this->admin->id,
        ]);
        FiscalPeriod::create([
            'code' => '2026-06-464',
            'name' => 'June 2026',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'period_type' => 'monthly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);

        $deliveryOrderId = $this->createConfirmedDeliveryOrder($deliveryDate);
        $stockBefore = $this->stockOnHand();

        $complete = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete");

        $this->assertSame(
            422,
            $complete->status(),
            'Completing a delivery dated in a locked period must be rejected by the posting engine.'
        );
        $this->assertNull(
            $this->cogsPostLink($deliveryOrderId),
            'A rejected completion must not post COGS.'
        );
        $this->assertSame('confirmed', DeliveryOrder::findOrFail($deliveryOrderId)->status);
        $this->assertSame(
            $stockBefore,
            $this->stockOnHand(),
            'The rejected completion must roll back its stock movement.'
        );
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }
}
