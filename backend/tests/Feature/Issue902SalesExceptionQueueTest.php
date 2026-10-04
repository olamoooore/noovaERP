<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\SubmissionProviderContract;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Enums\UserRole;
use App\Models\Accounting\CountryConfiguration;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Platform\Exceptions\ExceptionQueue;
use App\Platform\Exceptions\ExceptionService;
use App\Platform\Exceptions\ExceptionType;
use App\Platform\Notifications\NotificationService;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceStatusSync;
use Modules\Sales\Models\DeliveryOrder;
use Tests\TenantTestCase;

/**
 * Issue #902 [SALES-PLAT] P2 (PC8): business-relevant Sales failures were only
 * written to the application log — the platform exception queue
 * (App\Platform\Exceptions\ExceptionService / ExceptionQueue) existed with zero
 * callers, so nobody could see, assign, resolve or retry them.
 *
 * Contract asserted here (each case is forced through the real HTTP route,
 * router → middleware → controller → service):
 *  - TAX_VARIANCE between booked and determined tax raises a typed exception
 *    for the invoice (accountant-owned, payload snapshot, no retry);
 *  - the FX rounding residual raised during posting is queued the same way;
 *  - a failed delivery-order approver notification raises a sales-owned
 *    exception with a working re-notify retry;
 *  - a failed e-invoice auto-submit raises an accountant-owned exception with
 *    a working re-submit retry;
 *  - a bulk ("mark paid" style) payment posting failure raises an
 *    accountant-owned exception with a working re-post retry.
 */
