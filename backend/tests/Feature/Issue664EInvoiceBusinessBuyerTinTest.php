<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\SubmissionProviderContract;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\CountryConfiguration;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceStatusSync;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #664 [SALES-EINV] P1 — B2B customers without a TIN must not be
 * silently submitted as general-public consolidated invoices
 * (buyer TIN EI00000000010).
 *
 * Every action goes through the real HTTP route (router → middleware →
 * controller) and the real compliance chain (ComplianceController →
 * ComplianceService → MY validator/mapper → LHDN provider). The ONLY fake is
 * the LHDN HTTP boundary: a SubmissionProviderContract stub registered in the
 * CountryPluginRegistry in place of MyInvoiceSubmissionProvider, following
 * tests/Feature/Issue663EInvoiceCancelLifecycleTest.php.
 *
 * Acceptance criteria under test:
 *  - a business customer without a TIN fails validation with a clear message
 *    and no submission reaches LHDN;
 *  - a business customer with a TIN is submitted as is_consolidated: false;
 *  - an individual customer without a TIN is still allowed as a
 *    general-public consolidated invoice.
 */
class Issue664EInvoiceBusinessBuyerTinTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    private Product $product;

    private FakeLhdnProvider664 $lhdn;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => (string) Str::uuid(),
        ]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@einv664.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fake ONLY the LHDN HTTP boundary: swap the MyInvois provider for a
        // recording stub. Validator/mapper/service stay real.
        $this->lhdn = new FakeLhdnProvider664;
        app(CountryPluginRegistry::class)->register('MY', [
            'validator' => MFRSValidationService::class,
            'mapper' => MyInvoiceMapper::class,
            'provider' => $this->lhdn,
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
                'supplier_tin' => 'C1234567890',
                'supplier_name' => 'NoovaERP Sdn Bhd',
                'supplier_registration_number' => '202400000001',
            ],
            'settings' => ['auto_submit' => false],
        ]);

        DB::table('company_settings')->insert([
            'company_name' => 'NoovaERP Sdn Bhd',
            'base_currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::factory()->create([
            'track_inventory' => false,
            'name' => 'E-invoice buyer type item',
            'price' => 100,
            'cost' => 60,
        ]);
    }

    private function asTenant(): self
    {
        $this->bindTenantContext($this->tenantId);

        return $this->actingAs($this->user);
    }

    /**
     * Post an invoice through the real create + send routes, like a real sales
     * flow does before the e-invoice is submitted.
     */
    private function postedInvoice(Customer $customer): Invoice
    {
        $created = $this->asTenant()->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'description' => 'E-invoice buyer type line',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
            ],
        ]);
        $created->assertCreated();

        $invoiceId = $created->json('id');

        $this->asTenant()
            ->postJson('/api/v1/invoices/'.$invoiceId.'/send')
            ->assertOk();

        $invoice = Invoice::query()->findOrFail($invoiceId);

        $this->assertSame('posted', $invoice->status, 'Fixture: the invoice must be posted.');

        return $invoice;
    }

    /**
     * Submit the invoice's e-invoice through the real compliance routes: the
     * list route backfills the document stub, the submit route maps, validates
     * and (when allowed) calls the LHDN provider.
     */
    private function submitEInvoice(Invoice $invoice): \Illuminate\Testing\TestResponse
    {
        $this->asTenant()
            ->getJson('/api/v1/accounting/compliance/documents?country_code=MY')
            ->assertOk();

        $document = ComplianceDocument::query()
            ->where('tenant_id', $this->tenantId)
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($document, 'Fixture: the compliance list must backfill a document for the invoice.');

        return $this->asTenant()
            ->postJson('/api/v1/accounting/compliance/documents/'.$document->id.'/submit', ['country_code' => 'MY']);
    }

    public function test_business_customer_without_tin_is_rejected_with_a_clear_message_and_never_submitted(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'company',
            'name' => 'ABC Sdn Bhd',
            'company' => null,
            'tax_number' => null,
            'company_registration_number' => '202401234567',
        ]);

        $response = $this->submitEInvoice($this->postedInvoice($customer));

        $response->assertStatus(400);
        $message = (string) $response->json('message');

        $this->assertStringContainsString('ABC Sdn Bhd', $message);
        $this->assertStringContainsString('has no TIN', $message);
        $this->assertStringContainsString('mark as individual', $message);

        $this->assertCount(
            0,
            $this->lhdn->submitCalls,
            'A business customer without a TIN must never reach LHDN as a consolidated general-public invoice.'
        );
    }

    public function test_business_customer_with_tin_is_submitted_as_non_consolidated(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'company',
            'name' => 'ABC Sdn Bhd',
            'company' => null,
            'tax_number' => 'C9876543210',
            'company_registration_number' => '202401234567',
        ]);

        $this->submitEInvoice($this->postedInvoice($customer))->assertOk();

        $this->assertCount(1, $this->lhdn->submitCalls);
        $payload = $this->lhdn->submitCalls[0];

        $this->assertSame('business', $payload['buyer']['buyer_type'] ?? null);
        $this->assertFalse(
            $payload['is_consolidated'] ?? null,
            'A sales invoice to a business buyer must be explicitly non-consolidated.'
        );
        $this->assertSame('C9876543210', strtoupper((string) ($payload['buyer']['tin'] ?? '')));
        $this->assertNotSame('EI00000000010', strtoupper((string) ($payload['buyer']['tin'] ?? '')));
    }

    public function test_individual_customer_without_tin_is_allowed_as_general_public_consolidated(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'individual',
            'name' => 'Walk-in Customer',
            'company' => null,
            'tax_number' => null,
            'company_registration_number' => null,
        ]);

        $this->submitEInvoice($this->postedInvoice($customer))->assertOk();

        $this->assertCount(1, $this->lhdn->submitCalls);
        $payload = $this->lhdn->submitCalls[0];

        $this->assertSame('individual', $payload['buyer']['buyer_type'] ?? null);
        $this->assertSame('EI00000000010', strtoupper((string) ($payload['buyer']['tin'] ?? '')));
        $this->assertTrue(
            $payload['is_consolidated'] ?? null,
            'An individual buyer without a TIN is a general-public consolidated B2C invoice.'
        );
    }
}

