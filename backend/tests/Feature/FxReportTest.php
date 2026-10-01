<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * MRD pricelist-multicurrency FR-7.7 — FX gain/loss + exposure report.
 * Figures are proven from DOCUMENT SNAPSHOTS (never today's rates), matching
 * the PostingEngine's realized-FX math (§9.5).
 */
class FxReportTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) \Illuminate\Support\Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $tenantId], [
            'name' => 'FX Report Tenant',
            'email' => 'fxr-'.substr(md5($tenantId), 0, 8).'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->setUpTenantAdminUser('FX Report Tenant');
        $centralAdminId = DB::table('public.users')->where('email', $this->admin->email)->value('id');
        $this->tenantId = (string) DB::table('public.user_company_access')
            ->where('user_id', (int) $centralAdminId)
            ->value('tenant_id');
        CompanySettings::query()->create(['base_currency' => 'SGD']);
        $this->customer = Customer::factory()->create();
    }

    private function makeInvoice(float $total, float $balance, string $currency, float $rate): int
    {
        return (int) DB::table('invoices')->insertGetId([
            'invoice_number' => 'FXR-'.uniqid(),
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => $total - $balance,
            'balance' => $balance,
            'created_by' => $this->admin->id,
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'base_currency_code' => 'SGD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_fx_report_realized_gain_and_exposure_by_currency(): void
    {
        // Settled USD invoice @1.35 with a USD payment @1.362 → +12.00 gain.
        $settledInvoice = $this->makeInvoice(1000, 0, 'USD', 1.35);

        DB::table('payments')->insert([
            'payment_number' => 'PAY-FXR-'.uniqid(),
            'customer_id' => $this->customer->id,
            'invoice_id' => $settledInvoice,
            'payment_date' => now()->toDateString(),
            'amount' => 1000,
            'method' => 'bank_transfer',
            'currency' => 'USD',
            'exchange_rate' => 1.362,
            'base_currency_code' => 'SGD',
            'payment_status' => 'applied',
            'received_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Open exposure: USD invoice 500 @1.35, SGD invoice 100, USD bill 300 @1.35.
        $this->makeInvoice(500, 500, 'USD', 1.35);
        $this->makeInvoice(100, 100, 'SGD', 1.0);

        $vendor = Vendor::factory()->create();
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'total' => 300,
            'balance' => 300,
        ]);
        DB::table('bills')->where('id', $bill->id)->update([
            'currency_code' => 'USD',
            'exchange_rate' => 1.35,
            'base_currency_code' => 'SGD',
            'status' => 'posted',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/accounting/reports/fx');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame('SGD', $data['realized']['base_currency']);

        // Realized: USD net gain 12.00 (1,362 − 1,350).
        $usdRealized = collect($data['realized']['totals'])->firstWhere('currency_code', 'USD');
        $this->assertNotNull($usdRealized, 'USD realized bucket');
        $this->assertSame(12.0, round((float) $usdRealized['net'], 2));
        $this->assertSame(12.0, round((float) $data['realized']['total_base'], 2));

        // Exposure: AR USD 500 (base 675), AP USD 300 (base 405), net 200/270.
        $arUsd = collect($data['exposure']['ar'])->firstWhere('currency_code', 'USD');
        $this->assertNotNull($arUsd);
        $this->assertSame(500.0, round((float) $arUsd['outstanding'], 2));
        $this->assertSame(675.0, round((float) $arUsd['base_outstanding'], 2));

        $apUsd = collect($data['exposure']['ap'])->firstWhere('currency_code', 'USD');
        $this->assertNotNull($apUsd);
        $this->assertSame(300.0, round((float) $apUsd['outstanding'], 2));
        $this->assertSame(405.0, round((float) $apUsd['base_outstanding'], 2));

        $netUsd = collect($data['exposure']['net'])->firstWhere('currency_code', 'USD');
        $this->assertSame(200.0, round((float) $netUsd['net_outstanding'], 2));
        $this->assertSame(270.0, round((float) $netUsd['net_base'], 2));

        // Base-currency documents appear as their own bucket with 1:1 base.
        $arSgd = collect($data['exposure']['ar'])->firstWhere('currency_code', 'SGD');
        $this->assertNotNull($arSgd);
        $this->assertSame(100.0, round((float) $arSgd['base_outstanding'], 2));
    }

    public function test_fx_report_requires_accounting_view_permission(): void
    {
        $viewer = $this->makeRoleUser(\App\Enums\UserRole::VIEWER);

        // Viewer HAS accounting.view in the matrix → allowed.
        $this->actingAs($viewer)->getJson('/api/v1/accounting/reports/fx')->assertOk();

        // A cashier lacks the permission → 403 through the real router.
        $cashier = $this->makeRoleUser(\App\Enums\UserRole::CASHIER);
        $this->actingAs($cashier)->getJson('/api/v1/accounting/reports/fx')
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'accounting.view');
    }

    private function makeRoleUser(\App\Enums\UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->spatieName());

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => (int) $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $role->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return $user;
    }
}
