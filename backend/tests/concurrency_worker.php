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
    $schema = 'tenant_' . preg_replace('/[^a-z0-9]/', '', strtolower($config['tenant_id']));
    DB::statement("SET search_path TO {$schema}, public");
}

try {
    $result = ['status' => 'error', 'message' => 'unknown action'];

    if ($barrierFile) {
        // Signal ready
        file_put_contents($resultFile . '.ready', '1');

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
                $schema = 'tenant_' . preg_replace('/[^a-z0-9]/', '', strtolower($config['tenant_id']));
                $pdo->exec("SET search_path TO {$schema}, public");
            }

            // Expose backend PID so parent can verify lock contention via pg_locks
            $backendPid = (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
            file_put_contents($resultFile . '.pid', (string) $backendPid);

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
    }
} catch (\Throwable $e) {
    $result = ['status' => 'error', 'message' => $e->getMessage(), 'class' => get_class($e)];
}

file_put_contents($resultFile, json_encode($result));
exit(0);
