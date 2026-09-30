<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * MRD Phase 3: recoverability (R4.2) + reverse charge (R4.3) + the D8
 * control-account split, proven through real postings and the per-code
 * trail↔GL reconciliation.
 */
class TaxEnginePhase3CreditabilityTest extends TenantTestCase
{
    private User $user;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['tax_transaction_details']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create();
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
        foreach (['accounting.write', 'reports.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        // Non-tax accounts (the tax control accounts come from the Phase-3
        // tenant migration itself — this test verifies THAT wiring too).
        foreach (
            [
                'cash' => ['1000-P3', 'asset'],
                'accounts_receivable' => ['1100-P3', 'asset'],
                'accounts_payable' => ['2000-P3', 'liability'],
                'sales_revenue' => ['3000-P3', 'revenue'],
                'general_expense' => ['4000-P3', 'expense'],
            ] as $key => [$code, $type]
        ) {
            $account = ChartOfAccount::create([
                'code' => $code, 'name' => "P3 {$key}", 'type' => $type,
                'account_type' => $type, 'is_active' => true,
            ]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id, 'is_active' => true]);
        }

        $this->period = now()->format('Y-m');
    }

    public function test_d8_control_accounts_are_split_by_migration(): void
    {
        $recoverable = ChartOfAccount::where('code', '2210')->first();
        $blocked = ChartOfAccount::where('code', '5210')->first();

        $this->assertNotNull($recoverable, 'Phase-3 migration must seed the recoverable input account.');
        $this->assertNotNull($blocked, 'Phase-3 migration must seed the blocked-input expense account.');
        $this->assertSame('asset', $recoverable->type ?? $recoverable->account_type);
        $this->assertSame((int) $recoverable->id, (int) AccountMapping::where('mapping_key', 'input_tax')->value('account_id'));
    }

    public function test_non_creditable_input_is_expensed_visibly_never_claimed(): void
    {
        Setting::setValue('tax', ['enabled' => true, 'pricing_mode' => 'exclusive', 'jurisdiction' => 'MY']);

        $rule = Tax::create([
            'name' => 'MY SST input (non-creditable)',
            'code' => 'MY-IN-NONCR',
            'type' => 'sales',
            'regime' => 'SST',
            'jurisdiction' => 'MY',
            'scope' => 'input',
            'treatment' => 'standard_rated',
            'rate' => 10,
            'creditable' => false,
            'is_active' => true,
        ]);

        $bill = $this->makeBill($rule, 100.0, 10.0);
        $result = app(\App\Services\VendorBillService::class)->postBill($bill->load('lines.product', 'vendor.addresses'));
        $this->assertTrue($result->success, "bill post failed: {$result->error}");

        $blockedAccountId = (int) ChartOfAccount::where('code', '5210')->value('id');
        $recoverableAccountId = (int) ChartOfAccount::where('code', '2210')->value('id');

        $line = DB::table('journal_entry_lines')->where('tax_code', 'MY-IN-NONCR')->first();
        $this->assertNotNull($line);
        $this->assertSame((float) 10.00, (float) $line->debit);
        $this->assertSame($blockedAccountId, (int) $line->account_id, 'Non-creditable input must hit the visible blocked-expense account.');

        $this->assertSame(
            0,
            DB::table('journal_entry_lines')->where('account_id', $recoverableAccountId)->count(),
            'Nothing may be claimed as recoverable input tax.',
        );

        // The trail keeps the code, so the per-code reconciliation still ties:
        // expected GL net -10 (claimable sign by doc type = input) is offset by
        // the blocked debit of 10 on the SAME code.
        $recon = app(\Modules\AccountingCountry\Services\TaxReportingService::class)
            ->reconciliation($this->period);
        $this->assertTrue($recon['balanced'], json_encode($recon['variance'] ?? $recon['by_tax_code']));
    }

    public function test_reverse_charge_posts_paired_self_assessed_lines_and_nets_to_zero(): void
    {
        Setting::setValue('tax', ['enabled' => true, 'pricing_mode' => 'exclusive', 'jurisdiction' => 'SG']);

        $rule = Tax::create([
            'name' => 'SG RC on imported services',
            'code' => 'SG-RC-9',
            'type' => 'gst',
            'regime' => 'GST',
            'jurisdiction' => 'SG',
            'scope' => 'input',
            'treatment' => 'reverse_charge',
            'rate' => 9,
            'is_active' => true,
        ]);

        $bill = $this->makeBill($rule, 200.0, 18.0, taxInAp: false);
        $result = app(\App\Services\VendorBillService::class)->postBill($bill->load('lines.product', 'vendor.addresses'));
        $this->assertTrue($result->success, "bill post failed: {$result->error}");

        $lines = DB::table('journal_entry_lines')->where('tax_code', 'SG-RC-9')->get();
        $this->assertCount(2, $lines, 'RC must produce one reclaim and one self-assessed payable line.');

        $netTaxLiability = $lines->sum(fn ($l) => (float) $l->credit - (float) $l->debit);
        $this->assertSame(0.0, round((float) $netTaxLiability, 2), 'RC pairs must net to zero cash-effect on the tax accounts.');

        $trail = DB::table('tax_transaction_details')->where('tax_code', 'SG-RC-9')->get();
        $this->assertEqualsCanonicalizing(['input', 'output'], $trail->pluck('direction')->all(),
            'The trail must carry each RC side under its own direction.');

        // Aggregation: +18 output, −18... both positive rows, directions split.
        $aggregate = app(\Modules\AccountingCountry\Services\TaxReturnAggregator::class)->aggregate($this->period);
        $this->assertSame(18.0, round(array_sum(array_map(fn ($r) => (float) $r['tax_amount'], $aggregate['output'])), 2));
        $this->assertSame(18.0, round(array_sum(array_map(fn ($r) => (float) $r['tax_amount'], $aggregate['input'])), 2));

        // Per-code recon still ties (net 0 expected, net 0 posted).
        $recon = app(\Modules\AccountingCountry\Services\TaxReportingService::class)->reconciliation($this->period);
        $this->assertTrue($recon['balanced'], json_encode($recon['by_tax_code']));
    }

    /**
     * $taxInAp=false models reverse-charge bills correctly: the self-assessed
     * amount is payable to IRAS, never added to what we owe the vendor.
     */
    private function makeBill(Tax $rule, float $net, float $taxAmount, bool $taxInAp = true): Bill
    {
        $product = Product::create([
            'name' => 'P3 product', 'sku' => 'P3-'.Str::random(6), 'is_active' => true, 'track_inventory' => false,
        ]);
        $product->forceFill(['tax_id' => $rule->id])->save();

        $bill = Bill::create([
            'vendor_id' => Vendor::factory()->create()->id,
            'bill_number' => 'P3BILL-'.Str::random(6),
            'bill_type' => 'expense',
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => $net,
            'tax_amount' => $taxAmount,
            'discount_amount' => 0,
            'total' => $taxInAp ? $net + $taxAmount : $net,
            'paid_amount' => 0,
            'balance' => $taxInAp ? $net + $taxAmount : $net,
            'created_by' => $this->user->id,
        ]);

        BillLine::create([
            'bill_id' => $bill->id,
            'product_id' => $product->id,
            'description' => 'P3 line',
            'quantity' => 1,
            'unit_price' => $net,
            'tax_rate' => (float) $rule->rate,
            'tax_amount' => $taxAmount,
            'line_total' => $net,
            'account_id' => (int) AccountMapping::where('mapping_key', 'general_expense')->value('account_id'),
        ]);

        return $bill;
    }
}
