<?php

/**
 * Issue #899 concurrency worker.
 *
 * Boots the real application in its own process (one Postgres connection per
 * worker), announces readiness, waits for the parent's go-file and then
 * creates one blanket order through the real service. Prints the allocated
 * blanket number on stdout.
 *
 * Not a PHPUnit test file — it is executed by
 * Issue899SalesNumberingTest::test_twenty_parallel_blanket_order_creates_get_distinct_numbers().
 */

require getenv('ACE899_AUTOLOAD');

$app = require getenv('ACE899_BOOTSTRAP');

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$runDir = (string) getenv('ACE899_RUN_DIR');
$index = (string) getenv('ACE899_INDEX');
$tenantId = (string) getenv('ACE899_TENANT');

file_put_contents($runDir.'/'.$index.'.ready', 'ready');

$deadline = microtime(true) + 90;
$goFile = $runDir.'/go';

while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "barrier timeout\n");
        exit(2);
    }

    usleep(2_000);
    clearstatcache(true, $goFile);
}

$tenancy = $app->make(\Stancl\Tenancy\Tenancy::class);
$tenant = new \App\Models\Tenant(['id' => $tenantId]);
$tenancy->tenant = $tenant;
$tenancy->initialized = true;

$blanket = $app->make(\Modules\Sales\Services\SalesBlanketOrderService::class)->create([
    'tenant_id' => $tenantId,
    'customer_id' => 1,
    'name' => 'Parallel blanket order '.$index,
    'valid_from' => date('Y-m-d'),
    'currency' => 'USD',
]);

echo $blanket->blanket_number;
