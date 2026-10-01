<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\AgedReceivablesService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #170 (CRITICAL) — Customer delete guard + AR Aging null-customer crash.
 *
 * Before the fix:
 *  - CustomerController::destroy() soft-deleted a customer with ZERO dependency
 *    checks (while bulkDestroy() four lines away had the correct guard).
 *  - A soft-deleted customer left invoices pointing at it; Invoice::with('customer')
 *    resolved to null under the soft-delete global scope, and
 *    AgedReceivablesService dereferenced $invoice->customer->name -> uncaught
 *    PHP Error -> the whole AR Aging report failed for every user in the tenant.
 *
 * Fixes verified here:
 *  1. destroy() blocks deletion when the customer has invoices/quotations (409),
 *     using the same shared guard as bulkDestroy().
 *  2. destroy() succeeds for a customer with no dependencies.
 *  3. AgedReceivablesService::getAgedReceivables() survives a soft-deleted
 *     customer (withTrashed + null-safe fallback) — no crash, debt still shown.
 */
class HR170CustomerDeleteArAgingTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR170 Tenant', 'email' => 'hr170-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        User::factory()->create();
    }

    private function actingAsRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);

        return $user;
    }

    private function makeCustomer(string $name): Customer
    {
        return Customer::create([
            'name' => $name,
            'email' => Str::random(6).'@example.com',
        ]);
    }

    private function makeInvoice(int $customerId, int $userId, float $balance): int
    {
        return DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR170-'.Str::random(6),
            'customer_id' => $customerId,
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'subtotal' => $balance, 'tax_amount' => 0, 'discount_amount' => 0,
            'shipping_cost' => 0, 'total' => $balance, 'paid_amount' => 0, 'balance' => $balance,
            'status' => 'posted', 'lifecycle_status' => 'posted',
            'version_number' => 1, 'is_active_version' => true,
            'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ─── 1. destroy() blocks deletion when invoices exist ───────────

    public function test_destroy_blocked_when_customer_has_invoices(): void
    {
        $admin = $this->actingAsRole('admin', 'Admin');
        $customer = $this->makeCustomer('Has Invoice');
        $this->makeInvoice($customer->id, $admin->id, 500);

        $response = $this->actingAs($admin)->deleteJson("/api/v1/customers/{$customer->id}");

        $this->assertSame(409, $response->getStatusCode(), 'Destroy must be blocked when the customer has invoices: '.$response->getContent());
        $this->assertStringContainsString('associated invoices or quotations', $response->json('message'));
        // Customer must NOT be soft-deleted.
        $this->assertNull(Customer::find($customer->id)->deleted_at, 'Customer must survive a blocked delete');
    }

    public function test_destroy_blocked_when_customer_has_quotations(): void
    {
        $admin = $this->actingAsRole('admin', 'Admin');
        $customer = $this->makeCustomer('Has Quotation');

        if (\Illuminate\Support\Facades\Schema::hasTable('quotations')) {
            DB::table('quotations')->insert([
                'quotation_number' => 'QUO-HR170-'.Str::random(6),
                'customer_id' => $customer->id,
                'quotation_date' => '2026-01-01',
                'total' => 100, 'status' => 'draft',
                'created_by' => $admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $this->markTestSkipped('quotations table not provisioned');
        }

        $response = $this->actingAs($admin)->deleteJson("/api/v1/customers/{$customer->id}");
        $this->assertSame(409, $response->getStatusCode(), 'Destroy must be blocked when the customer has quotations');
    }

    // ─── 2. destroy() succeeds with no dependencies ─────────────────

    public function test_destroy_succeeds_for_customer_without_dependencies(): void
    {
        $admin = $this->actingAsRole('admin', 'Admin');
        $customer = $this->makeCustomer('No Dependencies');

        $response = $this->actingAs($admin)->deleteJson("/api/v1/customers/{$customer->id}");

        $this->assertSame(200, $response->getStatusCode(), 'A customer with no dependencies must delete: '.$response->getContent());
        $this->assertNotNull(Customer::withTrashed()->find($customer->id)->deleted_at, 'Customer should be soft-deleted');
    }

    // ─── 3. AR Aging survives a soft-deleted customer ───────────────

    public function test_aged_receivables_survives_soft_deleted_customer(): void
    {
        $admin = $this->actingAsRole('admin', 'Admin');
        $customer = $this->makeCustomer('To Be Deleted');
        $this->makeInvoice($customer->id, $admin->id, 750);

        // Force the soft-delete that destroy() now guards against, to prove the
        // report layer is resilient even if a deleted-customer invoice exists
        // (e.g. legacy data from before this fix).
        $customer->delete();
        $this->assertNotNull(Customer::withTrashed()->find($customer->id)->deleted_at);

        $service = app(AgedReceivablesService::class);

        // Must NOT throw an uncaught Error on the null customer relation.
        $report = $service->getAgedReceivables([]);

        $this->assertIsArray($report);
        // MRD pricelist-multicurrency FR-8.3: the aging payload carries the
        // per-document-currency breakdown and a base-equivalent total.
        $this->assertArrayHasKey('by_currency', $report);
        $this->assertNotEmpty($report['by_currency']);
        $this->assertArrayHasKey('base_outstanding', $report['summary']);
        $this->assertArrayHasKey('currency_code', $report['by_currency'][0]);
        // The soft-deleted customer's real debt must still be represented
        // (withTrashed), under a fallback label rather than crashing.
        $rows = $report['data'] ?? $report;
        $found = false;
        foreach ((array) $rows as $row) {
            if ((int) ($row['customer_id'] ?? 0) === (int) $customer->id) {
                $found = true;
                $this->assertNotEmpty($row['customer_name'], 'Fallback label must be present, not a null deref');
            }
        }
        $this->assertTrue($found, 'The soft-deleted customer\'s outstanding debt must still appear in AR aging');
    }

    public function test_aging_report_endpoint_does_not_fatal_with_deleted_customer(): void
    {
        $admin = $this->actingAsRole('admin', 'Admin');
        $customer = $this->makeCustomer('Deleted For Report');
        $this->makeInvoice($customer->id, $admin->id, 300);
        $customer->delete();

        // The company-wide aging matrix endpoint must render (200), not 500/fatal.
        $response = $this->actingAs($admin)->getJson('/api/v1/accounting/reports/aging');
        $this->assertSame(200, $response->getStatusCode(), 'Aging report must not crash on a soft-deleted customer: '.$response->getContent());
    }
}
