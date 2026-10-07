<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #331 follow-up — backfill of the `customer_deposit` mapping for
 * tenants that applied the MY CoA template BEFORE #331 added the key.
 *
 * Decision (7 Oct 2026, product owner): MY template default deposit account
 * is 221200 Receipt in Advance (Customer Prepayments). resolve() never
 * auto-creates a generic account for a template tenant (#763), so without the
 * backfill such a tenant's first down-payment invoice cannot post.
 *
 * Backfill rule: MY-template tenant + account 221200 present + no default
 * customer_deposit mapping → insert one mapping to 221200. Insert-only,
 * idempotent; a tenant without 221200 is left untouched (the #775 health
 * check lists the role as unmapped).
 */
class Issue331CustomerDepositBackfillTest extends TenantTestCase
{
    private const MIGRATION = 'database/migrations/tenant/2026_10_07_100331_backfill_customer_deposit_mapping_for_my_template.php';

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Tax $gst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantId,
            'name' => 'Issue 331 Backfill Tenant',
            'email' => $tenantId.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
            ]
        );

        // An existing MY-template tenant: the template was applied before
        // #331, so every template mapping exists except customer_deposit.
        DB::table('account_mappings')->delete();
        DB::table('settings')->where('key', 'accounting.coa_template')->delete();
        (new \Database\Seeders\MalaysiaCountrySeeder)->run();
        DB::table('account_mappings')->where('mapping_key', 'customer_deposit')->delete();
        DB::table('entity_account_roles')
            ->whereIn('account_role_id', DB::table('account_roles')->where('code', 'CUSTOMER_DEPOSIT')->pluck('id'))
            ->delete();

        // The tax row is the SG pack's own GST row (no rate invented here).
        $rule = collect((array) config('tax_packs.SG.rules', []))->firstWhere('code', 'SG-GST-9');
        $this->assertNotNull($rule, 'The SG pack must provide the GST 9 row.');
        $this->gst = Tax::create([
            'name' => $rule['name'],
            'code' => $rule['code'],
            'type' => $rule['type'],
            'regime' => $rule['regime'],
            'jurisdiction' => $rule['jurisdiction'],
            'scope' => $rule['scope'],
            'treatment' => $rule['treatment'],
            'rate' => $rule['rate'],
            'is_inclusive' => (bool) $rule['is_inclusive'],
            'effective_from' => $rule['effective_from'],
            'effective_to' => $rule['effective_to'],
            'is_active' => true,
            'is_default' => true,
        ]);
        Setting::setValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive',
            'default_tax_id' => $this->gst->id,
            'jurisdiction' => 'SG',
        ]);

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'payment_terms' => 30,
            'credit_limit' => null,
            'total_outstanding' => 0,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'I331B Installation Project',
            'sku' => 'I331B-'.Str::upper(Str::random(6)),
            'price' => 500,
            'cost' => 0,
            'track_inventory' => false,
        ]);
    }

    public function test_backfill_lets_an_existing_my_tenant_post_a_down_payment_to_221200(): void
    {
        $this->assertFalse(
            DB::table('account_mappings')->where('mapping_key', 'customer_deposit')->exists(),
            'Precondition: an existing MY tenant has no customer_deposit mapping.'
        );

        $this->runBackfill();

        $deposit = DB::table('chart_of_accounts')->where('code', '221200')->first();
        $this->assertNotNull($deposit);

        $order = $this->confirmedOrder();
        $dpResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", [
                'method' => 'percentage',
                'percentage' => 30,
            ]);
        $dpResponse->assertStatus(201, $dpResponse->getContent());
        $dpId = (int) $dpResponse->json('invoice.id');

        $post = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/invoices/{$dpId}/post");
        $post->assertOk($post->getContent());

        $dp = Invoice::findOrFail($dpId);
        $this->assertNotNull($dp->journal_entry_id);
        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $dp->journal_entry_id)->get();

        $this->assertEqualsWithDelta((float) $dp->subtotal, (float) $lines->where('account_id', $deposit->id)->sum('credit'), 0.001,
            'The down payment credits 221200 Receipt in Advance (Customer Prepayments).');
        $this->assertEqualsWithDelta((float) $lines->sum('debit'), (float) $lines->sum('credit'), 0.001);
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->runBackfill();
        $this->runBackfill();

        $rows = DB::table('account_mappings')->where('mapping_key', 'customer_deposit')->get();
        $this->assertCount(1, $rows, 'Running the backfill twice inserts the mapping once.');
        $this->assertSame('221200', DB::table('chart_of_accounts')->where('id', $rows->first()->account_id)->value('code'));
        $this->assertNull($rows->first()->document_type);
    }

    public function test_an_existing_customer_deposit_mapping_is_never_changed(): void
    {
        $other = DB::table('chart_of_accounts')->where('code', '221300')->value('id');
        DB::table('account_mappings')->insert([
            'mapping_key' => 'customer_deposit',
            'account_id' => $other,
            'document_type' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        $rows = DB::table('account_mappings')->where('mapping_key', 'customer_deposit')->get();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $other, (int) $rows->first()->account_id, 'An existing mapping is never updated.');
    }

    public function test_a_tenant_missing_221200_is_left_untouched(): void
    {
        $deposit = DB::table('chart_of_accounts')->where('code', '221200')->value('id');
        DB::table('chart_of_accounts')->where('id', $deposit)->delete();
        $before = DB::table('account_mappings')->orderBy('id')->get()->toArray();

        $this->runBackfill();

        $this->assertEquals($before, DB::table('account_mappings')->orderBy('id')->get()->toArray(), 'No mapping is inserted, updated or deleted.');
        $this->assertFalse(DB::table('chart_of_accounts')->where('code', '221200')->exists(), 'No account is created.');
        $this->assertContains(
            'customer_deposit',
            array_column(app(\App\Accounting\Services\AccountMappingService::class)->health()['unmapped'], 'mapping_key'),
            'The #775 health check lists customer_deposit as unmapped.'
        );
    }

    public function test_a_non_my_tenant_is_left_untouched(): void
    {
        Setting::setValue('accounting.coa_template', 'SG');

        $this->runBackfill();

        $this->assertFalse(DB::table('account_mappings')->where('mapping_key', 'customer_deposit')->exists());
    }

    private function runBackfill(): void
    {
        $path = base_path(self::MIGRATION);
        $this->assertFileExists($path, 'The #331 backfill migration exists.');
        (require $path)->up();
    }

    private function confirmedOrder(): SalesOrder
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Installation milestone',
                'quantity' => 2,
                'unit_price' => 500,
                'tax_rule_id' => $this->gst->id,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated($response->getContent());
        $order = SalesOrder::findOrFail((int) $response->json('id'));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm")
            ->assertOk();

        return $order->fresh();
    }
}
