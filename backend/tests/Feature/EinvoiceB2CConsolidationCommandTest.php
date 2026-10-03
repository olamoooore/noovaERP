<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Plugin\CountryPluginRegistry;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\CountryConfiguration;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceStatusSync;
use Tests\TenantTestCase;

/**
 * Issue #815 (EP4) AC2 + AC5 — the monthly job turns the receipts held by the
 * AC1 gate into ONE consolidated e-invoice per tenant/country/month, submits
 * it, and links every receipt to the consolidated document.
 *
 * The LHDN provider is the only boundary double (the validator/mapper are
 * real); a hundred POS receipts must produce a single submission whose total
 * is their sum.
 */
class EinvoiceB2CConsolidationCommandTest extends TenantTestCase
{
    private string $tenantId;

    private RecordingConsolidationProvider $provider;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady([
            'invoices',
            'invoice_lines',
            'acct_compliance_documents',
            'acct_compliance_submissions',
            'acct_country_configurations',
        ]);

        $this->user = User::factory()->create();

        $this->provider = new RecordingConsolidationProvider;

        app(CountryPluginRegistry::class)->register('MY', [
            'validator' => MFRSValidationService::class,
            'mapper' => MyInvoiceMapper::class,
            'provider' => $this->provider,
            'sync' => MyInvoiceStatusSync::class,
        ]);

        CountryConfiguration::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'provider_name' => 'myinvoice',
            'is_enabled' => true,
            'test_mode' => true,
            'api_credentials' => [
                'myinvoice_client_id' => 'test-client-id',
                'myinvoice_client_secret' => 'test-client-secret',
                'supplier_tin' => 'C2584563200',
                'supplier_name' => 'NoovaERP Test Sdn Bhd',
                'supplier_registration_number' => '202401234567',
                'supplier_id_type' => 'BRN',
                'supplier_id_number' => '202401234567',
                'supplier_msic_code' => '46510',
                'supplier_business_activity_description' => 'Wholesale',
                'supplier_phone' => '+60123456789',
                'supplier_email' => 'supplier@example.com',
                'supplier_address' => 'Jalan Test',
                'supplier_city' => 'KL',
                'supplier_state' => '14',
                'supplier_postal_code' => '50000',
            ],
            'settings' => ['b2c_consolidation' => true, 'environment' => 'preprod'],
        ]);
    }

    private function queueReceipt(string $number, string $issuedDate, float $net = 10.00, float $taxAmount = 0.60): ComplianceDocument
    {
        $invoice = Invoice::create([
            'customer_id' => null,
            'invoice_number' => $number,
            'invoice_date' => $issuedDate,
            'due_date' => $issuedDate,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'currency_code' => 'MYR',
            'subtotal' => $net,
            'tax_amount' => $taxAmount,
            'discount_amount' => 0,
            'total' => round($net + $taxAmount, 2),
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);

        return ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'document_number' => $number,
            'issued_date' => $issuedDate,
            'total_amount' => round($net + $taxAmount, 2),
            'currency_code' => 'MYR',
            'compliance_status' => 'queued_for_consolidation',
        ]);
    }

    public function test_a_hundred_receipts_produce_one_consolidated_submission_with_the_sum_total(): void
    {
        $receipts = collect();
        for ($i = 1; $i <= 100; $i++) {
            $receipts->push($this->queueReceipt(sprintf('POS-202609-%04d', $i), '2026-09-15'));
        }

        // Another month's receipt must not be pulled into September.
        $otherMonth = $this->queueReceipt('POS-202608-0001', '2026-08-20');

        Artisan::call('compliance:consolidate-b2c', ['--month' => '2026-09']);

        $this->assertCount(1, $this->provider->submissions, 'one hundred receipts must produce exactly one submission');

        $payload = $this->provider->submissions[0];
        $this->assertCount(100, $payload['line_items'], 'one line per receipt');
        $this->assertEqualsWithDelta(1060.00, (float) $payload['totals']['gross_amount'], 0.001, 'the consolidated total must equal the sum of the receipts');
        $this->assertTrue((bool) $payload['is_consolidated'], 'the batch must be submitted as a consolidated document');

        $consolidated = ComplianceDocument::where('tenant_id', $this->tenantId)
            ->where('document_number', 'CONS-202609-000001')
            ->first();

        $this->assertNotNull($consolidated, 'the consolidated document must exist and be numbered per month');

        foreach ($receipts as $receipt) {
            $receipt->refresh();
            $this->assertSame('consolidated', $receipt->compliance_status, 'every receipt must leave the queue');
            $this->assertSame($consolidated->id, $receipt->consolidated_document_id, 'every receipt must link to the consolidated document');
        }

        $otherMonth->refresh();
        $this->assertSame('queued_for_consolidation', $otherMonth->compliance_status, 'another month must not be batched');
    }

    public function test_a_second_run_does_not_double_submit(): void
    {
        $this->queueReceipt('POS-202609-0001', '2026-09-15');

        Artisan::call('compliance:consolidate-b2c', ['--month' => '2026-09']);
        Artisan::call('compliance:consolidate-b2c', ['--month' => '2026-09']);

        $this->assertCount(1, $this->provider->submissions, 'linked receipts must not be re-consolidated');
    }
}

/**
 * Boundary double for the LHDN submission endpoint: records every submitted
 * payload and succeeds.
 */
class RecordingConsolidationProvider implements \App\Accounting\Contracts\SubmissionProviderContract
{
    /** @var array<int, array> */
    public array $submissions = [];

    public function submit(array $payload, array $credentials): array
    {
        $this->submissions[] = $payload;

        return [
            'success' => true,
            'provider_reference' => 'CONS-SUB-'.count($this->submissions),
            'external_document_uuid' => 'consolidated-uuid-'.count($this->submissions),
            'status' => 'acknowledged',
        ];
    }

    public function getStatus(string $providerReference, array $credentials): array
    {
        return ['success' => true, 'status' => 'accepted', 'provider_reference' => $providerReference, 'details' => []];
    }

    public function cancel(string $providerReference, array $credentials): array
    {
        return ['success' => false, 'message' => 'not supported in the double'];
    }

    public function supportsCancellation(): bool
    {
        return false;
    }

    public function retry(array $payload, string $providerReference, array $credentials): array
    {
        return $this->submit($payload, $credentials);
    }

    public function getConfigRequirements(): array
    {
        return ['fields' => [], 'description' => 'consolidation test double'];
    }
}
