<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #337 — the public customer-portal route must resolve its tenant from
 * the token through the central #466 registry, with tenancy NOT pre-initialized
 * (the real InitializeTenantForPublicAccess path), and fail closed for an
 * unknown token without scanning tenants.
 *
 * Harness mirrors Issue466DocumentTokenLookupTest: no RefreshDatabase
 * transaction (the middleware swaps the tenant connection), committed
 * fixtures in dedicated schemas, removed in tearDown().
 */
class Issue337CustomerPortalTenantResolutionTest extends TenantTestCase
{
    private const SCHEMA_A = 'i337_tenant_a';

    private const SCHEMA_B = 'i337_tenant_b';

    private const TENANT_TABLES = [
        'company_settings',
        'customers',
        'invoices',
        'quotations',
        'customer_portal_links',
    ];

    /** @var string[] */
    private array $createdTenantIds = [];

    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated || ! $this->tenantSchemaExists()) {
            $this->runTenantMigrations();
            RefreshDatabaseState::$migrated = true;
        }

        foreach ([self::SCHEMA_A, self::SCHEMA_B] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");
            DB::statement("CREATE SCHEMA \"{$schema}\"");

            foreach (self::TENANT_TABLES as $table) {
                DB::statement("CREATE TABLE \"{$schema}\".{$table} (LIKE public.{$table} INCLUDING ALL)");
            }
        }

        DB::statement('SET search_path TO public');
    }

    protected function tearDown(): void
    {
        try {
            if (app()->bound(\Stancl\Tenancy\Tenancy::class)) {
                app(\Stancl\Tenancy\Tenancy::class)->end();
            }

            $db = DB::connection('pgsql');
            $db->statement('SET search_path TO public');

            if ($this->createdTenantIds !== []) {
                $db->table('public.document_token_lookup')->whereIn('tenant_id', $this->createdTenantIds)->delete();
                $db->table('public.tenants')->whereIn('id', $this->createdTenantIds)->delete();
            }

            foreach ([self::SCHEMA_A, self::SCHEMA_B] as $schema) {
                $db->statement("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_portal_token_resolves_its_tenant_via_central_lookup_and_lists_only_that_tenants_customer(): void
    {
        $tenantA = $this->makeTenant(self::SCHEMA_A);
        $this->makeTenant(self::SCHEMA_B);

        $token = Str::random(64);

        // Same customer/invoice ids in both schemas: only tenant A's must show.
        $this->seedCustomerAndInvoice(self::SCHEMA_A, 'INV-I337-A');
        $this->seedCustomerAndInvoice(self::SCHEMA_B, 'INV-I337-B');
        $linkId = $this->seedLink(self::SCHEMA_A, $token, now()->addDays(7));
        $this->register($token, (string) $tenantA->id, $linkId, now()->addDays(7));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->getJson("/api/v1/customer-portal/{$token}");

        $response->assertOk();
        $this->assertSame(['INV-I337-A'], collect($response->json('invoices'))->pluck('invoice_number')->all());

        $this->assertNotEmpty(
            array_filter($queries, fn (string $sql) => str_contains($sql, 'document_token_lookup')),
            'The portal route must resolve the tenant through the central token lookup.'
        );
        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'from "public"."tenants"') && ! str_contains($sql, ' where '),
        )), 'Tenant resolution must not scan every tenant.');
    }

    public function test_unknown_portal_token_fails_closed_with_404_without_scanning_tenants(): void
    {
        $this->makeTenant(self::SCHEMA_A);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/v1/customer-portal/'.Str::random(64))
            ->assertNotFound()
            ->assertJsonPath('code', 'INVALID_TOKEN');

        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'from "public"."tenants"') && ! str_contains($sql, ' where '),
        )));
    }

    // ─────────────────────────────────────────────────────────────────────

    private function makeTenant(string $schema): Tenant
    {
        $tenant = Tenant::withoutEvents(function () use ($schema) {
            $tenant = Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'I337 '.$schema,
                'email' => 'i337-'.Str::lower(Str::random(8)).'@example.test',
            ]);
            $tenant->schema_name = $schema;
            $tenant->save();

            return $tenant;
        });

        $this->createdTenantIds[] = (string) $tenant->id;

        return $tenant;
    }

    private function seedCustomerAndInvoice(string $schema, string $number): void
    {
        DB::statement("SET search_path TO \"{$schema}\", public");

        DB::table('customers')->insert([
            'id' => 1,
            'name' => 'I337 Customer '.$schema,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoices')->insert([
            'invoice_number' => $number,
            'customer_id' => 1,
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'balance' => 100,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'version_number' => 1,
            'is_active_version' => true,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement('SET search_path TO public');
    }

    private function seedLink(string $schema, string $token, $expiresAt): int
    {
        DB::statement("SET search_path TO \"{$schema}\", public");

        $id = DB::table('customer_portal_links')->insertGetId([
            'customer_id' => 1,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement('SET search_path TO public');

        return (int) $id;
    }

    private function register(string $token, string $tenantId, int $linkId, $expiresAt): void
    {
        DB::connection('pgsql')->table('public.document_token_lookup')->insert([
            'token_hash' => hash('sha256', $token),
            'tenant_id' => $tenantId,
            'document_type' => 'customer_portal',
            'document_id' => $linkId,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
