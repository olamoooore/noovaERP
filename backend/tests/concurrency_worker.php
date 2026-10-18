<?php

/**
 * Standalone concurrency worker for fork-safe database contention tests.
 *
 * Boots Laravel in a fresh process (no shared file descriptors with parent),
 * calls the real service, writes outcome to a result file, and exits cleanly.
 *
 * Usage:
 *   php tests/concurrency_worker.php '{"action":"set_primary","customer_id":1,"contact_id":2,"tenant_id":"uuid","result_file":"/tmp/result.json","barrier_file":"/tmp/go","barrier_wait_ms":10000}'
 */
define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';

$config = json_decode($argv[1], true);
$action = $config['action'];
$resultFile = $config['result_file'];
$barrierFile = $config['barrier_file'] ?? null;
$barrierWaitMs = $config['barrier_wait_ms'] ?? 10000;

// Boot Laravel
$app = (require_once __DIR__.'/../bootstrap/app.php');
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Set tenant context if provided
if (! empty($config['tenant_id'])) {
    $tenant = new App\Models\Tenant(['id' => $config['tenant_id'], 'name' => 'Test Tenant']);
    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
    $tenancy = app(\Stancl\Tenancy\Tenancy::class);
    $tenancy->tenant = $tenant;
    $tenancy->initialized = true;

    // Set the tenant search path for PostgreSQL
    $schema = 'tenant_'.preg_replace('/[^a-z0-9]/', '', strtolower($config['tenant_id']));
    DB::statement("SET search_path TO {$schema}, public");
}