/**
 * LHDN boundary double: records every submit/cancel call instead of making
 * HTTP requests. Registered in the CountryPluginRegistry as the MY "provider"
 * — the single seam where ComplianceService talks to LHDN.
 */
class FakeLhdnProvider664 implements SubmissionProviderContract
{
    /** @var array<int, array> */
    public array $submitCalls = [];

    public function submit(array $payload, array $credentials): array
    {
        $this->submitCalls[] = $payload;
        $sequence = count($this->submitCalls);

        return [
            'success' => true,
            'provider_reference' => 'SUB-TEST-664-'.$sequence,
            'external_document_uuid' => 'uuid-test-664-'.$sequence,
            'external_long_id' => 'long-id-test-664-'.$sequence,
            'validation_link' => 'https://portal.test/validate/664-'.$sequence,
            'status_details' => ['status' => 'Valid'],
            'message' => 'Submitted successfully',
        ];
    }

    public function getStatus(string $providerReference, array $credentials): array
    {
        return [
            'success' => true,
            'status' => 'accepted',
            'provider_reference' => $providerReference,
            'external_document_uuid' => 'uuid-test-664-from-status',
            'details' => [],
            'message' => 'Valid',
        ];
    }

    public function cancel(string $documentUuid, array $credentials): array
    {
        return [
            'success' => true,
            'status' => 'cancelled',
            'provider_status' => 'cancelled',
            'details' => [],
        ];
    }

    public function retry(array $payload, string $providerReference, array $credentials): array
    {
        return ['success' => true, 'provider_reference' => $providerReference, 'message' => 'Retried'];
    }

    public function getConfigRequirements(): array
    {
        return ['fields' => [], 'description' => 'Test provider for issue #664'];
    }
}
