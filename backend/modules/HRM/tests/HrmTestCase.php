<?php

namespace Modules\HRM\Tests;

use App\Http\Middleware\SetTenantFromUserPreference;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

/**
 * HRM-specific base test case.
 *
 * The default RefreshDatabase runs `migrate:fresh` against ALL 93+ migrations
 * in database/migrations/tenant/, which includes migrations with cross-table FK
 * ordering bugs (customer_loyalty → customers, product_bundles → product_variants,
 * etc.) that break in the test environment.
 *
 * Fix: override refreshTestDatabase() to run migrate:fresh against ONLY two
 * targeted paths:
 *   1. modules/HRM/tests/database/migrations/ — minimal base schema
 *      (users, Sanctum, Spatie permissions, departments, locations)
 *   2. modules/HRM/database/migrations/tenant/ — all HRM tables
 *
 * This gives HRM tests a clean, isolated environment with zero dependency on
 * the rest of the codebase's migration state.
 */
abstract class HrmTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Bypass middleware that queries central DB tables (user_company_access,
     * tenants) which are NOT part of our minimal HRM test schema.
     * Sanctum auth middleware continues to run normally.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(SetTenantFromUserPreference::class);
    }

    /**
     * Override RefreshDatabase's migration step to use only HRM-relevant paths.
     *
     * NOTE: migrate:fresh --path does NOT wipe the DB before running. We must
     * explicitly call db:wipe first to drop all stale tables from previous runs,
     * then run only our targeted migration paths.
     */
    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            // Drop everything in the test database (removes stale tables from
            // previous test runs or from running php artisan migrate globally)
            $this->artisan('db:wipe', ['--force' => true]);

            // Run only the migrations HRM tests need — base schema + HRM tables
            $this->artisan('migrate', [
                '--path' => [
                    'modules/HRM/tests/database/migrations',
                    'modules/HRM/database/migrations/tenant',
                ],
            ]);

            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    /**
     * Wrap each test in a transaction that rolls back on teardown.
     * Copied from RefreshDatabase so we can call it directly here.
     */
    public function beginDatabaseTransaction(): void
    {
        $database = $this->app->make('db');

        $connections = $this->connectionsToTransact();

        $this->app->instance(
            'db.transactions',
            $transactionsManager = new DatabaseTransactionsManager($connections)
        );

        foreach ($connections as $name) {
            $connection = $database->connection($name);
            $connection->setTransactionManager($transactionsManager);

            $dispatcher = $connection->getEventDispatcher();
            $connection->unsetEventDispatcher();
            $connection->beginTransaction();
            $connection->setEventDispatcher($dispatcher);
        }

        $this->beforeApplicationDestroyed(function () use ($database) {
            foreach ($this->connectionsToTransact() as $name) {
                $connection = $database->connection($name);
                $dispatcher = $connection->getEventDispatcher();
                $connection->unsetEventDispatcher();

                if ($connection->getPdo() && ! $connection->getPdo()->inTransaction()) {
                    RefreshDatabaseState::$migrated = false;
                }

                $connection->rollBack();
                $connection->setEventDispatcher($dispatcher);
                $connection->disconnect();
            }
        });
    }

    protected function connectionsToTransact(): array
    {
        return property_exists($this, 'connectionsToTransact')
            ? $this->connectionsToTransact
            : [null];
    }
}
