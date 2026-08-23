<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Modules\PaymentGateway\Controllers\PaymentWebhookController;
use Modules\PaymentGateway\Events\PaymentSuccessful;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentService;
use Tests\TenantTestCase;

class TestPaymentWebhookController extends PaymentWebhookController
{
    public function __construct(private readonly ?Closure $processPaymentOverride = null) {}

    public function handleSuccess(array $webhookData, PaymentGateway $gateway): void
    {
        $this->handlePaymentSuccess($webhookData, $gateway);
    }

    public function runProcessPayment(PaymentTransaction $transaction): void
    {
        $this->processPayment($transaction);
    }

    protected function processPayment(PaymentTransaction $transaction)
    {
        if ($this->processPaymentOverride) {
            ($this->processPaymentOverride)($transaction);

            return;
        }

        parent::processPayment($transaction);
    }

    protected function updateWebOrderStatus($invoice, $transaction = null) {}
}

class TestPaymentService extends PaymentService
{
    public function __construct(private readonly array $status) {}

    protected function getGatewayStatus(PaymentGateway $gateway, string $transactionId): array
    {
        return $this->status;
    }
}

class PaymentLifecycleHardeningTest extends TenantTestCase
{
    use DatabaseMigrations;

protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            database_path('migrations/tenant/2024_01_01_000001_create_users_table.php'),
            database_path('migrations/tenant/2024_01_01_100001_create_chart_of_accounts_table.php'),
            database_path('migrations/tenant/2024_01_01_100002_create_journal_entries_table.php'),
            database_path('migrations/tenant/2025_12_15_150000_fix_journal_balance_trigger.php'),
            database_path('migrations/tenant/2025_12_23_110001_create_fiscal_periods_table.php'),
            database_path('migrations/tenant/2026_01_06_000001_create_journals_table.php'),
            database_path('migrations/tenant/2026_01_06_000003_create_lock_dates_tables.php'),
            database_path('migrations/tenant/2026_01_06_000004_create_currencies_and_exchange_rates_tables.php'),
            database_path('migrations/tenant/2026_01_06_000005_create_analytic_accounts_table.php'),
            database_path('migrations/tenant/2026_01_06_000010_add_analytic_dimensions_to_journal_entry_lines.php'),
            database_path('migrations/tenant/2026_03_25_000004_create_journal_entry_links_table.php'),
            database_path('migrations/tenant/2026_03_25_000002_create_account_mappings_table.php'),
            database_path('migrations/tenant/2026_03_25_000003_create_posting_audit_logs_table.php'),
            database_path('migrations/tenant/2026_03_29_000012_create_entity_model_tables.php'),
            database_path('migrations/tenant/2026_03_29_000002_create_account_roles_table.php'),
            database_path('migrations/tenant/2026_03_29_000003_create_entity_account_roles_table.php'),
            database_path('migrations/tenant/2026_03_29_000016_add_entity_columns_to_journal_entries.php'),
            database_path('migrations/tenant/2024_01_01_200001_create_products_table.php'),
            database_path('migrations/tenant/2024_01_01_200002_create_customers_table.php'),
            database_path('migrations/tenant/2024_01_01_200003_create_invoices_table.php'),
            database_path('migrations/tenant/2024_01_01_200004_create_payments_table.php'),
            database_path('migrations/tenant/2026_04_19_000003_create_settlement_audit_logs_table.php'),
            database_path('migrations/tenant/2026_04_02_200000_make_invoices_customer_id_nullable.php'),
            database_path('migrations/tenant/2025_12_29_000001_create_vendors_table.php'),
            database_path('migrations/tenant/2026_01_05_000001_create_bills_table.php'),
            database_path('migrations/tenant/2026_04_21_000001_add_correction_fields_to_documents.php'),
            database_path('migrations/tenant/2025_12_17_000001_create_settings_table.php'),
            database_path('migrations/tenant/2026_01_06_000006_create_company_settings_table.php'),
            database_path('migrations/tenant/2026_03_29_000001_add_missing_columns_to_company_settings_table.php'),
            base_path('modules/PaymentGateway/database/migrations/tenant/2025_01_01_000001_create_payment_gateways_table.php'),
            base_path('modules/PaymentGateway/database/migrations/tenant/2025_01_01_000002_create_payment_transactions_table.php'),
            base_path('modules/PaymentGateway/database/migrations/tenant/2025_02_03_000003_add_refund_columns_to_payment_transactions.php'),
        ] as $migrationPath) {
            Artisan::call('migrate', [
                '--path' => $migrationPath,
                '--realpath' => true,
                '--force' => true,
            ]);
        }

        $this->ensureCustomerSchemaCompatibility();
    }

    private function ensureCustomerSchemaCompatibility(): void
    {
        if (! Schema::hasColumn('customers', 'marketing_opt_in')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->boolean('marketing_opt_in')->default(false);
            });
        }
    }

    public function test_webhook_retry_with_same_webhook_id_succeeds_after_prior_failure(): void
    {
        Notification::fake();
        Event::fake([PaymentSuccessful::class]);

        [$gateway, $transaction, $invoice] = $this->createInvoiceTransactionFixture();

        $controller = $this->makeWebhookController(
            fn () => throw new \RuntimeException('Simulated downstream failure')
        );

        $webhookData = [
            'transaction_id' => $transaction->transaction_id,
            'webhook_id' => 'hitpay_retry_1',
            'payment_method' => 'card',
            'raw_data' => ['status' => 'completed'],
            'metadata' => ['source' => 'test'],
        ];

        try {
            $controller->handleSuccess($webhookData, $gateway);
            $this->fail('Expected simulated webhook failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated downstream failure', $e->getMessage());
        }

        $transaction->refresh();

        $this->assertSame('pending', $transaction->status);
        $this->assertNull($transaction->processed_at);
        $this->assertNull($transaction->webhook_id);
        $this->assertSame(1, $transaction->webhook_attempts);
        $this->assertSame('Simulated downstream failure', $transaction->error_message);
        $this->assertDatabaseMissing('payments', [
            'reference' => $transaction->transaction_id,
        ]);

        $retryController = $this->makeWebhookController();
        $retryController->handleSuccess($webhookData, $gateway);

        $transaction->refresh();
        $invoice->refresh();

        $this->assertSame('completed', $transaction->status);
        $this->assertNotNull($transaction->processed_at);
        $this->assertSame('hitpay_retry_1', $transaction->webhook_id);
        $this->assertSame(2, $transaction->webhook_attempts);
        $this->assertNull($transaction->error_message);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(50.0, (float) $invoice->paid_amount);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame(1, Payment::where('reference', $transaction->transaction_id)->count());
    }

    public function test_duplicate_invoice_webhook_does_not_double_apply_invoice_balance(): void
    {
        Notification::fake();
        Event::fake([PaymentSuccessful::class]);

        [$gateway, $transaction, $invoice] = $this->createInvoiceTransactionFixture();

        $webhookData = [
            'transaction_id' => $transaction->transaction_id,
            'webhook_id' => 'hitpay_dup_guard',
            'payment_method' => 'card',
            'raw_data' => ['status' => 'completed'],
            'metadata' => ['source' => 'test'],
        ];

        $controller = $this->makeWebhookController();
        $controller->handleSuccess($webhookData, $gateway);
        $controller->handleSuccess([
            ...$webhookData,
            'webhook_id' => 'hitpay_dup_guard_retry',
        ], $gateway);

        $transaction->refresh();
        $invoice->refresh();

        $this->assertSame('completed', $transaction->status);
        $this->assertNotNull($transaction->processed_at);
        $this->assertSame(1, $transaction->webhook_attempts);
        $this->assertSame(1, Payment::where('reference', $transaction->transaction_id)->count());
        $this->assertSame(50.0, (float) $invoice->paid_amount);
        $this->assertSame(0.0, (float) $invoice->balance);
    }

    public function test_webhook_failure_resets_transaction_back_to_pending_until_local_processing_finishes(): void
    {
        Notification::fake();
        Event::fake([PaymentSuccessful::class]);

        [$gateway, $transaction] = $this->createInvoiceTransactionFixture();

        $controller = $this->makeWebhookController(
            fn () => throw new \RuntimeException('Simulated downstream failure')
        );

        $webhookData = [
            'transaction_id' => $transaction->transaction_id,
            'webhook_id' => 'hitpay_processing_guard',
            'payment_method' => 'card',
            'raw_data' => ['status' => 'completed'],
            'metadata' => ['source' => 'test'],
        ];

        try {
            $controller->handleSuccess($webhookData, $gateway);
            $this->fail('Expected simulated webhook failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated downstream failure', $e->getMessage());
        }

        $transaction->refresh();

        $this->assertSame('pending', $transaction->status);
        $this->assertNull($transaction->processed_at);
        $this->assertSame('Simulated downstream failure', $transaction->error_message);
    }

    public function test_process_payment_sets_processed_at_when_local_completion_succeeds(): void
    {
        Notification::fake();

        [, $transaction, $invoice] = $this->createInvoiceTransactionFixture();

        $controller = $this->makeWebhookController();
        $controller->runProcessPayment($transaction);

        $transaction->refresh();
        $invoice->refresh();

        $this->assertSame('pending', $transaction->status);
        $this->assertNotNull($transaction->processed_at);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(50.0, (float) $invoice->paid_amount);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame(1, Payment::where('reference', $transaction->transaction_id)->count());
    }

    public function test_confirm_payment_keeps_gateway_complete_non_terminal_until_local_processing_finishes(): void
    {
        [, $transaction] = $this->createStandaloneTransactionFixture();

        $service = $this->makePaymentService([
            'success' => true,
            'status' => 'completed',
            'payment_method' => 'card',
            'amount' => 50,
            'currency' => 'USD',
        ]);

        $result = $service->confirmPayment($transaction->transaction_id);

        $transaction->refresh();

        $this->assertSame('completed', $result['status']);
        $this->assertSame('pending', $transaction->status);
        $this->assertNull($transaction->processed_at);
        $this->assertSame('card', $transaction->payment_method);
        $this->assertSame('completed', $transaction->gateway_response['status'] ?? null);
    }

    public function test_confirm_payment_preserves_already_completed_local_state(): void
    {
        [, $transaction] = $this->createStandaloneTransactionFixture();

        $processedAt = now()->subMinute()->startOfSecond();
        $transaction->update([
            'status' => 'completed',
            'processed_at' => $processedAt,
        ]);

        $service = $this->makePaymentService([
            'success' => true,
            'status' => 'completed',
            'payment_method' => 'card',
            'amount' => 50,
            'currency' => 'USD',
        ]);

        $service->confirmPayment($transaction->transaction_id);

        $transaction->refresh();

        $this->assertSame('completed', $transaction->status);
        $this->assertNotNull($transaction->processed_at);
        $this->assertSame($processedAt->toDateTimeString(), $transaction->processed_at->toDateTimeString());
        $this->assertSame('completed', $transaction->gateway_response['status'] ?? null);
    }

    private function makeWebhookController(?Closure $processPaymentOverride = null): TestPaymentWebhookController
    {
        return new TestPaymentWebhookController($processPaymentOverride);
    }

    private function makePaymentService(array $status): TestPaymentService
    {
        return new TestPaymentService($status);
    }

    private function createInvoiceTransactionFixture(): array
    {
        $user = User::factory()->create();

        $this->seedAccountingPostingFixtures($user->id);

        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Webhook Test Customer',
            'email' => 'webhook@example.com',
            'payment_terms' => 30,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-001',
            'customer_id' => $customerId,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'subtotal' => 50,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 50,
            'paid_amount' => 0,
            'balance' => 50,
            'created_by' => $user->id,
        ]);

        $gateway = $this->createGateway();

        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customerId,
            'transaction_id' => 'txn_'.bin2hex(random_bytes(6)),
            'amount' => 50,
            'currency' => 'USD',
            'status' => 'pending',
            'metadata' => ['type' => 'invoice'],
        ]);

        return [$gateway, $transaction, $invoice];
    }

    private function seedAccountingPostingFixtures(int $userId): void
    {
        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();
        $periodCode = now()->format('Y-m');

        if (! DB::table('fiscal_periods')->where('code', $periodCode)->exists()) {
            DB::table('fiscal_periods')->insert([
                'name' => now()->format('F Y'),
                'code' => $periodCode,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([
            'cash' => '1010',
            'bank' => '1020',
            'accounts_receivable' => '1100',
        ] as $mappingKey => $accountCode) {
            if (DB::table('account_mappings')->where('mapping_key', $mappingKey)->whereNull('document_type')->exists()) {
                continue;
            }

            $accountId = DB::table('chart_of_accounts')->where('code', $accountCode)->value('id');

            DB::table('account_mappings')->insert([
                'mapping_key' => $mappingKey,
                'account_id' => $accountId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createStandaloneTransactionFixture(): array
    {
        $gateway = $this->createGateway();

        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'txn_'.bin2hex(random_bytes(6)),
            'amount' => 50,
            'currency' => 'USD',
            'status' => 'pending',
            'metadata' => [],
        ]);

        return [$gateway, $transaction];
    }

    private function createGateway(): PaymentGateway
    {
        return PaymentGateway::create([
            'gateway_name' => 'hitpay',
            'api_key' => 'test-api-key',
            'api_secret' => 'test-api-secret',
            'is_active' => true,
            'is_test_mode' => true,
        ]);
    }
}
