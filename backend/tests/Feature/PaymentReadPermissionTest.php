<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #481: payment reads must be gated with accounting.view. Previously
 * EnsureAccountingWriteAccess allowed every GET for all authenticated users and
 * the payment routes had no RequirePermission, so any tenant role (Sales,
 * Warehouse, HR…) could read every receipt, reference and bank detail.
 *
 * SALES has no accounting.view; ACCOUNTANT does.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentReadPermissionTest extends TenantTestCase
{
    protected User $sales;

    protected User $accountant;

    protected Customer $customer;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->sales = $this->makeUser('sales', UserRole::SALES);
        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);
        $this->customer = Customer::factory()->create(['payment_terms' => 30]);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function invoiceWithDraftPayment(): Payment
    {
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 500,
            'paid_amount' => 0,
            'balance' => 500,
            'created_by' => $this->accountant->id,
        ]);

        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 100,
                'method' => 'cash',
            ])
            ->assertCreated();

        return Payment::findOrFail($response->json('id'));
    }

    public function test_sales_cannot_list_export_or_view_payments(): void
    {
        $payment = $this->invoiceWithDraftPayment();

        $this->actingAs($this->sales)->getJson('/api/v1/payments')->assertForbidden();
        $this->actingAs($this->sales)->getJson('/api/v1/payments/export')->assertForbidden();
        $this->actingAs($this->sales)->getJson("/api/v1/payments/{$payment->id}")->assertForbidden();
        $this->actingAs($this->sales)->getJson('/api/v1/payments/eligible-invoices')->assertForbidden();
    }

    public function test_accountant_can_list_payments(): void
    {
        $this->invoiceWithDraftPayment();

        $this->actingAs($this->accountant)
            ->getJson('/api/v1/payments')
            ->assertOk();
    }
}
