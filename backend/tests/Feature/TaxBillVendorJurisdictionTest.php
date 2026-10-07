<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 2, hostile review of the tax decision paper (vendors).
 *
 * Bills determine input tax with only the vendor
 * (TaxDeterminationService::jurisdictionForVendor): the vendor's default (or
 * first) address country becomes the jurisdiction of the input-tax trail
 * row. Vendor addresses accept any country text (max 150). The tax return
 * selects trail rows by jurisdiction, so input tax the MY tenant booked can
 * be left out of its MY return:
 *  - a vendor whose country is typed as "Malaysia" → jurisdiction "MALAYSIA";
 *  - a correctly coded foreign vendor ("SG") → jurisdiction "SG".
 *
 * Rule asserted: input tax the tenant booked on a bill is recorded under the
 * tenant's own jurisdiction (the control bill's), whatever the vendor's
 * country. Whether that tax is claimable is a separate tax-agent decision
 * and is NOT asserted.
 *
 * Every step goes through the real routes (posting setup copied from
 * TaxReportingTest).
 */
class TaxBillVendorJurisdictionTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['tax_transaction_details', 'statutory_return_filings', 'journal_entry_lines']);

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
        foreach (['pos.create', 'accounting.write', 'reports.view', 'reports.manage'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        foreach (
            [
                'cash' => ['1000-RP', 'asset'],
                'accounts_receivable' => ['1100-RP', 'asset'],
                'sales_tax_payable' => ['2200-RP', 'liability'],
                'input_tax' => ['2210-RP', 'asset'],
                'accounts_payable' => ['2000-RP', 'liability'],
                'sales_revenue' => ['3000-RP', 'revenue'],
                'general_expense' => ['4000-RP', 'expense'],
            ] as $key => [$code, $type]
        ) {
            $account = ChartOfAccount::create([
                'code' => $code,
                'name' => "RP {$key}",
                'type' => $type,
                'account_type' => $type,
                'is_active' => true,
            ]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id, 'is_active' => true]);
        }

        // Tenant home jurisdiction: MY (the buyer of these bills).
        Setting::setValue('tax', array_merge((array) Setting::getValue('tax', []), ['jurisdiction' => 'MY']));

    }

    public function test_control_bill_input_tax_is_recorded_under_the_tenant(): void
    {
        $row = $this->trailRow($this->postBill($this->vendor(null)));

        $this->assertSame('MY', strtoupper((string) $row->jurisdiction), 'Control: a bill from a vendor with no country is recorded under the tenant (MY).');
    }

    public function test_vendor_country_typed_as_a_name_does_not_become_the_jurisdiction(): void
    {
        $row = $this->trailRow($this->postBill($this->vendor('Malaysia')));

        $this->assertSame(
            'MY',
            strtoupper((string) $row->jurisdiction),
            'Input tax on a bill from a vendor whose country is typed "Malaysia" must be recorded under MY, not "MALAYSIA".'
        );
    }

    public function test_input_tax_from_a_foreign_vendor_stays_in_the_tenants_jurisdiction(): void
    {
        $row = $this->trailRow($this->postBill($this->vendor('SG')));

        $this->assertSame(
            'MY',
            strtoupper((string) $row->jurisdiction),
            'Input tax the MY tenant booked on a bill from an SG vendor must be recorded under MY so it is in the MY return.'
        );
    }

    private function vendor(?string $country): int
    {
        $payload = ['name' => 'Probe Vendor '.Str::random(5)];
        if ($country !== null) {
            $payload['addresses'] = [[
                'type' => 'billing',
                'street' => '1 Supplier Road',
                'country' => $country,
                'is_default' => true,
            ]];
        }

        return (int) $this->postJson('/api/v1/accounting/vendors', $payload)->assertCreated()->json('data.id');
    }

    private function postBill(int $vendorId): int
    {
        $expenseAccountId = (int) AccountMapping::where('mapping_key', 'general_expense')->value('account_id');

        $response = $this->postJson('/api/v1/accounting/origin/bills', [
            'vendor_id' => $vendorId,
            'bill_type' => 'expense',
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'auto_post' => true,
            'lines' => [[
                'line_type' => 'expense',
                'description' => 'Probe purchase',
                'quantity' => 2,
                'unit_price' => 100,
                'gl_account_id' => $expenseAccountId,
                'tax_rate' => 9,
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('id');
    }

    private function trailRow(int $billId): object
    {
        $row = DB::table('tax_transaction_details')
            ->where('source_document_type', 'bill')
            ->where('source_document_id', $billId)
            ->first();
        $this->assertNotNull($row, 'The posted bill must leave an input-tax trail row.');

        return $row;
    }
}
