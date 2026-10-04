<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #906 (SALES-PLAT / UX2) — the frontend needs enough server data to
 * render the "Someone else is editing" banner and the save-conflict dialog
 * built on #896:
 *
 *   - GET show must expose the active soft lock (`active_lock`) so a page can
 *     warn before the user invests more edits, and
 *   - the lock payload (both on GET and on the 409) must name the editor, not
 *     only carry the id, because the UI says "Siti is editing this document".
 *
 * These are router-level tests: the documents are saved through the real PUT
 * routes (which record the soft lock) and read back through the real GET.
 */
class Issue906SalesEditingBannerTest extends TenantTestCase
{
    private string $tenantId;

    private User $owner;

    private User $secondUser;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Editing Banner Tenant',
            'email' => 'editing-banner@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        // Sales-order routes carry the `sales` entitlement; invoice and
        // quotation routes do not. Mock the platform gate as entitled.
        $this->mockFeatureGate();

        $this->owner = $this->makeUser('owner');
        $this->secondUser = $this->makeUser('owner');

        $this->customer = Customer::factory()->create();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function mockFeatureGate(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => true,
                'source' => 'tier_included',
                'name' => strtoupper($key),
            ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function makeInvoice(): Invoice
    {
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-906-'.Str::upper(Str::random(6)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);

        $invoice->lines()->create([
            'line_type' => 'product',
            'description' => 'Original line',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        $invoice->refresh();
        $invoice->calculateTotals();

        return $invoice;
    }

    private function makeQuotation(): Quotation
    {
        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-906-'.Str::upper(Str::random(6)),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'exchange_rate' => 1,
            'created_by' => $this->owner->id,
        ]);

        $quotation->lines()->create([
            'line_type' => 'product',
            'description' => 'Quotation line',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        return $quotation;
    }

    private function makeSalesOrder(): SalesOrder
    {
        return SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_invoice_get_exposes_who_last_saved_the_draft(): void
    {
        $invoice = $this->makeInvoice();

        // Nobody has saved yet — no banner data.
        $before = $this->actingAs($this->secondUser)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk();
        $this->assertNull($before->json('active_lock'));

        $version = $this->actingAs($this->owner)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->json('lock_version');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'customer_reference' => 'FIRST-WRITE',
                'version' => $version,
            ])
            ->assertOk();

        $this->actingAs($this->secondUser)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('active_lock.user_id', $this->owner->id)
            ->assertJsonPath('active_lock.user_name', $this->owner->name);
    }

    public function test_quotation_get_exposes_who_last_saved_the_draft(): void
    {
        $quotation = $this->makeQuotation();

        $version = $this->actingAs($this->owner)
            ->getJson("/api/v1/quotations/{$quotation->id}")
            ->json('lock_version');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/quotations/{$quotation->id}", [
                'customer_reference' => 'FIRST-WRITE',
                'version' => $version,
            ])
            ->assertOk();

        $this->actingAs($this->secondUser)
            ->getJson("/api/v1/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('active_lock.user_id', $this->owner->id)
            ->assertJsonPath('active_lock.user_name', $this->owner->name);
    }

    public function test_sales_order_get_exposes_who_last_saved_the_draft(): void
    {
        $order = $this->makeSalesOrder();

        $version = $this->actingAs($this->owner)
            ->getJson("/api/v1/sales/orders/{$order->id}")
            ->json('lock_version');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/sales/orders/{$order->id}", [
                'notes' => 'FIRST-WRITE',
                'version' => $version,
            ])
            ->assertOk();

        $this->actingAs($this->secondUser)
            ->getJson("/api/v1/sales/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('active_lock.user_id', $this->owner->id)
            ->assertJsonPath('active_lock.user_name', $this->owner->name);
    }

    public function test_conflict_response_names_the_editor_for_the_dialog(): void
    {
        $invoice = $this->makeInvoice();

        $version = $this->actingAs($this->owner)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->json('lock_version');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'customer_reference' => 'FIRST-WRITE',
                'version' => $version,
            ])
            ->assertOk();

        $this->actingAs($this->secondUser)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'customer_reference' => 'SECOND-WRITE',
                'version' => $version,
            ])
            ->assertStatus(409)
            ->assertJsonPath('active_lock.user_id', $this->owner->id)
            ->assertJsonPath('active_lock.user_name', $this->owner->name);
    }
}
