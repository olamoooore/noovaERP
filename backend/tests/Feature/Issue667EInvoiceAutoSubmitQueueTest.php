<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\SubmissionProviderContract;
use App\Accounting\Events\InvoicePosted;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Accounting\Services\ComplianceService;
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
use App\Platform\Exceptions\ExceptionQueue;
use App\Platform\Exceptions\ExceptionType;
use App\Platform\Notifications\NotificationLog;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceStatusSync;
use Tests\TenantTestCase;

/**
 * Issue #667 (EI5): the InvoicePosted listener submitted the e-invoice
 * inline, inside the posting request, and a failure (network, LHDN downtime,
 * validation) left no retry, no failed status on the invoice and no
 * notification.
 *
 * Contract asserted here (posting forced through the real HTTP route):
 *  - posting succeeds and creates the compliance document as `queued` within
 *    the posting transaction (it exists even when no listener runs);
 *  - the submission runs as a queued job, not inline in the request;
 *  - a provider failure schedules a retry with backoff; the last attempt
 *    sets the document `failed` with the reason, notifies the invoice owner
 *    and finance, and raises the accountant-owned platform exception;
 *  - a daily digest lists posted invoices whose e-invoice is not yet Valid.
 */
class Issue667EInvoiceAutoSubmitQueueTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private User $accountant;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    private int $legalEntityId;

    private Issue667ThrowingLhdnProvider $provider;

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
            'name' => 'Issue 667 Auto-submit Tenant',
            'email' => 'i667-'.substr(md5($this->tenantId), 0, 8).'@example.com',
            'plan' => 'standard',
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
            'company_name' => 'Issue 902 Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $this->legalEntityId = $this->createLegalEntity();

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => $this->legalEntityId,
            'payment_terms' => 30,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Issue 902 Widget',
            'sku' => 'I902-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 60,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Issue 902 Warehouse',
            'code' => 'I902-WH-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->seedPostingAccountsAndMappings();

        $this->accountant = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->accountant->id,
            'tenant_id' => $this->tenantId,
            'role' => UserRole::ACCOUNTANT->value,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->provider = new Issue667ThrowingLhdnProvider;
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
                'supplier_name' => 'Issue 667 Sdn Bhd',
                'supplier_registration_number' => '202400000001',
            ],
            'settings' => ['auto_submit' => true],
        ]);

        $this->assertTenantSchemaReady(['invoices', 'acct_compliance_documents', 'platform_exception_queue', 'notification_logs']);
    }

    private function complianceDocument(Invoice $invoice): ?ComplianceDocument
    {
        return ComplianceDocument::query()
            ->where('tenant_id', $this->tenantId)
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->first();
    }

    private function notifications(string $event, User $user): int
    {
        return NotificationLog::where('event', $event)->where('user_id', $user->id)->count();
    }

    public function test_posting_queues_the_document_and_does_not_submit_inline(): void
    {
        Queue::fake();

        $invoice = $this->createInvoiceViaApi();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $this->assertSame('posted', $invoice->fresh()->lifecycle_status, 'posting itself must succeed');
        $this->assertCount(0, $this->provider->submitCalls, 'the provider must not be called inside the posting request');

        $document = $this->complianceDocument($invoice);
        $this->assertNotNull($document, 'posting must create the compliance document');
        $this->assertSame('queued', $document->compliance_status);

        Queue::assertPushed(SubmitEInvoiceJob::class, fn (SubmitEInvoiceJob $job) => $job->documentType === 'invoice'
            && $job->documentId === (int) $invoice->id
            && $job->countryCode === 'MY'
            && $job->tenantId === $this->tenantId);
    }

    public function test_the_queued_document_is_created_by_the_posting_transaction_itself(): void
    {
        // No InvoicePosted listener runs: the document must still exist, so
        // it cannot depend on the post-commit dispatch.
        Event::fake([InvoicePosted::class]);

        $invoice = $this->createInvoiceViaApi();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $document = $this->complianceDocument($invoice);
        $this->assertNotNull($document, 'the posting transaction must create the compliance document');
        $this->assertSame('queued', $document->compliance_status);
    }

    public function test_provider_failure_retries_with_backoff_then_fails_notifies_and_raises(): void
    {
        Queue::fake();

        $invoice = $this->createInvoiceViaApi();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        /** @var SubmitEInvoiceJob $job */
        $job = Queue::pushed(SubmitEInvoiceJob::class)->first();
        $this->assertNotNull($job);

        // First attempt: the provider throws → a retry is scheduled.
        $job->withFakeQueueInteractions();
        $job->handle(app(ComplianceService::class));

        $job->assertReleased(60);
        $this->assertCount(1, $this->provider->submitCalls);
        $document = $this->complianceDocument($invoice);
        $this->assertSame('queued', $document->compliance_status, 'a retried submission stays queued');
        $this->assertStringContainsString('timed out', (string) $document->compliance_notes, 'the attempt failure reason is visible');
        $this->assertSame(0, $this->notifications('einvoice.submission_failed', $this->accountant), 'no alert before the last attempt');

        // Last attempt: the provider still throws → failed, notified, raised.
        $last = Queue::pushed(SubmitEInvoiceJob::class)->first();
        $last->withFakeQueueInteractions();
        $last->job->attempts = 6;
        $last->handle(app(ComplianceService::class));

        $last->assertNotReleased();
        $document->refresh();
        $this->assertSame('failed', $document->compliance_status, 'a terminal failure sets the document failed');
        $this->assertStringContainsString('timed out', (string) $document->compliance_notes, 'the failure reason is recorded');

        $this->assertSame(1, $this->notifications('einvoice.submission_failed', $this->admin), 'the invoice owner is notified');
        $this->assertSame(1, $this->notifications('einvoice.submission_failed', $this->accountant), 'finance is notified');
        $payload = NotificationLog::where('event', 'einvoice.submission_failed')
            ->where('user_id', $this->accountant->id)->first()->data ?? [];
        $this->assertSame($invoice->id, $payload['invoice_id'] ?? null);
        $this->assertSame('invoice', $payload['primary']['type'] ?? null, 'the alert links to the invoice');

        $exception = $this->firstQueued(ExceptionType::SALES_EINVOICE_AUTO_SUBMIT_FAILED);
        $this->assertNotNull($exception, 'a terminal failure reaches the platform exception queue');
        $this->assertSame('compliance.einvoice.submit', $exception->retry_handler);

        $this->assertSame('posted', $invoice->fresh()->lifecycle_status, 'posting is never undone by a submission failure');
    }

    public function test_daily_digest_lists_posted_invoices_not_yet_valid(): void
    {
        Queue::fake();

        $pending = $this->createInvoiceViaApi();
        $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$pending->id}/post")->assertOk();

        $valid = $this->createInvoiceViaApi();
        $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$valid->id}/post")->assertOk();
        $this->complianceDocument($valid)->update(['compliance_status' => 'accepted']);

        $this->bindTenantContext($this->tenantId);
        $this->artisan('compliance:send-einvoice-digest')->assertSuccessful();

        $log = NotificationLog::where('event', 'einvoice.daily_digest')
            ->where('user_id', $this->accountant->id)
            ->first();
        $this->assertNotNull($log, 'finance receives the daily digest');

        $data = $log->data ?? [];
        $numbers = array_column($data['invoices'] ?? [], 'invoice_number');
        $this->assertContains($pending->fresh()->invoice_number, $numbers, 'a queued (not yet Valid) invoice is listed');
        $this->assertNotContains($valid->fresh()->invoice_number, $numbers, 'a Valid invoice is not listed');
    }

    private function firstQueued(ExceptionType $type): ?ExceptionQueue
    {
        return ExceptionQueue::query()
            ->where('tenant_id', $this->tenantId)
            ->where('exception_type', $type->value)
            ->orderByDesc('created_at')
            ->first();
    }

    private function createInvoiceViaApi(array $overrides = [], ?Customer $customer = null): Invoice
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', array_merge([
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'MYR',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Issue 902 line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 6,
                'discount_amount' => 0,
            ]],
        ], $overrides));

        $response->assertCreated($response->getContent());

        return Invoice::findOrFail($response->json('id'));
    }

    private function createLegalEntity(): int
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 902 Group',
            'code' => 'I902-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Issue 902 Entity',
            'code' => 'I902-E-'.Str::upper(Str::random(5)),
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
        $ar = ChartOfAccount::factory()->create(['code' => '1100-I902', 'name' => 'AR', 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => '4000-I902', 'name' => 'Revenue', 'type' => 'revenue', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-I902', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);
        $cash = ChartOfAccount::factory()->create(['code' => '1000-I902', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);

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
 * LHDN boundary double for issue #667: throws on demand (as a network
 * failure would) so no real e-invoicing endpoint is ever called.
 */
class Issue667ThrowingLhdnProvider implements SubmissionProviderContract
{
    public bool $fail = true;

    /** @var array<int, array> */
    public array $submitCalls = [];

    public function submit(array $payload, array $credentials): array
    {
        $this->submitCalls[] = $payload;

        if ($this->fail) {
            // A transport failure: the provider throws instead of answering.
            throw new \RuntimeException('cURL error 28: connection to the e-invoicing gateway timed out');
        }

        return [
            'success' => true,
            'provider_reference' => 'SUB-I667-1',
            'external_document_uuid' => 'uuid-i667-1',
            'external_long_id' => 'long-id-i667-1',
            'validation_link' => 'https://portal.test/validate/i667-1',
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
            'details' => [],
            'message' => 'Valid',
        ];
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
        return ['fields' => [], 'description' => 'Test provider for issue #667'];
    }
}
