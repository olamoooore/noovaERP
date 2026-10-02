<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\MedicalCase;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #430 (CU2): customer deletion was guarded only by invoices and
 * quotations, so a customer with sales orders, payments, credit notes,
 * delivery orders or medical cases could be soft-deleted — leaving those
 * documents pointing at a deleted customer (the #170 crash class in AR
 * aging). Every dependent document must block the delete with 409 and name
 * itself in the blockers list; a customer with no dependencies still deletes.
 */
class CustomerDeleteGuardTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deleteCustomer(Customer $customer)
    {
        return $this->actingAs($this->user)
            ->deleteJson("/api/v1/customers/{$customer->id}");
    }

    public function test_delete_is_blocked_by_every_dependent_document(): void
    {
        $customer = Customer::factory()->create();

        SalesOrder::create([
            'sales_order_number' => 'SO-DEL-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);

        DeliveryOrder::create([
            'delivery_order_number' => 'DO-DEL-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);

        Payment::create([
            'payment_number' => 'PAY-DEL-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'amount' => 10,
            'received_by' => $this->user->id,
        ]);

        WebOrder::create([
            'order_number' => 'WEB-DEL-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'email' => 'web@example.test',
            'subtotal' => 10,
            'total' => 10,
        ]);

        MedicalCase::create([
            'case_no' => 'MC-DEL-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);

        // A credit note also needs its invoice, which is itself a blocker.
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);

        CustomerCreditNote::create([
            'credit_note_number' => 'CN-DEL-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteCustomer($customer);

        $response->assertStatus(409);

        $blockers = $response->json('blockers');

        foreach (['invoices', 'sales orders', 'delivery orders', 'payments', 'web orders', 'medical cases', 'credit notes'] as $label) {
            $this->assertContains($label, $blockers, "the blockers must name '{$label}'");
        }

        $this->assertNull(Customer::find($customer->id)->deleted_at, 'the customer must not be soft-deleted');
    }

    public function test_delete_succeeds_for_a_customer_without_documents(): void
    {
        $customer = Customer::factory()->create();

        $this->deleteCustomer($customer)->assertOk();

        $this->assertNotNull(Customer::withTrashed()->find($customer->id)->deleted_at, 'a clean customer must be soft-deleted');
    }
}
