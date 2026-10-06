<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\SubmissionProviderContract;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Enums\UserRole;
use App\Jobs\SubmitEInvoiceJob;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
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
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceStatusSync;
use Tests\TenantTestCase;

/**
 * Issue #673 (EINV-UX4): bulk retry / resubmit of the failed and invalid
 * e-invoice queue, with the LHDN error explained in plain language and
 * linked to the record and field to fix.
 *
 * Acceptance criteria asserted through the real HTTP routes:
 *  - a failed / invalid queue can be multi-selected and retried; each retry
 *    goes through the #667 SubmitEInvoiceJob (one job per document), and a
 *    Valid or already-queued document is never resubmitted;
 *  - each error code the integration receives is translated into plain
 *    language naming the record ("... customer ABC") with a fix link to that
 *    record and field; an unknown error shows the raw message;
 *  - the retry count and the last error are kept per document.
 *
 * LHDN is never called: the provider is a test double.
 */
class Issue673EInvoiceBulkRetryTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    private int $legalEntityId;

    private Issue673FakeLhdnProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 673 Bulk Retry Tenant',
            'email' => 'i673-'.substr(md5($this->tenantId), 0, 8).'@example.com',
            'plan' => 'standard',
        ]));

        $this->admin = $this->userWithRole(UserRole::ADMIN, true);

        CompanySettings::query()->create([
            'company_name' => 'Issue 673 Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $this->legalEntityId = $this->createLegalEntity();

        $this->customer = Customer::factory()->create([
            'name' => 'ABC Trading Sdn Bhd',
            'legal_entity_id' => $this->legalEntityId,
            'payment_terms' => 30,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Issue 673 Widget',
            'sku' => 'I673-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 60,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Issue 673 Warehouse',
            'code' => 'I673-WH-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->seedPostingAccountsAndMappings();

        $this->provider = new Issue673FakeLhdnProvider;
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
                'supplier_tin' => 'C1234567890',
                'supplier_name' => 'Issue 673 Sdn Bhd',
                'supplier_registration_number' => '202400000001',
            ],
            'settings' => ['auto_submit' => true],
        ]);

        $this->assertTenantSchemaReady(['invoices', 'acct_compliance_documents', 'acct_compliance_submissions']);
    }

    public function test_bulk_retry_queues_one_job_per_failed_or_invalid_document_and_never_resubmits_valid_ones(): void
    {
        Queue::fake();

        $failed = $this->postedInvoiceDocument();
        $invalid = $this->postedInvoiceDocument();
        $valid = $this->postedInvoiceDocument();
        $failed->update(['compliance_status' => 'failed', 'compliance_notes' => 'cURL error 28: timed out']);
        $invalid->update(['compliance_status' => 'rejected']);
        $valid->update(['compliance_status' => 'accepted']);

        // Only the retry's own jobs count from here.
        Queue::fake();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/compliance/documents/bulk-retry', [
                'document_ids' => [$failed->id, $invalid->id, $valid->id],
            ]);

        $response->assertOk();
        $results = collect($response->json('data.results'))->keyBy('id');
        $this->assertSame('queued', $results[$failed->id]['outcome']);
        $this->assertSame('queued', $results[$invalid->id]['outcome']);
        $this->assertSame('skipped', $results[$valid->id]['outcome'], 'a Valid document is not resubmitted');

        Queue::assertPushed(SubmitEInvoiceJob::class, 2);
        foreach ([$failed, $invalid] as $document) {
            Queue::assertPushed(SubmitEInvoiceJob::class, fn (SubmitEInvoiceJob $job) => $job->documentId === (int) $document->document_id
                && $job->documentType === 'invoice'
                && $job->countryCode === 'MY'
                && $job->tenantId === $this->tenantId);
        }

        $this->assertSame(1, (int) $failed->fresh()->retry_count, 'the retry is counted on the document');
        $this->assertNotNull($failed->fresh()->last_retried_at);
        $this->assertSame('queued', $failed->fresh()->compliance_status);
        $this->assertSame(0, (int) $valid->fresh()->retry_count);
        $this->assertSame('accepted', $valid->fresh()->compliance_status);

        // A second click while they are queued must not queue them again.
        $again = $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/compliance/documents/bulk-retry', [
                'document_ids' => [$failed->id, $invalid->id],
            ]);
        $again->assertOk();
        $this->assertSame(['skipped', 'skipped'], collect($again->json('data.results'))->pluck('outcome')->all());
        Queue::assertPushed(SubmitEInvoiceJob::class, 2);
        $this->assertSame(1, (int) $failed->fresh()->retry_count);
    }

    public function test_failed_document_shows_plain_language_error_linked_to_the_customer_field_and_keeps_retry_count(): void
    {
        // The integration's own code for a buyer TIN LHDN does not accept.
        $this->provider->failWith('BUYER_TIN_INVALID', 'Buyer TIN C0000000000 is not valid');

        // Posting auto-submits through the sync queue and fails.
        $document = $this->postedInvoiceDocument();
        $this->assertSame('failed', $document->fresh()->compliance_status);
        $this->assertCount(1, $this->provider->submitCalls);

        $row = $this->queueRow($document);
        $this->assertSame(0, (int) $row['retry_count']);
        $error = $row['errors'][0] ?? null;
        $this->assertNotNull($error, 'the failed queue row carries its last error');
        $this->assertSame('BUYER_TIN_INVALID', $error['code']);
        $this->assertSame('Buyer TIN C0000000000 is not valid', $error['raw_message']);
        $this->assertStringContainsString('Buyer TIN', $error['message']);
        $this->assertStringContainsString('ABC Trading Sdn Bhd', $error['message'], 'the plain-language error names the customer');
        $this->assertSame('customer', $error['fix']['type'] ?? null);
        $this->assertSame($this->customer->id, $error['fix']['id'] ?? null);
        $this->assertContains('tax_number', $error['fix']['fields'] ?? []);

        // Retry while LHDN still refuses: counted, still failed, error kept.
        $this->bulkRetry([$document->id])->assertOk();
        $this->assertCount(2, $this->provider->submitCalls, 'the retry resubmits through the queued job');
        $row = $this->queueRow($document);
        $this->assertSame('failed', $row['compliance_status']);
        $this->assertSame(1, (int) $row['retry_count']);
        $this->assertSame('BUYER_TIN_INVALID', $row['errors'][0]['code'] ?? null);

        // Fixed: the retry succeeds, and a further retry never double-submits.
        $this->provider->succeed();
        $this->bulkRetry([$document->id])->assertOk();
        $this->assertCount(3, $this->provider->submitCalls);
        $this->assertSame('submitted', $document->fresh()->compliance_status);
        $this->assertSame(2, (int) $document->fresh()->retry_count);

        $this->bulkRetry([$document->id])
            ->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'skipped');
        $this->assertCount(3, $this->provider->submitCalls, 'a submitted document is not submitted twice');
        $this->assertSame(2, (int) $document->fresh()->retry_count);
    }

    public function test_invalid_document_explains_lhdn_tax_error_and_links_the_invoice_lines(): void
    {
        Queue::fake();
        $document = $this->postedInvoiceDocument();

        // An LHDN Invalid result as the status sync records it (fixture code
        // and message from the provider's own unit test).
        $document->update(['compliance_status' => 'rejected']);
        ComplianceSubmission::create([
            'tenant_id' => $this->tenantId,
            'document_id' => $document->id,
            'country_code' => 'MY',
            'provider_name' => 'myinvoice',
            'provider_reference' => 'SUB-I673-INVALID',
            'submission_status' => 'rejected',
            'error_code' => 'INVALID_TAX_CODE',
            'error_message' => 'Tax code SSTX is invalid for this document type',
            'idempotency_key' => (string) Str::uuid(),
            'submitted_at' => now(),
        ]);

        $row = $this->queueRow($document);
        $error = $row['errors'][0] ?? null;
        $this->assertNotNull($error);
        $this->assertSame('INVALID_TAX_CODE', $error['code']);
        $this->assertSame('Tax code SSTX is invalid for this document type', $error['raw_message']);
        $this->assertNotSame($error['raw_message'], $error['message'], 'a known code is translated');
        $this->assertSame('invoice', $error['fix']['type'] ?? null);
        $this->assertSame((int) $document->document_id, $error['fix']['id'] ?? null);
        $this->assertContains('lines', $error['fix']['fields'] ?? []);
    }

    public function test_unknown_error_shows_the_raw_message_without_a_guessed_fix(): void
    {
        $this->provider->failWith('SOMETHING_NEW', 'Document rejected for an unlisted reason');

        $document = $this->postedInvoiceDocument();
        $this->assertSame('failed', $document->fresh()->compliance_status);

        $error = $this->queueRow($document)['errors'][0] ?? null;
        $this->assertNotNull($error);
        $this->assertSame('SOMETHING_NEW', $error['code']);
        $this->assertSame('Document rejected for an unlisted reason', $error['message'], 'an unknown error is shown as received');
        $this->assertNull($error['fix']);
    }

    public function test_a_validation_failure_links_each_rule_to_the_record_that_fixes_it(): void
    {
        Queue::fake();
        $document = $this->postedInvoiceDocument();

        // The buyer became a business without a TIN; the submission's own
        // validator refused it with its "Validation failed: ..." message.
        $this->customer->forceFill(['buyer_type' => 'business', 'tax_number' => null])->save();
        $document->update([
            'compliance_status' => 'failed',
            'compliance_notes' => "Validation failed: Customer 'ABC Trading Sdn Bhd' has no TIN — add it or mark as individual",
        ]);

        $errors = $this->queueRow($document)['errors'];
        $buyerTin = collect($errors)->firstWhere('issue_code', 'buyer_tin_missing');
        $this->assertNotNull($buyerTin, 'the validator rule is recognised (#671 readiness code)');
        $this->assertSame("Customer 'ABC Trading Sdn Bhd' has no TIN — add it or mark as individual", $buyerTin['message']);
        $this->assertSame('customer', $buyerTin['fix']['type'] ?? null);
        $this->assertSame($this->customer->id, $buyerTin['fix']['id'] ?? null);
        $this->assertContains('tax_number', $buyerTin['fix']['fields'] ?? []);
    }

    public function test_bulk_retry_requires_the_posting_permission(): void
    {
        Queue::fake();
        $document = $this->postedInvoiceDocument();
        $document->update(['compliance_status' => 'failed']);
        Queue::fake();

        $viewer = $this->userWithRole(UserRole::VIEWER, false);

        $this->actingAs($viewer)
            ->postJson('/api/v1/accounting/compliance/documents/bulk-retry', ['document_ids' => [$document->id]])
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(0, (int) $document->fresh()->retry_count);
    }

    /**
     * @return array<string, mixed>
     */
    private function queueRow(ComplianceDocument $document): array
    {
        $this->rebindTenant();
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/compliance/documents?status=failed,rejected&with_errors=1');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $document->id);
        $this->assertNotNull($row, 'the document is in the failed / invalid queue');

        return $row;
    }

    /**
     * A retry runs the job on the sync queue inside the request; like the
     * #667 digest test, restore the test's bound tenant context around it.
     */
    private function bulkRetry(array $documentIds): \Illuminate\Testing\TestResponse
    {
        $this->rebindTenant();
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/compliance/documents/bulk-retry', ['document_ids' => $documentIds]);
        $this->rebindTenant();

        return $response;
    }

    private function rebindTenant(): void
    {
        $this->bindTenantContext($this->tenantId);
    }

    private function postedInvoiceDocument(): ComplianceDocument
    {
        $invoice = $this->createInvoiceViaApi();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();
        $this->rebindTenant();

        return ComplianceDocument::query()
            ->where('tenant_id', $this->tenantId)
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->firstOrFail();
    }

    private function userWithRole(UserRole $role, bool $default): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role->value,
            'is_default' => $default,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function createInvoiceViaApi(): Invoice
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'MYR',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Issue 673 line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 6,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated($response->getContent());

        return Invoice::findOrFail($response->json('id'));
    }

    private function createLegalEntity(): int
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 673 Group',
            'code' => 'I673-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Issue 673 Entity',
            'code' => 'I673-E-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPostingAccountsAndMappings(): void
    {
        $ar = ChartOfAccount::factory()->create(['code' => '1100-I673', 'name' => 'AR', 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => '4000-I673', 'name' => 'Revenue', 'type' => 'revenue', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-I673', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);
        $cash = ChartOfAccount::factory()->create(['code' => '1000-I673', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
        AccountMapping::create(['mapping_key' => 'cash', 'account_id' => $cash->id]);
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => $isEntitled,
                'source' => $isEntitled ? 'tier_included' : null,
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
 * LHDN boundary double for issue #673: answers like the MyInvois provider
 * (success, or a failure carrying the integration's error_code and message)
 * so no real e-invoicing endpoint is ever called.
 */
class Issue673FakeLhdnProvider implements SubmissionProviderContract
{
    private ?array $failure = null;

    /** @var array<int, array> */
    public array $submitCalls = [];

    public function failWith(string $code, string $message): void
    {
        $this->failure = ['code' => $code, 'message' => $message];
    }

    public function succeed(): void
    {
        $this->failure = null;
    }

    public function submit(array $payload, array $credentials): array
    {
        $this->submitCalls[] = $payload;

        if ($this->failure !== null) {
            return [
                'success' => false,
                'message' => $this->failure['message'],
                'error_code' => $this->failure['code'],
            ];
        }

        $n = count($this->submitCalls);

        return [
            'success' => true,
            'provider_reference' => "SUB-I673-{$n}",
            'external_document_uuid' => "uuid-i673-{$n}",
            'validation_link' => "https://portal.test/validate/i673-{$n}",
            'status_details' => ['status' => 'Submitted'],
            'message' => 'Submitted successfully',
        ];
    }

    public function getStatus(string $providerReference, array $credentials): array
    {
        return ['success' => true, 'status' => 'pending', 'provider_reference' => $providerReference, 'details' => [], 'message' => 'Submitted'];
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
        return ['fields' => [], 'description' => 'Test provider for issue #673'];
    }
}