class Issue902SalesExceptionQueueTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    private int $legalEntityId;

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
            'name' => 'Issue 902 Exception Queue Tenant',
            'email' => 'i902-'.substr(md5($this->tenantId), 0, 8).'@example.com',
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

        $this->assertTenantSchemaReady(['invoices', 'payments', 'platform_exception_queue']);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  TAX_VARIANCE — posting succeeds, the drift is queued
    // ═════════════════════════════════════════════════════════════════════

    public function test_tax_variance_on_invoice_posting_raises_a_typed_queue_exception(): void
    {
        // The customer's entity has no active tax registration, so the engine
        // determines zero tax while the document booked 6% — the exact
        // booked-vs-determined divergence TAX_VARIANCE exists for. The GL
        // stays booked-first and the posting is not blocked.
        $invoice = $this->createInvoiceViaApi();

        $post = $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post");
        $post->assertOk($post->getContent());

        $exception = $this->firstQueued(ExceptionType::SALES_INVOICE_TAX_VARIANCE);

        $this->assertNotNull($exception, 'TAX_VARIANCE during posting must reach the platform exception queue.');
        $this->assertSame('sales', $exception->module);
        $this->assertSame('invoice', $exception->document_type);
        $this->assertSame((string) $invoice->id, (string) $exception->document_id);
        $this->assertSame($this->legalEntityId, (int) $exception->legal_entity_id);
        $this->assertSame('high', $exception->severity);
        $this->assertSame(UserRole::ACCOUNTANT->value, $exception->owner_role);
        $this->assertNull($exception->retry_handler, 'Tax variance is a review exception — there is nothing to auto-retry.');

        $payload = $exception->context ?? [];
        $this->assertEqualsWithDelta(6.0, (float) $payload['booked_tax'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $payload['determination_tax'], 0.001);
        $this->assertEqualsWithDelta(6.0, (float) $payload['variance'], 0.001);
        $this->assertStringContainsString($invoice->invoice_number, $exception->message);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  FX residual — posting absorbs a rounding drift, the drift is queued
    // ═════════════════════════════════════════════════════════════════════

    public function test_fx_rounding_residual_on_invoice_posting_raises_a_typed_queue_exception(): void
    {
        $this->allowUsdToMyr(3.33);

        $invoice = $this->createInvoiceViaApi(['currency_code' => 'USD']);

        // Same authoring bug: header tax is 0 while the document total still
        // carries the line tax. The posting spreads the unallocated cents at
        // revenue, so the revenue leg no longer matches subtotal × rate.
        DB::table('invoices')->where('id', $invoice->id)->update(['tax_amount' => 0]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $exception = $this->firstQueued(ExceptionType::SALES_INVOICE_FX_RESIDUAL);

        $this->assertNotNull($exception, 'The FX rounding residual during posting must reach the platform exception queue.');
        $this->assertSame('sales', $exception->module);
        $this->assertSame('invoice', $exception->document_type);
        $this->assertSame((string) $invoice->id, (string) $exception->document_id);
        $this->assertSame($this->legalEntityId, (int) $exception->legal_entity_id);
        $this->assertSame('medium', $exception->severity);
        $this->assertSame(UserRole::ACCOUNTANT->value, $exception->owner_role);
        $this->assertNull($exception->retry_handler);

        $payload = $exception->context ?? [];
        $this->assertArrayHasKey('expected_revenue_base', $payload);
        $this->assertArrayHasKey('posted_revenue_base', $payload);
        $this->assertGreaterThan(0.02, abs((float) $payload['residual']));
    }

    // ═════════════════════════════════════════════════════════════════════
    //  DO approver notification failure + retry
    // ═════════════════════════════════════════════════════════════════════

    public function test_delivery_order_approver_notification_failure_is_queued_and_retry_re_notifies(): void
    {
        $reviewer = User::factory()->create();
        Setting::setValue('sales.delivery_order.default_requires_approval', true);
        Setting::setValue('sales.delivery_order.reviewer_user_ids', [$reviewer->id]);
        Setting::setValue('sales.delivery_order.reviewer_roles', []);

        $failing = \Mockery::mock(NotificationService::class);
        $failing->shouldReceive('dispatch')->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->app->instance(NotificationService::class, $failing);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'requires_approval' => true,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => 'Issue 902 DO line',
                'quantity_delivered' => 1,
                'unit_price' => 100,
            ]],
        ]);

        $response->assertStatus(201, $response->getContent());
        $deliveryOrder = DeliveryOrder::findOrFail($response->json('data.id'));

        $exception = $this->firstQueued(ExceptionType::SALES_DO_APPROVAL_NOTIFICATION_FAILED);

        $this->assertNotNull($exception, 'A failed approver notification must reach the platform exception queue.');
        $this->assertSame('sales', $exception->module);
        $this->assertSame('delivery_order', $exception->document_type);
        $this->assertSame((string) $deliveryOrder->id, (string) $exception->document_id);
        $this->assertSame($this->legalEntityId, (int) $exception->legal_entity_id);
        $this->assertSame('high', $exception->severity);
        $this->assertSame(UserRole::SALES->value, $exception->owner_role);
        $this->assertSame('sales.delivery_order.notify_approvers', $exception->retry_handler);
        $this->assertStringContainsString('SMTP unavailable', $exception->message);
        $this->assertStringContainsString('SMTP unavailable', (string) $exception->context['error']);

        // Retry path: notifications work again → the queued exception resolves.
        $succeeding = \Mockery::mock(NotificationService::class);
        $succeeding->shouldReceive('dispatch')->once()->andReturnNull();
        $this->app->instance(NotificationService::class, $succeeding);

        $retried = app(ExceptionService::class)->retry($exception->id);

        $this->assertSame('resolved', $retried->status, 'A successful retry must resolve the queued exception.');
        $this->assertSame(1, (int) $retried->retry_count);
        $this->assertNotNull($retried->resolved_at);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  E-invoice auto-submit failure + retry
    // ═════════════════════════════════════════════════════════════════════

    public function test_einvoice_auto_submit_failure_is_queued_and_retry_resubmits(): void
    {
        $provider = new Issue902RetryableLhdnProvider;
        app(CountryPluginRegistry::class)->register('MY', [
            'validator' => MFRSValidationService::class,
            'mapper' => MyInvoiceMapper::class,
            'provider' => $provider,
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
                'supplier_name' => 'Issue 902 Sdn Bhd',
                'supplier_registration_number' => '202400000001',
            ],
            'settings' => ['auto_submit' => true],
        ]);

        $invoice = $this->createInvoiceViaApi();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $exception = $this->firstQueued(ExceptionType::SALES_EINVOICE_AUTO_SUBMIT_FAILED);

        $this->assertNotNull($exception, 'A failed e-invoice auto-submit must reach the platform exception queue.');
        $this->assertSame('sales', $exception->module);
        $this->assertSame('invoice', $exception->document_type);
        $this->assertSame((string) $invoice->id, (string) $exception->document_id);
        $this->assertSame($this->legalEntityId, (int) $exception->legal_entity_id);
        $this->assertSame('high', $exception->severity);
        $this->assertSame(UserRole::ACCOUNTANT->value, $exception->owner_role);
        $this->assertSame('compliance.einvoice.submit', $exception->retry_handler);
        $this->assertSame('MY', $exception->context['country_code']);
        $this->assertStringContainsString('LHDN gateway outage', (string) $exception->context['error']);
        $this->assertCount(1, $provider->submitCalls);

        // Retry path: the provider answers again → the document is submitted
        // and the queued exception resolves.
        $provider->fail = false;

        $retried = app(ExceptionService::class)->retry($exception->id);

        $this->assertSame('resolved', $retried->status, 'A successful e-invoice retry must resolve the queued exception. context: '.json_encode($retried->context));
        $this->assertCount(2, $provider->submitCalls, 'Retry must actually re-submit to the e-invoicing provider.');
        $this->assertSame(1, DB::table('acct_compliance_submissions')
            ->where('tenant_id', $this->tenantId)
            ->where('provider_reference', 'SUB-I902-1')
            ->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Bulk "mark paid" posting failure + retry
    // ═════════════════════════════════════════════════════════════════════

    public function test_bulk_payment_posting_failure_is_queued_and_retry_posts_the_payment(): void
    {
        $invoice = $this->createInvoiceViaApi();

        $create = $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'method' => 'cash',
        ]);
        $create->assertCreated($create->getContent());

        $payment = Payment::findOrFail($create->json('id'));
        $this->assertNull($payment->journal_entry_id, 'Fixture: the recorded payment starts as a draft.');

        // Legacy/imported data state: the payment carries a correction-only
        // method code. PaymentPostingService refuses to post it ("Unsupported
        // payment method"), which the bulk path currently swallows into a
        // skipped row + a log warning.
        DB::table('payments')->where('id', $payment->id)->update(['method' => 'refund']);

        $bulk = $this->actingAs($this->admin)->postJson('/api/v1/payments/bulk-post', [
            'ids' => [$payment->id],
        ]);
        $bulk->assertOk($bulk->getContent());
        $this->assertContains($payment->id, array_map('intval', $bulk->json('skipped.*.id') ?? []));

        $exception = $this->firstQueued(ExceptionType::SALES_PAYMENT_POSTING_FAILED);

        $this->assertNotNull($exception, 'A failed bulk payment posting must reach the platform exception queue.');
        $this->assertSame('sales', $exception->module);
        $this->assertSame('payment', $exception->document_type);
        $this->assertSame((string) $payment->id, (string) $exception->document_id);
        $this->assertSame($this->legalEntityId, (int) $exception->legal_entity_id);
        $this->assertSame('high', $exception->severity);
        $this->assertSame(UserRole::ACCOUNTANT->value, $exception->owner_role);
        $this->assertSame('sales.payment.post', $exception->retry_handler);
        $this->assertArrayHasKey('payment_number', $exception->context);

        // Retry path: the legacy method is repaired → the payment posts and
        // the queued exception resolves.
        DB::table('payments')->where('id', $payment->id)->update(['method' => 'cash']);

        $retried = app(ExceptionService::class)->retry($exception->id);

        $this->assertSame('resolved', $retried->status, 'A successful posting retry must resolve the queued exception.');
        $this->assertNotNull($payment->fresh()->journal_entry_id, 'Retry must actually post the payment.');
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Fixtures
    // ═════════════════════════════════════════════════════════════════════

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

    private function allowUsdToMyr(float $rate): void
    {
        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            [
                'name' => 'Malaysian Ringgit',
                'symbol' => 'RM',
                'decimal_places' => 2,
                'is_active' => true,
                'is_sellable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'MYR',
            'rate' => $rate,
            'effective_date' => now()->subDay()->toDateString(),
            'source' => 'manual',
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
 * LHDN boundary double for issue #902: fails on demand so the auto-submit
 * failure and its retry can both be exercised without HTTP.
 */
class Issue902RetryableLhdnProvider implements SubmissionProviderContract
{
    public bool $fail = true;

    /** @var array<int, array> */
    public array $submitCalls = [];

    public function submit(array $payload, array $credentials): array
    {
        $this->submitCalls[] = $payload;

        if ($this->fail) {
            return [
                'success' => false,
                'message' => 'Submission error: LHDN gateway outage',
                'error_code' => 'GATEWAY_TIMEOUT',
            ];
        }

        return [
            'success' => true,
            'provider_reference' => 'SUB-I902-1',
            'external_document_uuid' => 'uuid-i902-1',
            'external_long_id' => 'long-id-i902-1',
            'validation_link' => 'https://portal.test/validate/i902-1',
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
        return ['fields' => [], 'description' => 'Test provider for issue #902'];
    }
}
