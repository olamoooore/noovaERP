<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\TenantRoleService;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 2, hostile review of the tax decision paper (D3).
 *
 * POS posting calls TaxDeterminationService::determineForLineSet() with only
 * the customer, so the BUYER's billing country becomes the jurisdiction of
 * the tax trail row. The statutory returns select trail rows by
 * jurisdiction (Sst02ReturnService / GstF5ReturnService → TaxReturnAggregator
 * `upper(ttd.jurisdiction) = ?`), so tax a MY seller collected at its own
 * till can be left out of the MY return (asserted through the SST-02 route
 * below):
 *  - a buyer whose country is typed as "Malaysia" → jurisdiction "MALAYSIA";
 *  - a correctly coded foreign buyer ("SG") → jurisdiction "SG".
 *
 * Rule asserted: tax the seller booked on a POS sale is recorded under the
 * seller's jurisdiction (the control sale's), whatever the buyer's country.
 * Whether a foreign buyer should be charged at all is decision D3 for the
 * tax agent and is NOT asserted.
 *
 * Every step goes through the real POS routes (setup and helpers copied from
 * PosSmokeSuiteTest).
 */
class TaxPosBuyerJurisdictionTest extends TenantTestCase
{
    protected User $cashier;

    protected User $manager;

    protected Product $product;

    protected PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockFeatureGate(true);
        $this->ensureFeaturesTableExists();
        Notification::fake();

        if (Schema::hasTable('pos_orders') && ! Schema::hasColumn('pos_orders', 'deleted_at')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('pos_orders')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('pos_orders', 'payment_state')) {
                    $table->string('payment_state')->nullable();
                }
                if (! Schema::hasColumn('pos_orders', 'payment_method')) {
                    $table->string('payment_method')->nullable();
                }
                if (! Schema::hasColumn('pos_orders', 'amount_tendered')) {
                    $table->decimal('amount_tendered', 15, 2)->nullable();
                }
                if (! Schema::hasColumn('pos_orders', 'change_due')) {
                    $table->decimal('change_due', 15, 2)->nullable();
                }
                // Make external_id nullable for return orders created by void/refund flow
                if (Schema::hasColumn('pos_orders', 'external_id')) {
                    DB::statement('ALTER TABLE pos_orders ALTER COLUMN external_id DROP NOT NULL');
                }
                // Drop restrictive status check constraint to allow return order statuses
                DB::statement('ALTER TABLE pos_orders DROP CONSTRAINT IF EXISTS pos_orders_status_check');

