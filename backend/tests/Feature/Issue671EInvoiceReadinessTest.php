<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\SubmissionProviderContract;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Enums\UserRole;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceStatusSync;
use Tests\TenantTestCase;

/**
 * Issue #671 (EINV-UX2): e-invoice problems only surfaced after submission
 * (Invalid) or were silently masked by defaults.
 *
 * Contract asserted here, through the real HTTP routes:
 *  - GET /invoices/{id}/einvoice-readiness runs the country pack's own
 *    validation as a dry run on a draft invoice and returns a checklist of
 *    the gaps — buyer TIN, products without an LHDN classification, buyer
 *    state, supplier identity — each pointing at the record that fixes it;
 *  - the gaps that block submission (what the validator refuses) are
 *    "blocking"; the gaps the mapper/provider would silently default are
 *    "warning" and say which default would be sent;
 *  - the dry run writes nothing and never calls the provider;
 *  - fixing the customer through the customer endpoint clears the gap;
 *  - with no e-invoicing country enabled the check is "not applicable".
 */
class Issue671EInvoiceReadinessTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Issue671FakeLhdnProvider $lhdn;

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
            'name' => 'Issue 671 Tenant',
            'email' => 'i671-'.substr(md5($this->tenantId), 0, 8).'@example.com',
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
            'company_name' => 'Issue 671 Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        Warehouse::create([
            'name' => 'Issue 671 Warehouse',
            'code' => 'I671-WH-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->seedPostingAccountsAndMappings();

        $this->lhdn = new Issue671FakeLhdnProvider;
        app(CountryPluginRegistry::class)->register('MY', [
            'validator' => MFRSValidationService::class,
            'mapper' => MyInvoiceMapper::class,
            'provider' => $this->lhdn,
            'sync' => MyInvoiceStatusSync::class,
        ]);
    }

    public function test_readiness_lists_the_gaps_with_the_record_that_fixes_each_one(): void
    {
        $this->enableMyInvois();

        $customer = $this->businessCustomer(['tax_number' => null], null);
        $unclassified = $this->product(null);
        $alsoUnclassified = $this->product(null);
        $classified = $this->product('022');

        $invoice = $this->draftInvoice($customer, [$unclassified, $alsoUnclassified, $classified]);

        $response = $this->readiness($invoice);
        $response->assertOk();

        $this->assertTrue($response->json('applicable'), 'MyInvois is enabled for the invoice country.');
        $this->assertFalse($response->json('ready'), 'A business buyer without a TIN cannot be submitted.');
        $this->assertSame(['MY'], $response->json('countries'));

        $tin = $this->issue($response, 'buyer_tin_missing');
        $this->assertSame('blocking', $tin['severity']);
        $this->assertSame('MY', $tin['country_code']);
        $this->assertSame('customer', $tin['fix']['type']);
        $this->assertSame($customer->id, $tin['fix']['id']);
        $this->assertContains('tax_number', $tin['fix']['fields']);

        // The pack declares a default classification, so the mapper would
        // send it instead of refusing — a warning naming the default, one per
        // product, never for the product that has its own code.
        $classification = $this->issues($response, 'line_classification_missing');
        $this->assertCount(2, $classification, 'one gap per unclassified product');
        $this->assertEqualsCanonicalizing(
            [$unclassified->id, $alsoUnclassified->id],
            array_map(fn ($issue) => $issue['fix']['id'], $classification),
        );
        foreach ($classification as $issue) {
            $this->assertSame('warning', $issue['severity']);
            $this->assertSame('product', $issue['fix']['type']);
            $this->assertStringContainsString('001', $issue['message'], 'the message names the default that would be sent');
        }

        $state = $this->issue($response, 'buyer_state_missing');
        $this->assertSame('warning', $state['severity']);
        $this->assertSame('customer', $state['fix']['type']);
        $this->assertSame($customer->id, $state['fix']['id']);
        $this->assertContains('state', $state['fix']['fields']);

        $this->assertSame(1, $response->json('summary.blocking'));
        $this->assertSame(3, $response->json('summary.warnings'));

        // A dry run: nothing is recorded and the provider is never called.
        $this->assertSame(0, ComplianceDocument::query()->count());
        $this->assertSame([], $this->lhdn->submitCalls);
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_a_missing_pack_default_makes_the_classification_gap_blocking(): void
    {
        $this->enableMyInvois();
        config(['tax_packs.MY.einvoice.default_classification' => null]);

        $customer = $this->businessCustomer();
        $unclassified = $this->product(null);

        $response = $this->readiness($this->draftInvoice($customer, [$unclassified]));
        $response->assertOk();

        $issue = $this->issue($response, 'line_classification_missing');
        $this->assertSame('blocking', $issue['severity'], 'the mapper refuses a line with no classification at all');
        $this->assertSame($unclassified->id, $issue['fix']['id']);
        $this->assertFalse($response->json('ready'));
    }

    public function test_fixing_the_customer_in_place_clears_the_gap(): void
    {
        $this->enableMyInvois();

        $customer = $this->businessCustomer(['tax_number' => null], null);
        $invoice = $this->draftInvoice($customer, [$this->product('022')]);

        $before = $this->readiness($invoice);
        $this->assertFalse($before->json('ready'));

        // The inline fix writes through the existing customer endpoints: the
        // TIN on the customer, the state on the address the gap points at.
        $addressId = $this->issue($before, 'buyer_state_missing')['fix']['address_id'];
        $this->assertSame($customer->addresses()->value('id'), $addressId);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/customers/{$customer->id}", ['tax_number' => 'C2584563200'])
            ->assertOk();
        $this->actingAs($this->admin)
            ->putJson("/api/v1/customers/{$customer->id}/addresses/{$addressId}", ['state' => 'Selangor'])
            ->assertOk();

        $response = $this->readiness($invoice);
        $response->assertOk();
        $this->assertTrue($response->json('ready'), json_encode($response->json('issues')));
        $this->assertSame([], $response->json('issues'));
    }

    public function test_supplier_identity_gaps_point_at_the_legal_entity(): void
    {
        $this->enableMyInvois();

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 671 Group',
            'code' => 'I671-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The entity carries its own TIN but no registration number: it is
        // the supplier, and its missing BRN is not borrowed from the tenant.
        $entityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Gamma Sdn Bhd',
            'code' => 'I671-E-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'tax_id' => 'C4000000004',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = $this->businessCustomer(['legal_entity_id' => $entityId]);
        $response = $this->readiness($this->draftInvoice($customer, [$this->product('022')]));
        $response->assertOk();

        $issue = $this->issue($response, 'supplier_registration_missing');
        $this->assertSame('blocking', $issue['severity']);
        $this->assertSame('legal_entity', $issue['fix']['type']);
        $this->assertSame($entityId, $issue['fix']['id']);
        $this->assertFalse($response->json('ready'));
    }

    public function test_complete_data_is_ready(): void
    {
        $this->enableMyInvois();

        $response = $this->readiness($this->draftInvoice($this->businessCustomer(), [$this->product('022')]));
        $response->assertOk();

        $this->assertTrue($response->json('applicable'));
        $this->assertTrue($response->json('ready'), json_encode($response->json('issues')));
        $this->assertSame([], $response->json('issues'));
    }

    public function test_without_an_enabled_e_invoice_country_the_check_is_not_applicable(): void
    {
        $response = $this->readiness($this->draftInvoice($this->businessCustomer(['tax_number' => null]), [$this->product(null)]));
        $response->assertOk();

        $this->assertFalse($response->json('applicable'));
        $this->assertTrue($response->json('ready'));
        $this->assertSame([], $response->json('issues'));
    }

    /**
     * Issue #344: the pre-flight checklist's e-invoice row reuses this
     * country-pack readiness check instead of a generic "customer has a
     * tax_number" test — a readiness gap surfaces there (as a warning: it
     * blocks e-invoice submission, not posting), complete data passes.
     */
    public function test_the_invoice_preflight_e_invoice_row_uses_the_country_pack_readiness(): void
    {
        $this->enableMyInvois();

        // A TIN on the customer but no buyer state: the generic TIN check
        // passed this; the pack's readiness does not.
        $gappy = $this->draftInvoice($this->businessCustomer([], null), [$this->product('022')]);
        $row = $this->preflightRow($gappy);
        $this->assertSame('warn', $row['status'], json_encode($row));
        $this->assertStringContainsString('E-invoice', $row['label']);
        $this->assertStringContainsString($this->issue($this->readiness($gappy), 'buyer_state_missing')['message'], $row['detail']);

        $blocking = $this->draftInvoice($this->businessCustomer(['tax_number' => null]), [$this->product('022')]);
        $blockingRow = $this->preflightRow($blocking);
        $this->assertSame('warn', $blockingRow['status']);
        $this->assertStringContainsString($this->issue($this->readiness($blocking), 'buyer_tin_missing')['message'], $blockingRow['detail']);

        $readyRow = $this->preflightRow($this->draftInvoice($this->businessCustomer(), [$this->product('022')]));
        $this->assertSame('pass', $readyRow['status'], json_encode($readyRow));

        $this->assertSame(0, ComplianceDocument::query()->count());
        $this->assertSame([], $this->lhdn->submitCalls);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function preflightRow(Invoice $invoice): array
    {
        $checks = $this->actingAs($this->admin)->getJson("/api/v1/invoices/{$invoice->id}/preflight")->assertOk()->json('checks');

        return collect($checks)->firstWhere('key', 'e_invoice');
    }

    private function readiness(Invoice $invoice): TestResponse
    {
        return $this->actingAs($this->admin)->getJson("/api/v1/invoices/{$invoice->id}/einvoice-readiness");
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(TestResponse $response, string $code): array
    {
        $matches = $this->issues($response, $code);
        $this->assertCount(1, $matches, "Expected one '{$code}' issue in: ".json_encode($response->json('issues')));

        return $matches[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function issues(TestResponse $response, string $code): array
    {
        return array_values(array_filter(
            (array) $response->json('issues'),
            fn ($issue) => ($issue['code'] ?? null) === $code,
        ));
    }

    private function enableMyInvois(): void
    {
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
                'supplier_name' => 'Issue 671 Sdn Bhd',
                'supplier_registration_number' => '201901000009',
            ],
            'settings' => ['auto_submit' => false],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function businessCustomer(array $overrides = [], ?string $state = 'Selangor'): Customer
    {
        // The structured billing address (#436 CU8) is where the customer
        // form keeps the buyer's state; the customers table has no state.
        $customer = Customer::factory()->create(array_merge([
            'name' => 'Delta Retail Sdn Bhd',
            'type' => 'company',
            'buyer_type' => 'business',
            'payment_terms' => 30,
            'tax_number' => 'C9876543210',
            'company_registration_number' => '202301234567',
            'billing_address' => null,
            'shipping_address' => null,
        ], $overrides));

        $customer->addresses()->create([
            'type' => 'billing',
            'street' => '8 Jalan Delta',
            'city' => 'Shah Alam',
            'state' => $state,
            'zip' => '40000',
            'country' => 'MY',
            'is_default' => true,
        ]);

        return $customer;
    }

    private function product(?string $classificationCode): Product
    {
        return Product::factory()->create([
            'name' => 'Issue 671 Item '.Str::upper(Str::random(4)),
            'sku' => 'I671-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 60,
            'track_inventory' => false,
            'einvoice_classification_code' => $classificationCode,
        ]);
    }

    /**
     * @param  list<Product>  $products
     */
    private function draftInvoice(Customer $customer, array $products): Invoice
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'MYR',
            'lines' => array_map(fn (Product $product) => [
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ], $products),
        ]);
        $response->assertCreated($response->getContent());

        return Invoice::findOrFail($response->json('id'));
    }

    private function seedPostingAccountsAndMappings(): void
    {
        $ar = ChartOfAccount::factory()->create(['code' => '1100-I671', 'name' => 'AR', 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => '4000-I671', 'name' => 'Revenue', 'type' => 'revenue', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-I671', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
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
 * LHDN boundary double: records submit calls so the test can prove the
 * readiness dry run never reaches the provider.
 */
class Issue671FakeLhdnProvider implements SubmissionProviderContract
{
    /** @var array<int, array> */
    public array $submitCalls = [];

    public function submit(array $payload, array $credentials): array
    {
        $this->submitCalls[] = $payload;

        return ['success' => true, 'provider_reference' => 'SUB-I671', 'message' => 'Submitted'];
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
        return ['fields' => [], 'description' => 'Test provider for issue #671'];
    }
}
