<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenant = null;

try {
    $tenant = Tenant::create([
        'id' => (string) Str::uuid(),
        'name' => 'CI Provision Smoke',
        'email' => 'ci-provision+'.Str::lower(Str::random(8)).'@example.test',
        'plan' => 'trial',
    ]);

    $schema = $tenant->database()->getName();
    $schemaExists = ! empty(DB::select(
        'SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?',
        [$schema]
    ));

    if (! $schemaExists) {
        throw new RuntimeException("Tenant schema was not created for {$schema}.");
    }

    $usersTableExists = $tenant->run(fn (): bool => Schema::hasTable('users'));

    if (! $usersTableExists) {
        throw new RuntimeException("Tenant migrations did not create the users table for {$schema}.");
    }

    fwrite(STDOUT, "Tenant provisioning smoke check passed for schema {$schema}.".PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
} finally {
    if ($tenant instanceof Tenant) {
        try {
            tenancy()->end();
        } catch (Throwable) {
            // Ignore context cleanup failures during teardown.
        }

        try {
            $tenant->database()->manager()->deleteDatabase($tenant);
        } catch (Throwable) {
            // Ignore schema cleanup failures; the assertion result already reflects the smoke check.
        }

        try {
            $tenant->delete();
        } catch (Throwable) {
            // Ignore tenant row cleanup failures during teardown.
        }
    }
}
