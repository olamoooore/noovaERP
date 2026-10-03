<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceSubmissionProvider;
use Tests\TenantTestCase;

/**
 * #816 EP5: a refund to the buyer produces a MyInvois REFUND NOTE (type 04)
 * that references the original invoice's accepted LHDN UUID.
 */
class EinvRefundNoteMappingTest extends TenantTestCase
{
    public function test_a_refund_note_payload_references_the_original_invoice_uuid(): void
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $invoiceId = 4242;
        $documentId = DB::table('acct_compliance_documents')->insertGetId([
            'tenant_id' => $tenant->id, 'country_code' => 'MY', 'document_type' => 'invoice',
            'document_id' => $invoiceId, 'document_number' => 'INV-1', 'issued_date' => '2025-08-15',
            'total_amount' => 100.00, 'currency_code' => 'MYR', 'compliance_status' => 'valid',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('acct_compliance_submissions')->insert([
            'document_id' => $documentId, 'tenant_id' => $tenant->id, 'submission_status' => 'accepted',
            'external_document_uuid' => 'LHDN-UUID-9', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $line = (object) [
            'id' => 1, 'description' => 'Refunded service', 'quantity' => 1, 'unit_price' => 100.00,
            'discount_amount' => 0, 'tax_rate' => 0, 'tax_amount' => 0,
        ];
        $invoice = (object) [
            'id' => $invoiceId, 'invoice_number' => 'INV-1',
            'supplier' => (object) ['name' => 'NoovaERP Sdn Bhd', 'tin' => 'C2584563200', 'company_registration' => '202401234567'],
        ];
        $customer = (object) ['name' => 'Buyer Sdn Bhd', 'tin' => 'C9999999999', 'buyer_type' => 'business'];

        $refund = (object) [
            'id' => 900, 'credit_note_number' => 'RF-1', 'credit_note_date' => '2025-08-20',
            'currency_code' => 'MYR', 'lines' => collect([$line]), 'customer' => $customer,
            'invoice' => $invoice, 'subtotal' => 100.00, 'discount_amount' => 0, 'tax_amount' => 0, 'tax_rate' => 0,
        ];

        $payload = app(MyInvoiceMapper::class)->mapRefundNote($refund);

        $this->assertSame('refund_note', $payload['document_type']);
        $this->assertSame('LHDN-UUID-9', $payload['original_invoice_references'][0]['uuid'] ?? null);
        $this->assertSame('INV-1', $payload['original_invoice_references'][0]['invoice_number'] ?? null);

        // The provider maps that document type to LHDN type 04.
        $provider = new MyInvoiceSubmissionProvider(null, 'https://sandbox.myinvoice.test');
        $method = (new \ReflectionClass($provider))->getMethod('resolveDocumentTypeCode');
        $method->setAccessible(true);
        $this->assertSame('04', $method->invoke($provider, 'refund_note'));
    }
}
