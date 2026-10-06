<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\SubmissionProviderContract;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Enums\UserRole;
use App\Jobs\SubmitEInvoiceJob;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\CountryConfiguration;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceStatusSync;
use Tests\TenantTestCase;

/**
 * Issue #668 (EI6): the e-invoice supplier identity (TIN, BRN, SST number,
 * address) and the auto-submit country gate came from the TENANT — every
 * legal entity's invoices were submitted under one TIN, and a SG entity in a
 * MY tenant could never be routed to its own e-invoicing system.
 *
 * Contract asserted here (all actions through the real HTTP routes; the only
 * fake is the LHDN HTTP boundary — a SubmissionProviderContract stub in the
 * CountryPluginRegistry, as in Issue664/Issue667 tests):
 *  - each MY entity's invoice reaches MyInvois with ITS OWN supplier TIN,
 *    BRN, SST number, name and address;
 *  - an entity without identifiers falls back to the tenant configuration
 *    and the submission reports that fallback explicitly;
 *  - an entity with its own TIN never borrows the tenant's BRN;
 *  - auto-submit gating uses the entity's country: a SG entity's posted
 *    invoice is queued for SG (Peppol) and not for MY, a MY entity's for MY;
 *  - a manual submission to a country other than the entity's is refused.
 */
