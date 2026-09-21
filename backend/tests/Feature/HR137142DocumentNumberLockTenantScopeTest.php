<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issues #137 / #142 (HIGH) — document-number generation advisory locks used
 * fixed, tenant-independent keys, so every tenant on the shared Postgres
 * database serialized against every other tenant.
 *
 * Verification: the key derivation is tenant-scoped, and a real second
 * connection proves different tenants' keys do not block each other while the
 * same tenant's key still serializes.
 */
class HR137142DocumentNumberLockTenantScopeTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['journal_entries', 'sales_orders', 'delivery_orders']);
    }

    public function test_all_three_lock_keys_are_tenant_scoped_and_stable(): void
    {
        $derivations = [
            'journal_entry' => fn (string $tenantId) => JournalEntry::numberGenerationLockKey($tenantId),
            'sales_order' => fn (string $tenantId) => SalesOrderService::salesOrderNumberLockKey($tenantId),
            'delivery_order' => fn (string $tenantId) => DeliveryOrderService::deliveryOrderNumberLockKey($tenantId),
        ];

        $allKeys = [];

        foreach ($derivations as $name => $derive) {
            $keyA = $derive('tenant-a');
            $keyB = $derive('tenant-b');

            $this->assertNotSame($keyA, $keyB, "{$name}: different tenants must get different lock keys");
            $this->assertSame($keyA, $derive('tenant-a'), "{$name}: key must be stable for the same tenant");

            $allKeys[$name] = $keyA;
        }

        // Namespaces must not collide with each other either.
        $this->assertCount(3, array_unique(array_values($allKeys)), 'Journal/SO/DO lock keys must be distinct namespaces');
    }

    public function test_tenant_keys_do_not_contend_across_connections(): void
    {
        $keyA = JournalEntry::numberGenerationLockKey('tenant-a');
        $keyB = JournalEntry::numberGenerationLockKey('tenant-b');

        $this->assertNotSame($keyA, $keyB);

        $config = config('database.connections.pgsql');
        $pdo = new \PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
        );

        try {
            $pdo->beginTransaction();
            $pdo->query('SELECT pg_advisory_xact_lock('.(int) $keyA.')');

            // A different tenant's key must still be acquirable (no cross-tenant blocking).
            $otherTenant = DB::selectOne('SELECT pg_try_advisory_xact_lock(?) AS acquired', [$keyB]);
            $this->assertTrue((bool) $otherTenant->acquired, 'Another tenant must not block on this tenant\'s lock');

            // The same tenant's key must be blocked while held (serialization preserved).
            $sameTenant = DB::selectOne('SELECT pg_try_advisory_xact_lock(?) AS acquired', [$keyA]);
            $this->assertFalse((bool) $sameTenant->acquired, 'The same tenant\'s number generation must serialize');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function test_journal_entry_numbers_still_generate_within_a_tenant(): void
    {
        $number = JournalEntry::generateEntryNumber();

        $this->assertMatchesRegularExpression('/^JE-\d{4}-\d{4}$/', $number);
    }
}
