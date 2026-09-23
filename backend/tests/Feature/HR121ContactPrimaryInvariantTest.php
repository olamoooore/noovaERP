<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Services\ContactMasterService;
use Tests\TenantTestCase;

/**
 * Issue #121 (hostile re-review 2026-09-23) — the "one primary per contact"
 * invariant was application-only: no partial unique index, no row lock, and
 * existing multi-primary rows were never backfilled.
 *
 * Verifies:
 *   1. The migration backfills multi-primary rows down to one per contact
 *      per flag (keeps the lowest id).
 *   2. The partial unique indexes exist and reject a second primary /
 *      default-billing / default-shipping row at the database layer.
 *   3. The service writers (addAddress/addMethod) still leave exactly one
 *      primary after repeated calls.
 */
class HR121ContactPrimaryInvariantTest extends TenantTestCase
{
    private string $tenantId;

    private string $contactId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->contactId = (string) Str::uuid();
        DB::table('core_contacts')->insert([
            'id' => $this->contactId,
            'tenant_id' => $this->tenantId,
            'display_name' => 'HR121 Invariant Contact',
            'contact_type' => 'individual',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runInvariantMigration(): void
    {
        $migration = require base_path('database/migrations/tenant/2026_09_23_000006_contact_primary_invariants.php');
        $migration->up();
    }

    private function insertAddress(string $id, array $flags = []): void
    {
        DB::table('contact_addresses')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'contact_id' => $this->contactId,
            'address_type' => 'office',
            'line_1' => 'Test Street',
            'is_primary' => false,
            'is_default_billing' => false,
            'is_default_shipping' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $flags));
    }

    public function test_partial_unique_indexes_exist(): void
    {
        foreach ([
            'contact_addresses_primary_unique',
            'contact_addresses_default_billing_unique',
            'contact_addresses_default_shipping_unique',
            'contact_methods_primary_unique',
        ] as $index) {
            $row = DB::selectOne(
                "SELECT 1 AS present FROM pg_indexes WHERE tablename IN ('contact_addresses','contact_methods') AND indexname = ?",
                [$index]
            );
            $this->assertNotNull($row, "Partial unique index {$index} must exist.");
        }
    }

    public function test_database_rejects_a_second_primary_address(): void
    {
        $this->insertAddress('00000000-0000-0000-0000-0000000000a1', ['is_primary' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->insertAddress('00000000-0000-0000-0000-0000000000a2', ['is_primary' => true]);
    }

    public function test_database_rejects_a_second_primary_method(): void
    {
        $make = fn (string $id) => DB::table('contact_methods')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'contact_id' => $this->contactId,
            'method_type' => 'email',
            'method_value' => 'primary@example.com',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $make('00000000-0000-0000-0000-0000000000b1');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $make('00000000-0000-0000-0000-0000000000b2');
    }

    public function test_migration_backfills_multiple_primaries_to_the_lowest_id(): void
    {
        // Drop the guards so pre-fix duplicates can be planted.
        DB::statement('DROP INDEX IF EXISTS contact_addresses_primary_unique');
        DB::statement('DROP INDEX IF EXISTS contact_addresses_default_billing_unique');
        DB::statement('DROP INDEX IF EXISTS contact_addresses_default_shipping_unique');

        $this->insertAddress('00000000-0000-0000-0000-0000000000c1', [
            'is_primary' => true, 'is_default_billing' => true, 'is_default_shipping' => true,
        ]);
        $this->insertAddress('00000000-0000-0000-0000-0000000000c2', [
            'is_primary' => true, 'is_default_billing' => true, 'is_default_shipping' => true,
        ]);
        $this->insertAddress('00000000-0000-0000-0000-0000000000c3', [
            'is_primary' => true, 'is_default_billing' => true, 'is_default_shipping' => true,
        ]);

        $this->runInvariantMigration();

        $primaries = DB::table('contact_addresses')
            ->where('contact_id', $this->contactId)
            ->where('is_primary', true)
            ->pluck('id')
            ->all();

        $this->assertSame(
            ['00000000-0000-0000-0000-0000000000c1'],
            $primaries,
            'Exactly the lowest-id row must keep each primary flag after the backfill.'
        );

        $this->assertSame(1, DB::table('contact_addresses')->where('contact_id', $this->contactId)->where('is_default_billing', true)->count());
        $this->assertSame(1, DB::table('contact_addresses')->where('contact_id', $this->contactId)->where('is_default_shipping', true)->count());
    }

    public function test_service_writers_leave_exactly_one_primary(): void
    {
        $service = app(ContactMasterService::class);

        $service->addAddress($this->contactId, [
            'address_type' => 'office', 'line_1' => 'First',
            'is_primary' => true, 'is_default_billing' => true, 'is_default_shipping' => true,
        ], $this->tenantId);

        // A second call with the same flags must not trip the unique index —
        // the guard clears the prior row before inserting.
        $service->addAddress($this->contactId, [
            'address_type' => 'office', 'line_1' => 'Second',
            'is_primary' => true, 'is_default_billing' => true, 'is_default_shipping' => true,
        ], $this->tenantId);

        $this->assertSame(1, DB::table('contact_addresses')->where('contact_id', $this->contactId)->where('is_primary', true)->count());
        $this->assertSame(1, DB::table('contact_addresses')->where('contact_id', $this->contactId)->where('is_default_billing', true)->count());
        $this->assertSame(1, DB::table('contact_addresses')->where('contact_id', $this->contactId)->where('is_default_shipping', true)->count());
    }
}