                // Add void columns if missing
                foreach (['voided_by', 'void_approved_by'] as $col) {
                    if (! Schema::hasColumn('pos_orders', $col)) {
                        $table->foreignId($col)->nullable()->constrained('users');
                    }
                }
                if (! Schema::hasColumn('pos_orders', 'voided_at')) {
                    $table->timestamp('voided_at')->nullable();
                }
                if (! Schema::hasColumn('pos_orders', 'void_reason')) {
                    $table->string('void_reason')->nullable();
                }
            });
        }

        // Drop restrictive payment method check constraint to allow refund method
        if (Schema::hasTable('pos_payments')) {
            DB::statement('ALTER TABLE pos_payments DROP CONSTRAINT IF EXISTS pos_payments_method_check');
        }

        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);

        // Mock TenantRoleService so RequirePermission middleware always passes
        $roleMock = \Mockery::mock(TenantRoleService::class);
        $roleMock->shouldReceive('hasPermission')->andReturn(true);
        $roleMock->shouldReceive('hasAnyPermission')->andReturn(true);
        app()->instance(TenantRoleService::class, $roleMock);

        // Create roles and permissions
        $posCreate = Permission::firstOrCreate(['name' => 'pos.create', 'guard_name' => 'web']);
        $cashier = Role::firstOrCreate(['name' => 'cashier']);
        $cashier->givePermissionTo($posCreate);
        $managerRole = Role::firstOrCreate(['name' => 'manager']);
        $managerRole->givePermissionTo($posCreate);

        // Create users
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');

        // Create test data
        // Tenant home jurisdiction: MY (the seller).
        \App\Models\Setting::setValue('tax', array_merge((array) \App\Models\Setting::getValue('tax', []), ['jurisdiction' => 'MY']));
        $this->product = Product::factory()->create([
            'price' => 100.00,
            'tax_rate' => 10.00,
            'stock_quantity' => 1000,
        ]);

        $warehouse = \App\Models\Warehouse::factory()->create();
        $this->terminal = PosTerminal::factory()->create(['warehouse_id' => $warehouse->id]);

        $this->ensureOpenFiscalPeriodForToday();
    }

    public function test_control_pos_sale_is_recorded_under_the_seller(): void
    {
        $control = $this->trailRow($this->payOrder($this->openSession(), Customer::factory()->create()));

        $this->assertSame('MY', strtoupper((string) $control->jurisdiction), 'Control: a sale with no buyer country is recorded under the seller (MY).');
        $this->assertEquals(10.00, (float) $control->tax_amount);
    }

    public function test_buyer_country_typed_as_a_name_does_not_become_the_jurisdiction(): void
    {
        $named = $this->trailRow($this->payOrder($this->openSession(), $this->customerWithBillingCountry('Malaysia')));

        $this->assertSame(
            'MY',
            strtoupper((string) $named->jurisdiction),
            'Tax collected from a buyer whose country is typed "Malaysia" must be recorded under MY, not "MALAYSIA".'
        );
    }

    public function test_tax_collected_from_a_foreign_buyer_stays_in_the_sellers_jurisdiction(): void
    {
        $foreign = $this->trailRow($this->payOrder($this->openSession(), $this->customerWithBillingCountry('SG')));

        $this->assertSame(
            'MY',
            strtoupper((string) $foreign->jurisdiction),
            'Tax the MY seller collected at its own till must be recorded under MY so it is in the MY return, even for an SG buyer.'
        );
    }

    public function test_tax_collected_from_a_buyer_typed_malaysia_is_in_the_sst02_return(): void
    {
        // The product's tax comes from an activated MY pack rule (sales or
        // service tax) in force today, at that rule's rate — no rate is
        // invented here.
        $this->actingAs($this->manager)->postJson('/api/v1/accounting/tax-engine/packs/MY/activate')->assertOk();
        $today = now()->toDateString();
        $rule = \App\Models\Tax::query()
            ->where('jurisdiction', 'MY')->whereIn('type', ['sales', 'service'])
            ->where('is_active', true)->where('lifecycle_state', 'ACTIVE')->where('rate', '>', 0)
            ->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->orderBy('id')
            ->firstOrFail();
        $this->product->update(['tax_rate' => (float) $rule->rate, 'tax_id' => $rule->id]);

        $control = $this->trailRow($this->payOrder($this->openSession(), Customer::factory()->create(), null));
        $named = $this->trailRow($this->payOrder($this->openSession(), $this->customerWithBillingCountry('Malaysia'), null));
        $this->assertSame($control->reporting_period, $named->reporting_period);

        $return = $this->actingAs($this->manager)
            ->getJson('/api/v1/accounting/statutory-returns/MY/'.$control->reporting_period)
            ->assertOk();

        $this->assertSame(
            round((float) $control->tax_amount + (float) $named->tax_amount, 2),
            (float) $return->json('data.total_tax_payable'),
            'The MY seller\'s SST-02 must include the tax collected from both POS sales, including the buyer whose country is typed "Malaysia".'
        );
    }

    private function customerWithBillingCountry(string $country): Customer
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->manager)->postJson("/api/v1/customers/{$customer->id}/addresses", [
            'type' => 'billing',
            'street' => '1 Probe Road',
            'city' => 'Probe City',
            'country' => $country,
            'is_default' => true,
        ])->assertCreated();

        return $customer;
    }

    private function openSession(): PosSession
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/sessions/open', [
            'terminal_id' => $this->terminal->id,
            'opening_balance' => 100.00,
        ]);

        return PosSession::find($response->json('session.id'));
    }

    private function payOrder(PosSession $session, Customer $customer, ?float $amount = 110.00): PosOrder
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'session_id' => $session->id,
            'terminal_id' => $this->terminal->id,
            'customer_id' => $customer->id,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => 100.00,
            ]],
        ]);
        $orderId = $response->json('id');

        $pay = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => $amount ?? (float) PosOrder::findOrFail($orderId)->total,
        ]);
        $this->assertSame(200, $pay->status(), 'Payment failed: '.$pay->content());

        return PosOrder::findOrFail($orderId);
    }

    private function trailRow(PosOrder $order): object
    {
        $row = DB::table('tax_transaction_details')
            ->where('source_document_type', 'pos_order')
            ->where('source_document_id', $order->id)
            ->first();
        $this->assertNotNull($row, 'The POS sale must leave a tax trail row.');

        return $row;
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
        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'pos', 'source' => 'tier_included', 'name' => 'POS']] : []);
        $mock->shouldReceive('invalidate')->andReturnNull();
        app()->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        \App\Models\Feature::query()->updateOrCreate(
            ['key' => 'pos'],
            [
                'name' => 'POS',
                'description' => 'POS module feature gate for tests',
                'is_core' => false,
                'price' => 0,
            ]
        );
    }

    private function ensureOpenFiscalPeriodForToday(): void
    {
        if (! Schema::hasTable('fiscal_periods')) {
            return;
        }

        $today = now()->toDateString();
        $exists = DB::table('fiscal_periods')
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('fiscal_periods')->insert([
            'name' => now()->format('F Y'),
            'code' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => (int) now()->format('Y'),
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->cashier->id ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