class Issue668EInvoiceEntitySupplierIdentityTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Product $product;

    private Issue668FakeLhdnProvider $lhdn;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId, ['tenant_country_code' => 'MY']);
        $this->mockFeatureGate();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 668 Multi-entity Tenant',
            'email' => 'i668-'.substr(md5($this->tenantId), 0, 8).'@example.com',
            'plan' => 'standard',
            'tenant_country_code' => 'MY',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => UserRole::ADMIN->value,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CompanySettings::query()->create([
            'company_name' => 'Tenant Holding Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $this->groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 668 Group',
            'code' => 'I668-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Issue 668 Widget',
            'sku' => 'I668-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 60,
            'track_inventory' => false,
        ]);

        Warehouse::create([
            'name' => 'Issue 668 Warehouse',
            'code' => 'I668-WH-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->seedPostingAccountsAndMappings();

        $this->lhdn = new Issue668FakeLhdnProvider;
        app(CountryPluginRegistry::class)->register('MY', [
            'validator' => MFRSValidationService::class,
            'mapper' => MyInvoiceMapper::class,
            'provider' => $this->lhdn,
            'sync' => MyInvoiceStatusSync::class,
        ]);

        // Tenant-level configuration: the identity every entity used to be
        // submitted under.
        CountryConfiguration::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'provider_name' => 'myinvoice',
            'is_enabled' => true,
            'test_mode' => true,
            'api_credentials' => [
                'myinvoice_client_id' => 'test-client-id',
                'myinvoice_client_secret' => 'test-client-secret',
                'supplier_tin' => 'C1000000000',
                'supplier_name' => 'Tenant Holding Sdn Bhd',
                'supplier_registration_number' => '201901000009',
            ],
            'settings' => ['auto_submit' => true],
        ]);

        // The SG status-sync plugin (resolved by SubmitEInvoiceJob for its
        // retry policy) requires an access-point endpoint in config. No HTTP
        // is made: the queue is faked in every test.
        config(['compliance.singapore.peppol_test_endpoint' => 'https://peppol-ap.invalid/api']);

        CountryConfiguration::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'SG',
            'provider_name' => 'peppol',
            'is_enabled' => true,
            'test_mode' => true,
            'api_credentials' => [],
            'settings' => ['auto_submit' => true],
        ]);
    }

    public function test_each_my_entity_invoice_is_submitted_under_its_own_supplier_identity(): void
    {
        Queue::fake();

        $alpha = $this->createLegalEntity('Alpha Trading Sdn Bhd', 'MY', [
            'tax_id' => 'C2000000001',
            'registration_number' => '202401000001',
            'sst_registration_number' => 'W10-2401-32000001',
            'address' => '1 Jalan Alpha, Kuala Lumpur',
        ]);
        $beta = $this->createLegalEntity('Beta Services Sdn Bhd', 'MY', [
            'tax_id' => 'C3000000002',
            'registration_number' => '202402000002',
            'address' => '2 Jalan Beta, Penang',
        ]);

        $alphaInvoice = $this->postedInvoiceFor($alpha);
        $betaInvoice = $this->postedInvoiceFor($beta);

        $alphaResponse = $this->submitEInvoice($alphaInvoice, 'MY');
        $alphaResponse->assertOk();
        $this->submitEInvoice($betaInvoice, 'MY')->assertOk();

        $this->assertCount(2, $this->lhdn->submitCalls);
        [$alphaPayload, $betaPayload] = $this->lhdn->submitCalls;

        $this->assertSame('C2000000001', $alphaPayload['supplier']['tin'] ?? null, 'entity A is submitted under its own TIN');
        $this->assertSame('202401000001', $alphaPayload['supplier']['company_registration'] ?? null);
        $this->assertSame('202401000001', $alphaPayload['supplier']['identity_number'] ?? null);
        $this->assertSame('W10-2401-32000001', $alphaPayload['supplier']['sst_registration'] ?? null);
        $this->assertSame('Alpha Trading Sdn Bhd', $alphaPayload['supplier']['name'] ?? null);
        $this->assertSame('1 Jalan Alpha, Kuala Lumpur', $alphaPayload['supplier']['address']['street'] ?? null);

        $this->assertSame('C3000000002', $betaPayload['supplier']['tin'] ?? null, 'entity B is submitted under its own TIN');
        $this->assertSame('202402000002', $betaPayload['supplier']['company_registration'] ?? null);
        $this->assertSame('Beta Services Sdn Bhd', $betaPayload['supplier']['name'] ?? null);
        $this->assertSame('2 Jalan Beta, Penang', $betaPayload['supplier']['address']['street'] ?? null);

        // The submission reports where the identity came from.
        $this->assertSame('legal_entity', $alphaResponse->json('data.supplier_identity.source'));
        $this->assertSame($alpha, $alphaResponse->json('data.supplier_identity.legal_entity_id'));
        $this->assertSame('tenant_configuration', $alphaResponse->json('data.supplier_identity.credentials_source'));
    }

    public function test_entity_without_identifiers_falls_back_to_tenant_configuration_and_reports_it(): void
    {
        Queue::fake();

        $plain = $this->createLegalEntity('Plain Entity', 'MY', []);

        $response = $this->submitEInvoice($this->postedInvoiceFor($plain), 'MY');
        $response->assertOk();

        $this->assertCount(1, $this->lhdn->submitCalls);
        $payload = $this->lhdn->submitCalls[0];
        $this->assertSame('C1000000000', $payload['supplier']['tin'] ?? null, 'no entity TIN: the tenant TIN is the fallback');
        $this->assertSame('201901000009', $payload['supplier']['company_registration'] ?? null);

        $this->assertSame('tenant_configuration', $response->json('data.supplier_identity.source'));
        $this->assertTrue($response->json('data.supplier_identity.fallback_used'), 'the fallback is reported explicitly');
        $this->assertContains('supplier_tin', $response->json('data.supplier_identity.fallback_fields'));
        $this->assertContains('supplier_registration_number', $response->json('data.supplier_identity.fallback_fields'));
    }

    public function test_entity_with_its_own_tin_never_borrows_the_tenant_registration_number(): void
    {
        Queue::fake();

        $tinOnly = $this->createLegalEntity('Tin Only Sdn Bhd', 'MY', ['tax_id' => 'C4000000004']);

        $response = $this->submitEInvoice($this->postedInvoiceFor($tinOnly), 'MY');

        $response->assertStatus(400);
        $this->assertStringContainsString('registration', strtolower((string) $response->json('message')));
        $this->assertCount(0, $this->lhdn->submitCalls, 'a mixed entity/tenant identity must never reach LHDN');
    }

    public function test_auto_submit_gating_uses_the_entity_country(): void
    {
        Queue::fake();

        $myEntity = $this->createLegalEntity('Alpha Trading Sdn Bhd', 'MY', ['tax_id' => 'C2000000001', 'registration_number' => '202401000001']);
        $sgEntity = $this->createLegalEntity('Gamma Pte Ltd', 'SG', ['registration_number' => '201912345K']);

        $myInvoice = $this->postedInvoiceFor($myEntity);
        $sgInvoice = $this->postedInvoiceFor($sgEntity);

        $this->assertSame(['MY'], $this->queuedCountries($myInvoice), 'a MY entity invoice is queued for MyInvois only');
        $this->assertSame(['SG'], $this->queuedCountries($sgInvoice), 'a SG entity invoice is queued for Peppol, not MyInvois');

        Queue::assertPushed(SubmitEInvoiceJob::class, fn (SubmitEInvoiceJob $job) => $job->documentId === (int) $sgInvoice->id
            && $job->countryCode === 'SG');
        Queue::assertNotPushed(SubmitEInvoiceJob::class, fn (SubmitEInvoiceJob $job) => $job->documentId === (int) $sgInvoice->id
            && $job->countryCode === 'MY');
    }

    public function test_manual_submission_to_another_country_than_the_entity_is_refused(): void
    {
        Queue::fake();

        $sgEntity = $this->createLegalEntity('Gamma Pte Ltd', 'SG', ['registration_number' => '201912345K']);
        $sgInvoice = $this->postedInvoiceFor($sgEntity);

        $document = ComplianceDocument::query()->create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $sgInvoice->id,
            'document_number' => $sgInvoice->invoice_number,
            'issued_date' => $sgInvoice->invoice_date,
            'total_amount' => $sgInvoice->total,
            'currency_code' => 'MYR',
            'compliance_status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/compliance/documents/'.$document->id.'/submit', ['country_code' => 'MY']);

        $response->assertStatus(400);
        $this->assertStringContainsString('ENTITY_COUNTRY_MISMATCH', (string) $response->json('message'));
        $this->assertCount(0, $this->lhdn->submitCalls, 'a SG entity invoice must never reach MyInvois');
    }

    /**
     * @return array<int, string>
     */
    private function queuedCountries(Invoice $invoice): array
    {
        return ComplianceDocument::query()
            ->where('tenant_id', $this->tenantId)
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->orderBy('country_code')
            ->pluck('country_code')
            ->map(fn ($code) => strtoupper((string) $code))
            ->all();
    }

    private function submitEInvoice(Invoice $invoice, string $countryCode): TestResponse
    {
        $document = ComplianceDocument::query()
            ->where('tenant_id', $this->tenantId)
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->where('country_code', $countryCode)
            ->first();

        $this->assertNotNull($document, 'Fixture: posting must queue the compliance document for '.$countryCode.'.');

        return $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/compliance/documents/'.$document->id.'/submit', ['country_code' => $countryCode]);
    }

    private function postedInvoiceFor(int $legalEntityId): Invoice
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => $legalEntityId,
            'payment_terms' => 30,
            'type' => 'company',
            'tax_number' => 'C9876543210',
            'company_registration_number' => '202301234567',
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'MYR',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Issue 668 line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated($response->getContent());

        $invoice = Invoice::findOrFail($response->json('id'));
        $this->assertSame($legalEntityId, (int) $invoice->legal_entity_id, 'Fixture: the invoice carries its customer entity.');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        return $invoice->fresh();
    }

    /**
     * @param  array<string, string>  $identity
     */
    private function createLegalEntity(string $name, string $countryCode, array $identity): int
    {
        return DB::table('legal_entities')->insertGetId(array_merge([
            'group_id' => $this->groupId,
            'name' => $name,
            'code' => 'I668-E-'.Str::upper(Str::random(5)),
            'country_code' => $countryCode,
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $identity));
    }

    private function seedPostingAccountsAndMappings(): void
    {
        $ar = ChartOfAccount::factory()->create(['code' => '1100-I668', 'name' => 'AR', 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => '4000-I668', 'name' => 'Revenue', 'type' => 'revenue', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-I668', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);
        $cash = ChartOfAccount::factory()->create(['code' => '1000-I668', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
        AccountMapping::create(['mapping_key' => 'cash', 'account_id' => $cash->id]);
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

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }
}

/**
 * LHDN boundary double for issue #668: records every submit call instead of
 * making HTTP requests.
 */
class Issue668FakeLhdnProvider implements SubmissionProviderContract
{
    /** @var array<int, array> */
    public array $submitCalls = [];

    public function submit(array $payload, array $credentials): array
    {
        $this->submitCalls[] = $payload;
        $sequence = count($this->submitCalls);

        return [
            'success' => true,
            'provider_reference' => 'SUB-I668-'.$sequence,
            'external_document_uuid' => 'uuid-i668-'.$sequence,
            'external_long_id' => 'long-id-i668-'.$sequence,
            'validation_link' => 'https://portal.test/validate/i668-'.$sequence,
            'status_details' => ['status' => 'Valid'],
            'message' => 'Submitted successfully',
        ];
    }

    public function getStatus(string $providerReference, array $credentials): array
    {
        return ['success' => true, 'status' => 'accepted', 'provider_reference' => $providerReference, 'details' => [], 'message' => 'Valid'];
    }

    public function cancel(string $documentUuid, array $credentials): array
    {
        return ['success' => true, 'status' => 'cancelled', 'provider_status' => 'cancelled', 'details' => []];
    }

    public function supportsCancellation(): bool
    {
        return true;
    }

    public function retry(array $payload, string $providerReference, array $credentials): array
    {
        return ['success' => true, 'provider_reference' => $providerReference, 'message' => 'Retried'];
    }

    public function getConfigRequirements(): array
    {
        return ['fields' => [], 'description' => 'Test provider for issue #668'];
    }
}
