<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #897 — the Sales list endpoints (invoices, quotations, sales orders)
 * accepted any caller-supplied per_page, so `per_page=100000` loaded the whole
 * table with its relations: memory/DoS risk and a bulk-extraction path.
 *
 * Each list must clamp per_page to the platform maximum (100) while keeping
 * the existing paginator response shape (`data`, `per_page`, ...).
 */
class SalesListPaginationCapTest extends TenantTestCase
{
    private const PER_PAGE_CAP = 100;

    private const SEEDED_ROWS = 120;

    protected User $user;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Sales Pagination Cap Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::MANAGER->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'manager',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_invoice_list_clamps_per_page_to_the_platform_cap(): void
    {
        $this->seedInvoices(self::SEEDED_ROWS);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/invoices?per_page=100000')
            ->assertOk();

        $this->assertLessThanOrEqual(
            self::PER_PAGE_CAP,
            count($response->json('data')),
            'The invoice list must never return more than the platform per_page cap.'
        );
        $this->assertSame(
            self::PER_PAGE_CAP,
            (int) $response->json('per_page'),
            'The invoice list must clamp an oversized per_page to the platform cap.'
        );
    }

    public function test_quotation_list_clamps_per_page_to_the_platform_cap(): void
    {
        $this->seedQuotations(self::SEEDED_ROWS);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/quotations?per_page=100000')
            ->assertOk();

        $this->assertLessThanOrEqual(
            self::PER_PAGE_CAP,
            count($response->json('data')),
            'The quotation list must never return more than the platform per_page cap.'
        );
        $this->assertSame(
            self::PER_PAGE_CAP,
            (int) $response->json('per_page'),
            'The quotation list must clamp an oversized per_page to the platform cap.'
        );
    }

    public function test_sales_order_list_clamps_per_page_to_the_platform_cap(): void
    {
        $this->seedSalesOrders(self::SEEDED_ROWS);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/sales/orders?per_page=100000')
            ->assertOk();

        $this->assertLessThanOrEqual(
            self::PER_PAGE_CAP,
            count($response->json('data')),
            'The sales-order list must never return more than the platform per_page cap.'
        );
        $this->assertSame(
            self::PER_PAGE_CAP,
            (int) $response->json('per_page'),
            'The sales-order list must clamp an oversized per_page to the platform cap.'
        );
    }

    public function test_invoice_list_still_honours_a_per_page_below_the_cap(): void
    {
        $this->seedInvoices(self::SEEDED_ROWS);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/invoices?per_page=50')
            ->assertOk();

        $this->assertCount(50, $response->json('data'));
        $this->assertSame(50, (int) $response->json('per_page'));
    }

    private function seedInvoices(int $count): void
    {
        $customer = Customer::factory()->create(['name' => 'Pagination Cap Customer']);

        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);

        $this->duplicateTemplate('invoices', 'invoice_number', 'INV-CAP', $count);
    }

    private function seedQuotations(int $count): void
    {
        $customer = Customer::factory()->create(['name' => 'Pagination Cap Customer']);

        Quotation::create([
            'customer_id' => $customer->id,
            'quotation_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        $this->duplicateTemplate('quotations', 'quotation_number', 'QT-CAP', $count);
    }

    private function seedSalesOrders(int $count): void
    {
        $customer = Customer::factory()->create(['name' => 'Pagination Cap Customer']);

        SalesOrder::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        $this->duplicateTemplate('sales_orders', 'sales_order_number', 'SO-CAP', $count);
    }

    /**
     * Copy the fixture row $count times, replacing the unique document number,
     * so the list has more rows than the cap without $count model saves.
     */
    private function duplicateTemplate(string $table, string $uniqueColumn, string $prefix, int $count): void
    {
        $template = (array) DB::table($table)->latest('id')->first();
        unset($template['id']);

        $now = now();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $row = $template;
            $row[$uniqueColumn] = sprintf('%s-%05d', $prefix, $i);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
        }

        DB::table($table)->insert($rows);
    }
}
