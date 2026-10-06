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
 * Issue #488 (PAY-UX2): the split-payment / unapplied-credit UI needs, through
 * the real routes and seeded permissions:
 *  - the #155 allocation endpoints to work for an accounting writer and be
 *    readable (not writable) by a view-only role;
 *  - the customer's open POSTED invoices to allocate against;
 *  - a customer's payments that still carry unapplied credit, with the
 *    unapplied amount (customer page + invoice "outstanding credits").
 */
class Issue488PaymentAllocationUiTest extends TenantTestCase
{
    private string $tenantId;

    private User $accountant;

    private User $viewer;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady(['payments', 'payment_applications', 'invoices', 'customers']);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);
        $this->viewer = $this->makeUser('viewer', UserRole::VIEWER);
        $this->customer = Customer::factory()->create();
    }

    private function makeUser(string $centralRole, UserRole $spatieRole): User
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
        $user->assignRole($spatieRole->spatieName());

        return $user;
    }

    private function invoice(float $total, string $lifecycle = 'posted', ?Customer $customer = null): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'status' => 'sent',
            'lifecycle_status' => $lifecycle,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'created_by' => $this->accountant->id,
        ]);
    }

    private function payment(float $amount, string $status = 'applied', ?Customer $customer = null): Payment
    {
        return Payment::create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_id' => null,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'payment_status' => $status,
            'received_by' => $this->accountant->id,
        ]);
    }

    public function test_an_accountant_splits_a_payment_and_sees_the_unapplied_remainder(): void
    {
        $a = $this->invoice(100);
        $b = $this->invoice(200);
        $payment = $this->payment(500);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payments/{$payment->id}/allocations", [
                'applications' => [
                    ['invoice_id' => $a->id, 'amount' => 100],
                    ['invoice_id' => $b->id, 'amount' => 150],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('applied_amount', 250)
            ->assertJsonPath('unapplied_amount', 250);

        $this->actingAs($this->accountant)
            ->getJson("/api/v1/payments/{$payment->id}/allocations")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.invoice.invoice_number', $a->invoice_number)
            ->assertJsonPath('unapplied_amount', 250);

        $this->assertEqualsWithDelta(50.0, (float) $b->fresh()->balance, 0.001);
    }

    public function test_a_viewer_can_read_allocations_but_cannot_allocate(): void
    {
        $invoice = $this->invoice(100);
        $payment = $this->payment(100);

        $this->actingAs($this->viewer)
            ->getJson("/api/v1/payments/{$payment->id}/allocations")
            ->assertOk()
            ->assertJsonPath('unapplied_amount', 100);

        $this->actingAs($this->viewer)
            ->postJson("/api/v1/payments/{$payment->id}/allocations", [
                'applications' => [['invoice_id' => $invoice->id, 'amount' => 100]],
            ])
            ->assertForbidden();

        $this->assertEqualsWithDelta(100.0, (float) $invoice->fresh()->balance, 0.001);
    }

    public function test_eligible_invoices_expose_the_posting_state_so_only_posted_ones_are_offered(): void
    {
        $posted = $this->invoice(100, 'posted');
        $unposted = $this->invoice(100, 'draft');

        $rows = collect($this->actingAs($this->accountant)
            ->getJson('/api/v1/payments/eligible-invoices?customer_id='.$this->customer->id)
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertSame('posted', $rows[$posted->id]['lifecycle_status'] ?? null);
        $this->assertSame('draft', $rows[$unposted->id]['lifecycle_status'] ?? null);
    }

    public function test_the_payment_list_can_be_filtered_to_payments_holding_unapplied_credit(): void
    {
        $invoice = $this->invoice(300);
        $partly = $this->payment(500);
        $fully = $this->payment(300);
        $onAccount = $this->payment(80);
        $draft = $this->payment(40, 'recorded');
        $other = $this->payment(70, 'applied', Customer::factory()->create());

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payments/{$partly->id}/allocations", [
                'applications' => [['invoice_id' => $invoice->id, 'amount' => 200]],
            ])->assertOk();
        $other2 = $this->invoice(300);
        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payments/{$fully->id}/allocations", [
                'applications' => [['invoice_id' => $other2->id, 'amount' => 300]],
            ])->assertOk();

        $rows = collect($this->actingAs($this->viewer)
            ->getJson('/api/v1/payments?unapplied=1&customer_id='.$this->customer->id)
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertEqualsCanonicalizing([$partly->id, $onAccount->id], $rows->keys()->all());
        $this->assertEqualsWithDelta(300.0, (float) $rows[$partly->id]['unapplied_amount'], 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $rows[$onAccount->id]['unapplied_amount'], 0.001);
        $this->assertArrayNotHasKey($fully->id, $rows->all());
        $this->assertArrayNotHasKey($draft->id, $rows->all());
        $this->assertArrayNotHasKey($other->id, $rows->all());
    }
}
