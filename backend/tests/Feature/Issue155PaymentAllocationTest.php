<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #155 — a payment can be split across multiple invoices, or held as
 * unapplied credit on account, via explicit payment_applications rows.
 */
class Issue155PaymentAllocationTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '15515515-5152-5354-5556-575859505152',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['payments', 'payment_applications', 'invoices', 'customers']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
    }

    private function invoice(float $total): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'created_by' => $this->user->id,
        ]);
    }

    private function payment(float $amount, ?Customer $customer = null): Payment
    {
        return Payment::create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_id' => null,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'payment_status' => 'applied',
            'received_by' => $this->user->id,
        ]);
    }

    public function test_a_payment_can_be_split_across_multiple_invoices(): void
    {
        $invoiceA = $this->invoice(600);
        $invoiceB = $this->invoice(400);
        $payment = $this->payment(1000);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments/'.$payment->id.'/allocations', [
            'applications' => [
                ['invoice_id' => $invoiceA->id, 'amount' => 600],
                ['invoice_id' => $invoiceB->id, 'amount' => 400],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(0.0, (float) $response->json('unapplied_amount'));
        $this->assertSame(2, PaymentApplication::where('payment_id', $payment->id)->count());

        $invoiceA->refresh();
        $invoiceB->refresh();
        $this->assertSame(600.0, round((float) $invoiceA->paid_amount, 2));
        $this->assertSame(0.0, round((float) $invoiceA->balance, 2));
        $this->assertSame('paid', $invoiceA->status);
        $this->assertSame(400.0, round((float) $invoiceB->paid_amount, 2));
        $this->assertSame(0.0, round((float) $invoiceB->balance, 2));
    }

    public function test_a_payment_without_applications_is_unapplied_credit(): void
    {
        $payment = $this->payment(500);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payments/'.$payment->id.'/allocations');

        $response->assertOk();
        $this->assertSame(500.0, (float) $response->json('amount'));
        $this->assertSame(0.0, (float) $response->json('applied_amount'));
        $this->assertSame(500.0, (float) $response->json('unapplied_amount'));
        $this->assertCount(0, $response->json('data'));
    }

    public function test_over_allocation_is_rejected(): void
    {
        $invoice = $this->invoice(600);
        $payment = $this->payment(100);

        $this->actingAs($this->user)->postJson('/api/v1/payments/'.$payment->id.'/allocations', [
            'applications' => [['invoice_id' => $invoice->id, 'amount' => 150]],
        ])->assertStatus(422);

        $this->assertSame(0, PaymentApplication::count());
        $this->assertSame(0.0, round((float) $invoice->fresh()->paid_amount, 2));
    }

    public function test_allocation_beyond_the_invoice_balance_is_rejected(): void
    {
        $invoice = $this->invoice(100);
        $payment = $this->payment(500);

        $this->actingAs($this->user)->postJson('/api/v1/payments/'.$payment->id.'/allocations', [
            'applications' => [['invoice_id' => $invoice->id, 'amount' => 200]],
        ])->assertStatus(422);
    }

    public function test_allocating_to_another_customers_invoice_is_rejected(): void
    {
        $otherCustomer = Customer::factory()->create();
        $foreignInvoice = Invoice::factory()->create([
            'customer_id' => $otherCustomer->id,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'total' => 100,
            'balance' => 100,
            'paid_amount' => 0,
            'created_by' => $this->user->id,
        ]);

        $payment = $this->payment(500);

        $this->actingAs($this->user)->postJson('/api/v1/payments/'.$payment->id.'/allocations', [
            'applications' => [['invoice_id' => $foreignInvoice->id, 'amount' => 100]],
        ])->assertStatus(422);
    }

    public function test_the_same_invoice_cannot_be_allocated_twice_from_one_payment(): void
    {
        $invoice = $this->invoice(500);
        $payment = $this->payment(500);

        $this->actingAs($this->user)->postJson('/api/v1/payments/'.$payment->id.'/allocations', [
            'applications' => [['invoice_id' => $invoice->id, 'amount' => 100]],
        ])->assertOk();

        $this->actingAs($this->user)->postJson('/api/v1/payments/'.$payment->id.'/allocations', [
            'applications' => [['invoice_id' => $invoice->id, 'amount' => 100]],
        ])->assertStatus(422);

        $this->assertSame(1, PaymentApplication::count());
    }
}
