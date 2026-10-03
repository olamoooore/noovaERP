<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Numbering\NumberingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesBlanketOrder;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TenantTestCase;

/**
 * Issue #899 (SALES-PLAT / PC5): three numbering implementations existed — the
 * unused platform NumberingService, DocumentNumberingService with a repair/
 * guess loop, and blanket orders using count()+1. The accepted fix is one
 * locked, per-tenant numbering service for every sales document, no guessing
 * fallbacks, and unique constraints on every sales document number.
 *
 * The parallel test below really is parallel: 20 worker processes are started
 * (each with its own Postgres connection), synchronised on a filesystem
 * barrier, and only then do all 20 create a blanket order at the same time.
 */
class Issue899SalesNumberingTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => (string) Str::uuid(),
        ]));
        $this->tenantId = $tenant->id;

        $this->bindTenantContext($tenant->id);

        $this->assertTenantSchemaReady([
            'sales_blanket_orders', 'sales_blanket_order_lines', 'sales_orders',
            'sales_order_lines', 'delivery_orders', 'customers', 'products',
            'numbering_sequences',
        ]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create(['price' => 10.00, 'cost' => 5.00]);
    }

    private function createBlanket(): int
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/sales/blanket-orders', [
            'customer_id' => $this->customer->id,
            'name' => 'Numbering agreement',
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'currency' => 'USD',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Bulk widget',
                'committed_qty' => 10,
                'unit_price' => 10.00,
            ]],
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /**
     * Business rule: a blanket-order number is never reissued. Deleting an
     * agreement must not make the next create reuse any live number (the old
     * count()+1 generator did exactly that).
     */
    public function test_a_blanket_number_is_never_reused_after_a_record_is_deleted(): void
    {
        $firstId = $this->createBlanket();
        $this->createBlanket();
        $this->createBlanket();

        $year = now()->format('Y');
        $issued = DB::table('sales_blanket_orders')
            ->orderBy('id')
            ->pluck('blanket_number')
            ->all();

        $this->assertSame(
            ["BO-{$year}-0001", "BO-{$year}-0002", "BO-{$year}-0003"],
            $issued,
            'consecutive creates must consume the locked sequence'
        );

        // Remove the first agreement. count()+1 now says "0003", which is taken.
        DB::table('sales_blanket_orders')->where('id', $firstId)->delete();

        $response = $this->actingAs($this->user)->postJson('/api/v1/sales/blanket-orders', [
            'customer_id' => $this->customer->id,
            'name' => 'Post-delete agreement',
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'currency' => 'USD',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Bulk widget',
                'committed_qty' => 10,
                'unit_price' => 10.00,
            ]],
        ]);

        $response->assertStatus(201);

        $newNumber = (string) $response->json('data.blanket_number');

        $this->assertNotContains($newNumber, $issued, 'a deleted agreement\'s number must not be reissued');
        $this->assertSame("BO-{$year}-0004", $newNumber, 'the sequence continues instead of colliding');

        $this->assertSame(
            DB::table('sales_blanket_orders')->count(),
            DB::table('sales_blanket_orders')->distinct()->count('blanket_number'),
            'all stored blanket numbers must be distinct'
        );
    }

    /**
     * Acceptance: SO, DO and blanket orders all allocate from the platform
     * NumberingService sequence (the old code used per-document settings
     * counters, so the platform service had no sequence at all).
     */
    public function test_sales_documents_allocate_from_the_platform_numbering_sequence(): void
    {
        $year = now()->format('Y');

        $this->assertSame("SO-{$year}-0001", SalesOrder::generateOrderNumber());
        $this->assertSame("DO-{$year}-0001", DeliveryOrder::generateNumber());
        $this->assertSame("BO-{$year}-0001", SalesBlanketOrder::generateNumber());

        $numbering = app(NumberingService::class);

        $this->assertSame("SO-{$year}-0002", $numbering->peek('sales_order', $this->tenantId));
        $this->assertSame("DO-{$year}-0002", $numbering->peek('delivery_order', $this->tenantId));
        $this->assertSame("BO-{$year}-0002", $numbering->peek('sales_blanket_order', $this->tenantId));

        $this->assertSame(1, $numbering->current('sales_order', $this->tenantId));
        $this->assertSame(1, $numbering->current('delivery_order', $this->tenantId));
        $this->assertSame(1, $numbering->current('sales_blanket_order', $this->tenantId));
    }

    /**
     * Acceptance: every sales document number column carries a unique index.
     */
    public function test_every_sales_document_number_column_has_a_unique_index(): void
    {
        $columns = [
            'invoices' => 'invoice_number',
            'quotations' => 'quotation_number',
            'sales_orders' => 'sales_order_number',
            'delivery_orders' => 'delivery_order_number',
            'sales_blanket_orders' => 'blanket_number',
            'customer_credit_notes' => 'credit_note_number',
        ];

        foreach ($columns as $table => $column) {
            $unique = collect(Schema::getIndexes($table))->first(
                fn (array $index): bool => $index['unique'] && $index['columns'] === [$column]
            );

            $this->assertNotNull(
                $unique,
                "{$table}.{$column} must have a unique index (last-resort guard against duplicate numbers)"
            );
        }
    }

    public function test_the_database_rejects_a_duplicate_blanket_number(): void
    {
        $this->createBlanket();

        $existing = (string) DB::table('sales_blanket_orders')->value('blanket_number');

        $this->expectException(QueryException::class);

        DB::table('sales_blanket_orders')->insert([
            'tenant_id' => $this->tenantId,
            'blanket_number' => $existing,
            'customer_id' => $this->customer->id,
            'name' => 'Duplicate attempt',
            'status' => 'active',
            'valid_from' => now()->toDateString(),
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The required concurrency proof: 20 genuinely parallel creates (one
     * process + one Postgres connection each) released simultaneously through
     * a filesystem barrier must yield 20 distinct numbers.
     */
    public function test_twenty_parallel_blanket_order_creates_get_distinct_numbers(): void
    {
        $runDir = storage_path('framework/testing/issue899-'.Str::uuid());
        $this->assertTrue(mkdir($runDir, 0777, true), 'worker barrier directory must be creatable');

        $config = config('database.connections.pgsql');
        $worker = base_path('tests/Fixtures/issue899_blanket_order_worker.php');

        $processes = [];

        try {
            for ($i = 0; $i < 20; $i++) {
                $process = new Process(
                    [PHP_BINARY, $worker],
                    base_path(),
                    [
                        'ACE899_INDEX' => (string) $i,
                        'ACE899_TENANT' => $this->tenantId,
                        'ACE899_RUN_DIR' => $runDir,
                        'ACE899_AUTOLOAD' => base_path('vendor/autoload.php'),
                        'ACE899_BOOTSTRAP' => base_path('bootstrap/app.php'),
                        'DB_CONNECTION' => 'pgsql',
                        'DB_DATABASE' => (string) $config['database'],
                        'DB_HOST' => (string) $config['host'],
                        'DB_PORT' => (string) $config['port'],
                        'DB_USERNAME' => (string) $config['username'],
                        'DB_PASSWORD' => (string) $config['password'],
                    ],
                    120,
                );
                $process->start();
                $processes[] = $process;
            }

            // Start barrier: wait until all 20 workers have booted, then release
            // them together so the numbering critical section really contends.
            $deadline = microtime(true) + 90;
            while (count(glob($runDir.'/*.ready')) < 20) {
                $this->assertLessThan(
                    $deadline,
                    microtime(true),
                    'not all 20 workers booted within 90 seconds'
                );
                usleep(10_000);
                clearstatcache();
            }

            file_put_contents($runDir.'/go', 'go');

            $numbers = [];
            $failures = [];

            foreach ($processes as $i => $process) {
                $process->wait();

                // Laravel's console exception handler renders failures to
                // stdout and still exits 0, so the output shape is the signal.
                $output = trim($process->getOutput());

                if ($process->getExitCode() !== 0 || ! preg_match('/^BO-\d{4}-\d{4}$/', $output)) {
                    $failures[] = "#{$i}: exit {$process->getExitCode()} ".$output.' '.trim($process->getErrorOutput());

                    continue;
                }

                $numbers[] = $output;
            }

            $this->assertSame(
                [],
                $failures,
                "every parallel worker must create its blanket order:\n".implode("\n", $failures)
            );

            $this->assertCount(20, $numbers);
            $this->assertCount(20, array_unique($numbers), 'parallel creates must never share a number: '.implode(', ', $numbers));

            $stored = DB::table('sales_blanket_orders')
                ->where('tenant_id', $this->tenantId)
                ->pluck('blanket_number');

            $this->assertCount(20, $stored);
            $this->assertCount(20, $stored->unique(), 'stored blanket numbers must all be distinct');
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            $this->cleanCommittedRows($this->tenantId);

            foreach (glob($runDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($runDir);
        }
    }

    /**
     * The worker processes commit on their own connections, so the test's
     * transaction cannot roll their rows back. Delete them with a separate
     * connection so they cannot leak into other tests.
     */
    private function cleanCommittedRows(string $tenantId): void
    {
        $config = config('database.connections.pgsql');

        $pdo = new \PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
        );

        try {
            $pdo->prepare('DELETE FROM sales_blanket_order_lines WHERE blanket_order_id IN (SELECT id FROM sales_blanket_orders WHERE tenant_id = ?)')
                ->execute([$tenantId]);
            $pdo->prepare('DELETE FROM sales_blanket_orders WHERE tenant_id = ?')->execute([$tenantId]);
            $pdo->prepare('DELETE FROM numbering_sequences WHERE tenant_id = ?')->execute([$tenantId]);
        } finally {
            $pdo = null;
        }
    }
}
