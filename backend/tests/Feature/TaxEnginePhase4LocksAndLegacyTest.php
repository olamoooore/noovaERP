<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Services\PosPostingService;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * MRD Phase 4: tax period locks enforced at posting (R7.4), legacy tax_rates
 * migration (R10.2), consolidated sales.tax settings (R10.3), seeded
 * tax.view/tax.manage permissions (R7.5).
 */
class TaxEnginePhase4LocksAndLegacyTest extends TenantTestCase
{
    private User $user;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['tax_period_locks', 'tax_transaction_details', 'tax_rates']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['pos.create', 'accounting.write', 'reports.view', 'reports.manage', 'settings.write', 'settings.view', 'tax.view', 'tax.manage'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        foreach (
            [
                'cash' => ['1000-P4', 'asset'],
                'accounts_receivable' => ['1100-P4', 'asset'],
                'sales_tax_payable' => ['2200b-P4', 'liability'],
                'sales_revenue' => ['3000-P4', 'revenue'],
                'general_expense' => ['4000-P4', 'expense'],
            ] as $key => [$code, $type]
        ) {
            $account = ChartOfAccount::create([
                'code' => $code, 'name' => "P4 {$key}", 'type' => $type,
                'account_type' => $type, 'is_active' => true,
            ]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id, 'is_active' => true]);
        }

        $this->period = now()->format('Y-m');
    }

    private function postPosOrder(float $rate): void
    {
        $terminal = PosTerminal::create(['name' => 'P4 T', 'code' => 'P4T-'.Str::random(5), 'is_active' => true]);
        $session = PosSession::create([
            'terminal_id' => $terminal->id, 'user_id' => $this->user->id,
            'session_number' => 'P4S-'.Str::random(5), 'status' => 'open',
            'opening_balance' => 0, 'opened_at' => now(),
        ]);

        $product = Product::create(['name' => 'P4 P', 'sku' => 'P4-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);
        $tax = round(100 * $rate / 100, 2);

        $order = PosOrder::create([
            'external_id' => Str::uuid()->toString(),
            'order_number' => 'P4-'.Str::random(8),
            'session_id' => $session->id,
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => $tax,
            'total' => 100 + $tax,
            'created_by' => $this->user->id,
        ]);
        PosOrderItem::create([
            'pos_order_id' => $order->id, 'product_id' => $product->id, 'name' => 'P4', 'sku' => $product->sku,
            'quantity' => 1, 'unit_price' => 100, 'tax_rate' => $rate, 'tax_amount' => $tax, 'line_total' => 100 + $tax,
        ]);

        app(PosPostingService::class)->postPaidOrder($order->load('items.product', 'items.variant'), 100 + $tax, 'cash');
    }

    public function test_closing_a_tax_period_blocks_tax_bearing_postings_until_reopened(): void
    {
        // Seed a rate so POS resolves a rule and produces tax lines.
        Tax::create([
            'name' => 'SG GST out', 'code' => 'P4-GST-9', 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => 'SG', 'scope' => 'output', 'treatment' => 'standard_rated',
            'rate' => 9, 'is_active' => true,
        ]);

        $this->actingAs($this->user)->postJson("/api/v1/accounting/tax-periods/{$this->period}/close")
            ->assertCreated()
            ->assertJsonPath('data.total_tax_amount', 0);

        // Tax-bearing posting must fail with the canonical reason.
        try {
            $this->postPosOrder(9.0);
            $this->fail('Tax-bearing posting must be blocked in a closed tax period.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('TAX_PERIOD_LOCKED', $e->getMessage());
        }

        // Non-tax-bearing postings are unaffected.
        $this->postPosOrder(0.0);
        $this->assertDatabaseCount('tax_transaction_details', 0);

        // Reopen -> tax posting converges and lands the trail.
        $this->actingAs($this->user)->postJson("/api/v1/accounting/tax-periods/{$this->period}/reopen")->assertOk();
        $this->postPosOrder(9.0);
        $this->assertDatabaseHas('tax_transaction_details', ['source_document_type' => 'pos_order']);

        // Double-close guard + listing.
        $this->actingAs($this->user)->postJson("/api/v1/accounting/tax-periods/{$this->period}/close")->assertCreated();
        $this->actingAs($this->user)->postJson("/api/v1/accounting/tax-periods/{$this->period}/close")->assertStatus(422);
        $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-periods')
            ->assertOk()->assertJsonPath('data.0.period', $this->period);
        $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-report/periods')
            ->assertOk()->assertJsonPath('data.0.locked', true);
        $this->actingAs($this->user)->getJson("/api/v1/accounting/tax-report/reconciliation?period={$this->period}")
            ->assertOk()->assertJsonPath('data.tax_period_locked', true);
    }

    public function test_legacy_tax_rates_migrate_idempotently_and_skip_compound(): void
    {
        $accountId = (int) DB::table('chart_of_accounts')->value('id');
        $now = now();

        DB::table('tax_rates')->insert([
            ['name' => 'Sales Tax 9.5', 'code' => 'P4ST95', 'rate' => 9.5, 'type' => 'sales',
                'tax_account_id' => $accountId, 'is_compound' => false, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Cascading combo', 'code' => 'P4COMP', 'rate' => 5, 'type' => 'sales',
                'tax_account_id' => $accountId, 'is_compound' => true, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now],
        ]);

        Artisan::call('taxes:migrate-legacy-rates');

        $migrated = Tax::where('code', 'P4ST95')->first();
        $this->assertNotNull($migrated);
        $this->assertSame('output', $migrated->scope);
        $this->assertSame(9.5, (float) $migrated->rate);
        $this->assertSame('standard_rated', $migrated->treatment);
        $this->assertNull(Tax::where('code', 'P4COMP')->first(), 'Compound rates must NOT be silently flattened.');

        Artisan::call('taxes:migrate-legacy-rates');
        $this->assertSame(1, Tax::where('code', 'P4ST95')->count());
    }

    public function test_sales_tax_settings_write_the_canonical_block_only(): void
    {
        $rule = Tax::create([
            'name' => 'P4 default 9', 'code' => 'P4-DEF9', 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => 'SG', 'scope' => 'both', 'treatment' => 'standard_rated',
            'rate' => 9, 'is_active' => true,
        ]);

        $this->actingAs($this->user)->putJson('/api/v1/settings/sales', [
            'tax' => ['prices_include_tax' => true, 'default_tax_rate' => 9, 'default_tax_treatment' => 'zero_rated'],
        ])->assertOk()->assertJsonPath('data.tax.prices_include_tax', true);

        $tax = Setting::getValue('tax', []);
        $this->assertSame('inclusive', $tax['pricing_mode'] ?? null);
        $this->assertSame($rule->id, (int) ($tax['default_tax_id'] ?? 0));
        $this->assertSame(0, DB::table('settings')->where('key', 'like', 'sales.tax.%')->count(),
            'The orphan sales.tax.* namespace must no longer receive writes.');

        // Re-pointing with a rate that matches no rule must NOT invent one.
        $this->actingAs($this->user)->putJson('/api/v1/settings/sales', ['tax' => ['default_tax_rate' => 42]])->assertOk();
        $this->assertSame($rule->id, (int) Setting::getValue('tax', [])['default_tax_id']);
    }
}
