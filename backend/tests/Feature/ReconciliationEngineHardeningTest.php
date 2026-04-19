<?php

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ReconciliationControlResult;
use App\Models\ReconciliationException;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Reconciliation\APGLReconciliationService;
use App\Services\Reconciliation\ARGLReconciliationService;
use App\Services\Reconciliation\InventoryGLReconciliationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationEngineHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:wipe', ['--force' => true]);
        Artisan::call('migrate:install');

        foreach ([
            database_path('migrations/tenant/2024_01_01_000001_create_users_table.php'),
            database_path('migrations/tenant/2024_01_01_100001_create_chart_of_accounts_table.php'),
            database_path('migrations/tenant/2026_03_25_000002_create_account_mappings_table.php'),
            database_path('migrations/tenant/2024_01_01_100002_create_journal_entries_table.php'),
            database_path('migrations/tenant/2025_12_17_000001_create_settings_table.php'),
            database_path('migrations/tenant/2025_12_17_000002_create_taxes_table.php'),
            database_path('migrations/tenant/2025_12_18_231016_create_warehouses_table.php'),
            database_path('migrations/tenant/2024_01_01_200001_create_products_table.php'),
            database_path('migrations/tenant/2024_01_01_200002_create_customers_table.php'),
            database_path('migrations/tenant/2024_01_01_200003_create_invoices_table.php'),
            database_path('migrations/tenant/2024_01_01_200004_create_payments_table.php'),
            database_path('migrations/tenant/2025_12_29_000001_create_vendors_table.php'),
            database_path('migrations/tenant/2026_04_15_000001_create_reconciliation_enhancements_tables.php'),
            database_path('migrations/tenant/2026_04_19_000002_add_reconciliation_control_result_id_to_reconciliation_exceptions.php'),
        ] as $migrationPath) {
            Artisan::call('migrate', [
                '--path' => $migrationPath,
                '--realpath' => true,
            ]);
        }

        // Create account mappings
        $arAccount = ChartOfAccount::where('code', '1100')->first();
        if ($arAccount) {
            AccountMapping::create([
                'mapping_key' => 'accounts_receivable',
                'account_id' => $arAccount->id,
                'is_active' => true,
            ]);
        }

        $inventoryAccount = ChartOfAccount::where('code', '1200')->first();
        if ($inventoryAccount) {
            AccountMapping::create([
                'mapping_key' => 'inventory',
                'account_id' => $inventoryAccount->id,
                'is_active' => true,
            ]);
        }
    }

    public function test_ar_gl_reconciliation_service_can_be_instantiated(): void
    {
        $service = app(ARGLReconciliationService::class);
        $this->assertNotNull($service);
        
        // Test the service has the expected methods
        $this->assertTrue(method_exists($service, 'reconcile'));
        $this->assertTrue(method_exists($service, 'getHistory'));
    }

    public function test_reconciliation_exception_can_be_resolved(): void
    {
        $user = User::create([
            'name' => 'Test User 3',
            'email' => 'test-recon3@test.local',
            'password' => bcrypt('password'),
        ]);

        $exception = ReconciliationException::create([
            'exception_type' => ReconciliationException::TYPE_UNMATCHED_TRANSACTION,
            'reference' => 'TEST-EXC-001',
            'description' => 'Test exception',
            'amount' => 50.00,
            'status' => ReconciliationException::STATUS_OPEN,
            'priority' => ReconciliationException::PRIORITY_MEDIUM,
            'aging_bucket' => ReconciliationException::BUCKET_CURRENT,
            'days_open' => 0,
        ]);

        $exception->resolve($user->id, 'Test resolution notes');

        $this->assertEquals(ReconciliationException::STATUS_RESOLVED, $exception->fresh()->status);
        $this->assertNotNull($exception->fresh()->resolved_at);
        $this->assertEquals($user->id, $exception->fresh()->resolved_by);
    }

    public function test_reconciliation_exception_update_aging(): void
    {
        // Test the calculateAgingBucket static method directly
        $this->assertEquals(ReconciliationException::BUCKET_CURRENT, ReconciliationException::calculateAgingBucket(0));
        $this->assertEquals(ReconciliationException::BUCKET_1_30, ReconciliationException::calculateAgingBucket(15));
        $this->assertEquals(ReconciliationException::BUCKET_31_60, ReconciliationException::calculateAgingBucket(35));
        $this->assertEquals(ReconciliationException::BUCKET_61_90, ReconciliationException::calculateAgingBucket(75));
        $this->assertEquals(ReconciliationException::BUCKET_90_PLUS, ReconciliationException::calculateAgingBucket(100));
    }

    public function test_inventory_gl_reconciliation_service_can_be_instantiated(): void
    {
        $service = app(InventoryGLReconciliationService::class);
        $this->assertNotNull($service);
        
        // Test the service has the expected methods
        $this->assertTrue(method_exists($service, 'reconcile'));
        $this->assertTrue(method_exists($service, 'getHistory'));
    }

    public function test_reconciliation_exception_links_to_control_result(): void
    {
        $user = User::create([
            'name' => 'Link Test User',
            'email' => 'link-test@test.local',
            'password' => bcrypt('password'),
        ]);

        // Create a control result
        $controlResult = ReconciliationControlResult::create([
            'control_name' => 'test_reconciliation',
            'as_of_date' => '2026-04-15',
            'subledger_balance' => 100.00,
            'gl_balance' => 0,
            'difference' => 100.00,
            'status' => 'failed',
            'discrepancies' => [],
            'executed_by' => $user->id,
        ]);

        // Create exception with FK
        $exception = ReconciliationException::create([
            'exception_type' => ReconciliationException::TYPE_UNMATCHED_TRANSACTION,
            'reference' => 'TEST-LINK-001',
            'description' => 'Test exception with link',
            'amount' => 100.00,
            'status' => ReconciliationException::STATUS_OPEN,
            'priority' => ReconciliationException::PRIORITY_MEDIUM,
            'aging_bucket' => ReconciliationException::BUCKET_CURRENT,
            'days_open' => 0,
            'reconciliation_control_result_id' => $controlResult->id,
        ]);

        $this->assertEquals($controlResult->id, $exception->fresh()->reconciliation_control_result_id);
    }

    public function test_ap_gl_reconciliation_service_can_be_instantiated(): void
    {
        $service = app(APGLReconciliationService::class);
        $this->assertNotNull($service);
        
        $this->assertTrue(method_exists($service, 'reconcile'));
        $this->assertTrue(method_exists($service, 'generateReport'));
    }
}