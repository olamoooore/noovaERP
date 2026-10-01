<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\AccountingDocumentLineData;
use App\Accounting\DTOs\AccountingOriginBillData;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountingOriginBillService;
use App\Services\WhtRemittanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactTaxProfile;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #270 (R4.6 + remittance loop): vendor tax-profile overrides/exemption,
 * remittance posting nets wht_payable to zero, schedule export ties the trail.
 */
class WhtRemittanceLoopTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        foreach ([
            'general_expense' => ['W270-EXP', 'expense'],
            'accounts_payable' => ['W270-AP', 'liability'],
            'wht_payable' => ['W270-WHT', 'liability'],
            'cash' => ['W270-CASH', 'asset'],
        ] as $key => [$code, $type]) {
            $account = ChartOfAccount::create([
                'code' => $code, 'name' => "W270 {$key}", 'type' => $type,
                'account_type' => $type, 'is_active' => true,
            ]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id, 'is_active' => true]);
        }
    }

    private function whtRule(): Tax
    {
        return Tax::create([
            'name' => 'MY WHT s.109B 15%', 'code' => 'MY-WHT-15', 'type' => 'withholding',
            'regime' => 'SST', 'jurisdiction' => 'MY', 'scope' => 'input',
            'treatment' => 'standard_rated', 'rate' => 15, 'is_active' => true,
        ]);
    }

    private function whtProduct(Tax $rule): Product
    {
        $product = Product::create(['name' => 'W270 svc', 'sku' => 'W270-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);
        $product->forceFill(['tax_id' => $rule->id, 'type' => 'service'])->save();

        return $product->refresh();
    }

    private function vendorWithProfile(float $rate = 10.0, bool $exempt = false): Vendor
    {
        $vendor = Vendor::factory()->create();
        $vendor->forceFill(['core_contact_uuid' => (string) Str::uuid()])->save();

        ContactTaxProfile::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => tenant('id'),
            'contact_id' => $vendor->core_contact_uuid,
            'tax_profile_type' => 'vendor',
            'tax_id' => 'TIN-VENDOR-1',
            'withholding_tax_rate' => $rate,
            'is_tax_exempt' => $exempt,
        ]);

        return $vendor->refresh();
    }

    private function createBill(Vendor $vendor, Product $product, float $lineRate = 15.0)
    {
        return app(AccountingOriginBillService::class)->create(new AccountingOriginBillData(
            vendorId: $vendor->id,
            billType: 'expense',
            lines: [new AccountingDocumentLineData(
                lineType: 'service',
                description: 'Non-resident service',
                quantity: 1,
                unitPrice: 1000.00,
                productId: $product->id,
                glAccountId: (int) AccountMapping::where('mapping_key', 'general_expense')->value('account_id'),
                taxRate: $lineRate,
            )],
            autoPost: true,
        ));
    }

    private function whtPayableBalance(): float
    {
        $accountId = (int) AccountMapping::where('mapping_key', 'wht_payable')->value('account_id');

        return round((float) DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_id', $accountId)
            ->where('je.status', 'posted')
            ->selectRaw('coalesce(sum(jel.credit - jel.debit), 0) as balance')
            ->value('balance'), 2);
    }

    public function test_vendor_profile_rate_override_is_honoured_and_remittance_nets_payable(): void
    {
        $vendor = $this->vendorWithProfile(rate: 10.0);
        $bill = $this->createBill($vendor, $this->whtProduct($this->whtRule()));

        // 15% product rule superseded by the vendor profile's 10%.
        $this->assertSame(900.0, round((float) $bill->total, 2), 'AP must be net of the profile-rate withholding.');
        $this->assertSame(100.0, round((float) $bill->tax_amount, 2));

        $whtLine = DB::table('journal_entry_lines')->where('tax_code', 'MY-WHT-15')->first();
        $this->assertNotNull($whtLine);
        $this->assertSame(100.0, (float) $whtLine->credit);

        $period = now()->format('Y-m');
        $this->assertSame(100.0, app(WhtRemittanceService::class)->openBalance('MY', $period));

        $entry = app(WhtRemittanceService::class)->remit('MY', $period, 100.0, 'RC-270-1');
        $this->assertNotNull($entry);

        $this->assertSame(0.0, $this->whtPayableBalance(), 'Remittance must net the wht_payable account to zero.');
        $this->assertSame(0.0, app(WhtRemittanceService::class)->openBalance('MY', $period));

        $summary = app(\Modules\AccountingCountry\Services\TaxReportingService::class)->withholdingSummary();
        $this->assertSame(0.0, (float) $summary['open_total']);
        $this->assertSame(0.0, (float) $summary['variance'], 'Payable balance must reconcile to withheld minus remitted.');

        // Over-remittance is blocked.
        try {
            app(WhtRemittanceService::class)->remit('MY', $period, 50.0, 'RC-270-2');
            $this->fail('Over-remittance must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exceeds the open withholding balance', $e->getMessage());
        }
    }

    public function test_exempt_vendor_profile_skips_withholding_entirely(): void
    {
        $vendor = $this->vendorWithProfile(rate: 10.0, exempt: true);
        $bill = $this->createBill($vendor, $this->whtProduct($this->whtRule()));

        $this->assertSame(1000.0, round((float) $bill->total, 2));
        $this->assertSame(0.0, round((float) $bill->tax_amount, 2));
        $this->assertSame(0, DB::table('journal_entry_lines')->where('tax_code', 'MY-WHT-15')->count());
    }

    public function test_withholding_schedule_export_ties_the_trail(): void
    {
        $vendor = $this->vendorWithProfile(rate: 10.0);
        $this->createBill($vendor, $this->whtProduct($this->whtRule()));

        $period = now()->format('Y-m');

        $before = $this->get("/api/v1/accounting/tax-report/withholding/export?authority=MY&period={$period}");
        $before->assertStatus(200);
        $csv = $before->getContent();
        $this->assertStringContainsString('MY-WHT-15', $csv);
        $this->assertStringContainsString('100.00,0.00,100.00', str_replace(["\r", "\n"], ['', '|'], $csv));

        app(WhtRemittanceService::class)->remit('MY', $period, 100.0, 'RC-270-3');

        $after = $this->get("/api/v1/accounting/tax-report/withholding/export?authority=MY&period={$period}");
        $after->assertStatus(200);
        $this->assertStringContainsString('100.00,100.00,0.00', str_replace(["\r", "\n"], ['', '|'], $after->getContent()));
    }
}
