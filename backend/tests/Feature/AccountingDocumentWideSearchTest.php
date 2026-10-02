<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteLine;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAddress;
use App\Models\VendorCreditNote;
use App\Models\VendorCreditNoteLine;
use App\Services\TagService;
use Tests\TenantTestCase;

/**
 * The accounting document lists (customer credit notes, vendor credit notes,
 * customer payments and bill payments) must have the same wide list search as
 * the sales documents: a customer/vendor contact, address, line description,
 * document reference, related invoice/bill number or tag must find the row.
 *
 * Regression: each only matched its own number + the party's name.
 */
class AccountingDocumentWideSearchTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'ffff6666-ffff-4666-ffff-666666666666';
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'Accounting Wide Search Tenant',
            'email' => 'accounting-wide-search@example.com',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($tenantId);

        $this->user = User::create([
            'name' => 'Tenant User',
            'email' => 'accounting-wide-search-user@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->customer = Customer::factory()->create(['name' => 'Astra Specialists']);
        $this->vendor = Vendor::factory()->create(['name' => 'Astra Supplies Pte Ltd']);
    }

    /**
     * @return int[]
     */
    private function searchIds(string $path, string $term): array
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson($path.'?search='.urlencode($term).'&per_page=50')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->map('intval')->all();
    }

    public function test_customer_credit_note_search_matches_contact_line_and_invoice(): void
    {
        $invoice = Invoice::factory()->create(['customer_id' => $this->customer->id]);
        CustomerContact::create([
            'customer_id' => $this->customer->id,
            'name' => 'Tan Toh Lick',
            'contact_category' => 'doctor',
            'contact_type' => 'doctor',
        ]);

        $creditNote = CustomerCreditNote::create([
            'credit_note_number' => 'CN-TEST-1',
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 10,
            'tax_amount' => 0,
            'total_amount' => 10,
            'reason' => 'test',
            'created_by' => $this->user->id,
        ]);
        CustomerCreditNoteLine::create([
            'credit_note_id' => $creditNote->id,
            'description' => 'Widget Assembly',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'line_total' => 10,
        ]);

        $this->assertContains($creditNote->id, $this->searchIds('/api/v1/accounting/customers/credit-notes', 'Tan Toh'));
        $this->assertContains($creditNote->id, $this->searchIds('/api/v1/accounting/customers/credit-notes', 'Widget Assembly'));
        $this->assertContains($creditNote->id, $this->searchIds('/api/v1/accounting/customers/credit-notes', $invoice->invoice_number));
        $this->assertSame([], $this->searchIds('/api/v1/accounting/customers/credit-notes', 'Nobody Zzz'));
    }

    public function test_vendor_credit_note_search_matches_address_line_and_bill(): void
    {
        $bill = Bill::factory()->create(['vendor_id' => $this->vendor->id]);
        VendorAddress::create([
            'vendor_id' => $this->vendor->id,
            'type' => 'billing',
            'label' => 'HQ',
            'street' => '12 Jalan Kilang',
            'city' => 'Petaling Jaya',
            'country' => 'Malaysia',
        ]);

        $creditNote = VendorCreditNote::create([
            'credit_note_number' => 'VCN-TEST-1',
            'vendor_id' => $this->vendor->id,
            'bill_id' => $bill->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 10,
            'tax_amount' => 0,
            'total_amount' => 10,
            'reason' => 'test',
            'created_by' => $this->user->id,
        ]);
        VendorCreditNoteLine::create([
            'credit_note_id' => $creditNote->id,
            'description' => 'Vendor Widget',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'line_total' => 10,
        ]);

        $this->assertContains($creditNote->id, $this->searchIds('/api/v1/accounting/vendors/credit-notes', 'Petaling Jaya'));
        $this->assertContains($creditNote->id, $this->searchIds('/api/v1/accounting/vendors/credit-notes', 'Vendor Widget'));
        $this->assertContains($creditNote->id, $this->searchIds('/api/v1/accounting/vendors/credit-notes', $bill->bill_number));
    }

    public function test_payment_search_matches_customer_contact_tag_and_invoice(): void
    {
        $invoice = Invoice::factory()->create(['customer_id' => $this->customer->id]);
        CustomerContact::create([
            'customer_id' => $this->customer->id,
            'name' => 'Tan Toh Lick',
            'contact_category' => 'doctor',
            'contact_type' => 'doctor',
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-TEST-1',
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'method' => 'cash',
            'payment_status' => 'recorded',
            'received_by' => $this->user->id,
        ]);

        $this->assertContains($payment->id, $this->searchIds('/api/v1/payments', 'Tan Toh'));
        $this->assertContains($payment->id, $this->searchIds('/api/v1/payments', $invoice->invoice_number));

        app(TagService::class)->attach($payment, ['VIP'], $this->user->id);
        $this->assertContains($payment->id, $this->searchIds('/api/v1/payments', 'vip'));
        $this->assertSame([], $this->searchIds('/api/v1/payments', 'Nobody Zzz'));
    }

    public function test_bill_payment_search_matches_vendor_address_and_bill(): void
    {
        $bill = Bill::factory()->create(['vendor_id' => $this->vendor->id]);
        VendorAddress::create([
            'vendor_id' => $this->vendor->id,
            'type' => 'billing',
            'label' => 'HQ',
            'street' => '12 Jalan Kilang',
            'city' => 'Petaling Jaya',
            'country' => 'Malaysia',
        ]);

        $billPayment = BillPayment::factory()->create([
            'vendor_id' => $this->vendor->id,
            'bill_id' => $bill->id,
            'created_by' => $this->user->id,
        ]);

        $this->assertContains($billPayment->id, $this->searchIds('/api/v1/accounting/bill-payments', 'Petaling Jaya'));
        $this->assertContains($billPayment->id, $this->searchIds('/api/v1/accounting/bill-payments', $bill->bill_number));
        $this->assertContains($billPayment->id, $this->searchIds('/api/v1/accounting/bill-payments', 'Astra Supplies'));
    }
}