try {
    $result = ['status' => 'error', 'message' => 'unknown action'];

    if ($barrierFile) {
        // Signal ready
        file_put_contents($resultFile.'.ready', '1');

        // Wait for go signal
        $waitStart = microtime(true);
        while (! file_exists($barrierFile) && (microtime(true) - $waitStart) < ($barrierWaitMs / 1000)) {
            usleep(25000); // 25ms
        }
        if (! file_exists($barrierFile)) {
            $result = ['status' => 'timeout', 'message' => 'barrier timeout'];
            file_put_contents($resultFile, json_encode($result));
            exit(0);
        }
    }

    switch ($action) {
        case 'set_primary':
            // Signal that we've booted and are about to enter lockForUpdate()
            if (! empty($config['ready_file'])) {
                file_put_contents($config['ready_file'], '1');
            }
            $customer = \App\Models\Customer::findOrFail($config['customer_id']);
            $service = app(\App\Services\CustomerContactSyncService::class);
            $contact = $service->setPrimaryContact(
                $customer,
                $config['contact_id'],
                $config['actor_id'] ?? null
            );
            $result = [
                'status' => 'ok',
                'contact_id' => $contact->id,
                'is_primary' => $contact->is_primary,
            ];
            break;

        case 'set_primary_raw':
            // Raw PDO operation for contention testing
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                config('database.connections.pgsql.host'),
                config('database.connections.pgsql.port'),
                config('database.connections.pgsql.database')
            );
            $pdo = new PDO(
                $dsn,
                config('database.connections.pgsql.username'),
                config('database.connections.pgsql.password'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Set search_path for tenant schema
            if (! empty($config['tenant_id'])) {
                $schema = 'tenant_'.preg_replace('/[^a-z0-9]/', '', strtolower($config['tenant_id']));
                $pdo->exec("SET search_path TO {$schema}, public");
            }

            // Expose backend PID so parent can verify lock contention via pg_locks
            $backendPid = (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
            file_put_contents($resultFile.'.pid', (string) $backendPid);

            $pdo->exec("SET lock_timeout = '5000'");
            $pdo->beginTransaction();

            $customerId = $config['customer_id'];
            $contactId = $config['contact_id'];

            // Lock the customer row
            $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE');
            $stmt->execute([$customerId]);
            if (! $stmt->fetch()) {
                $pdo->rollBack();
                $result = ['status' => 'error', 'message' => 'CUSTOMER_NOT_FOUND'];
                break;
            }

            // Clear old primary and set new
            $pdo->exec("UPDATE customer_contacts SET is_primary = false WHERE customer_id = {$customerId} AND is_primary = true");
            $pdo->exec("UPDATE customer_contacts SET is_primary = true WHERE id = {$contactId}");

            $pdo->commit();
            $result = ['status' => 'ok', 'contact_id' => $contactId, 'is_primary' => true];
            break;

        case 'setup_cn_race':
            // #513 (CN4): committed fixtures for the two-process posting race.
            // This worker runs outside the test's RefreshDatabase transaction,
            // so everything created here is visible to the posting workers.
            // Explicit codes: the factories derive `code`/`invoice_number`
            // from COUNT()+1, which collides with the rows the parent test is
            // holding in its (invisible) transaction — the insert would block
            // on the unique-key transaction lock instead of racing.
            $token = strtoupper(substr(md5(uniqid('', true)), 0, 10));
            $user = \App\Models\User::factory()->create(['email' => "cn4r-{$token}@example.test"]);
            $customer = \App\Models\Customer::factory()->create(['code' => 'CN4R-'.$token]);

            // The posting workers run the real AccountingAccessService gate
            // (credit_note resolves to the material `accounting.write`), which
            // falls back to the CENTRAL user_company_access role. Give the
            // fixture actor its own tenant + admin access row, committed, so
            // both workers pass the gate exactly like an API request does.
            $raceTenantId = \Illuminate\Support\Str::uuid()->toString();
            DB::table('tenants')->insert([
                'id' => $raceTenantId,
                'name' => 'CN4 Race Tenant '.$token,
                'email' => "cn4r-{$token}@example.test",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_company_access')->insert([
                'user_id' => $user->id,
                'tenant_id' => $raceTenantId,
                'role' => 'admin',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $revenue = \App\Models\ChartOfAccount::factory()->create(['code' => '3000-CN4R', 'name' => 'Sales Revenue CN4', 'type' => 'revenue', 'is_active' => true]);
            $ar = \App\Models\ChartOfAccount::factory()->create(['code' => '1100-CN4R', 'name' => 'Accounts Receivable CN4', 'type' => 'asset', 'is_active' => true]);
            $tax = \App\Models\ChartOfAccount::factory()->create(['code' => '2200-CN4R', 'name' => 'Tax Payable CN4', 'type' => 'liability', 'is_active' => true]);

            \App\Models\AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
            \App\Models\AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
            \App\Models\AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);

            $invoice = \App\Models\Invoice::factory()->create([
                'invoice_number' => 'CN4R-'.$token.'-INV',
                'customer_id' => $customer->id,
                'status' => 'sent',
                'lifecycle_status' => 'posted',
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'balance' => 0,
                'created_by' => $user->id,
            ]);

            $line = \App\Models\InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'description' => 'Race line',
                'quantity' => 10,
                'unit_price' => 100,
                'tax_rate' => 0,
                'tax_inclusive' => false,
                'discount_amount' => 0,
            ]);

            $invoice->calculateTotals();
            $invoice = $invoice->fresh();

            // Both posting workers would otherwise race to auto-create the same
            // fiscal period and one would die on the unique violation — create
            // it once, committed, up front.
            $today = now();
            \App\Models\FiscalPeriod::firstOrCreate(
                [
                    'period_start' => $today->copy()->startOfMonth()->toDateString(),
                    'period_end' => $today->copy()->endOfMonth()->toDateString(),
                ],
                [
                    'name' => $today->format('F Y'),
                    'code' => $today->format('Y-m'),
                    'period_type' => 'monthly',
                    'fiscal_year' => $today->year,
                    'is_locked' => false,
                    'status' => 'open',
                    'created_by' => $user->id,
                ]
            );

            $cnIds = [];
            foreach ([7, 7] as $qty) {
                $cn = \App\Models\CustomerCreditNote::create([
                    'credit_note_number' => 'CN4R-'.substr(md5(uniqid('', true)), 0, 8),
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice->id,
                    'credit_note_date' => now()->toDateString(),
                    'status' => 'draft',
                    'subtotal' => $qty * 100,
                    'tax_amount' => 0,
                    'total_amount' => $qty * 100,
                    'created_by' => $user->id,
                ]);
                $cn->lines()->create([
                    'invoice_line_id' => $line->id,
                    'description' => 'Race line',
                    'quantity' => $qty,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                ]);
                $cnIds[] = $cn->id;
            }

            $result = [
                'status' => 'ok',
                'race_tenant_id' => $raceTenantId,
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
                'line_id' => $line->id,
                'cn_ids' => $cnIds,
                'account_ids' => [$revenue->id, $ar->id, $tax->id],
                'fiscal_period_created' => true,
            ];
            break;

        case 'setup_je_number_race':
            // Journal entry number race: committed fixtures for concurrent
            // POST /api/v1/accounting/journal-entries requests against one
            // journal. Committed so every request process can see them.
            $token = strtoupper(substr(md5(uniqid('', true)), 0, 10));
            $user = \App\Models\User::factory()->create(['email' => "jenr-{$token}@example.test"]);
            $raceTenantId = \Illuminate\Support\Str::uuid()->toString();
            DB::table('tenants')->insert([
                'id' => $raceTenantId,
                'name' => 'JE Number Race Tenant '.$token,
                'email' => "jenr-{$token}@example.test",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_company_access')->insert([
                'user_id' => $user->id,
                'tenant_id' => $raceTenantId,
                'role' => 'admin',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $expense = \App\Models\ChartOfAccount::factory()->create(['code' => '6000-JENR-'.$token, 'name' => 'Expense JENR', 'type' => 'expense', 'is_active' => true]);
            $payable = \App\Models\ChartOfAccount::factory()->create(['code' => '2000-JENR-'.$token, 'name' => 'Payable JENR', 'type' => 'liability', 'is_active' => true]);

            $journalId = DB::table('journals')->insertGetId([
                'code' => 'JR'.substr($token, 0, 8),
                'name' => 'Race Journal '.$token,
                'type' => 'General',
                'sequence_prefix' => 'R'.substr($token, 0, 6),
                'sequence_number' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $today = now();
            $period = \App\Models\FiscalPeriod::firstOrCreate(
                [
                    'period_start' => $today->copy()->startOfMonth()->toDateString(),
                    'period_end' => $today->copy()->endOfMonth()->toDateString(),
                ],
                [
                    'name' => $today->format('F Y'),
                    'code' => $today->format('Y-m'),
                    'period_type' => 'monthly',
                    'fiscal_year' => $today->year,
                    'is_locked' => false,
                    'status' => 'open',
                    'created_by' => $user->id,
                ]
            );

            $result = [
                'status' => 'ok',
                'race_tenant_id' => $raceTenantId,
                'user_id' => $user->id,
                'api_token' => $user->createToken('je-number-race')->plainTextToken,
                'journal_id' => $journalId,
                'account_ids' => [$expense->id, $payable->id],
                'fiscal_period_id' => $period->wasRecentlyCreated ? $period->id : null,
            ];
            break;

        case 'http_request':
            // Dispatch one real HTTP request through the application's HTTP
            // kernel (router -> middleware -> controller), authenticated with
            // a Sanctum bearer token, exactly as an API client would.
            $httpKernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $request = Illuminate\Http\Request::create(
                $config['uri'],
                $config['method'],
                [],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$config['token'],
                ],
                json_encode($config['payload'] ?? [])
            );
            $response = $httpKernel->handle($request);
            $httpKernel->terminate($request, $response);
            $result = [
                'status' => 'ok',
                'http_status' => $response->getStatusCode(),
                'body' => json_decode((string) $response->getContent(), true),
            ];
            break;

        case 'post_credit_note':
            $creditNote = \App\Models\CustomerCreditNote::findOrFail($config['credit_note_id']);
            $service = app(\App\Services\CustomerCreditNoteService::class);
            $posted = $service->postCreditNote($creditNote);
            $result = [
                'status' => 'ok',
                'cn_id' => $posted->id,
                'cn_status' => $posted->status,
                'journal_entry_id' => $posted->journal_entry_id,
            ];
            break;
    }
} catch (\Throwable $e) {
    $result = ['status' => 'error', 'message' => $e->getMessage(), 'class' => get_class($e)];
}

file_put_contents($resultFile, json_encode($result));
exit(0);
