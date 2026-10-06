<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InvoiceEvidence;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #466 (DO14) — public document-token tenant resolution must be O(1).
 *
 * The public, unauthenticated acceptance routes (`/api/v1/do-accept/{token}`
 * and `/api/v1/invoice-accept/{token}`) used to discover the owning tenant by
 * iterating every tenant and querying each tenant schema until the token was
 * found — O(tenants) per request and a DoS lever. The fix resolves the tenant
 * from the central `public.document_token_lookup` registry in a single indexed
 * lookup, keeping the legacy scan only as a fallback for pre-existing tokens.
 *
 * These tests exercise the REAL route chain (router → middleware → controller)
 * with tenancy NOT pre-initialized, so the middleware's resolver actually runs.
 *
 * Harness note: this class deliberately runs WITHOUT the RefreshDatabase
 * transaction. The production middleware initializes tenancy, which swaps the
 * default DB connection; rows and DDL left uncommitted in the test transaction
 * would be invisible to the swapped tenant connection. Fixtures are therefore
 * committed and tearDown() removes every row/schema this class creates.
 */
class Issue466DocumentTokenLookupTest extends TenantTestCase
{
    private const SCHEMA_A = 'i466_tenant_a';

    private const SCHEMA_B = 'i466_tenant_b';

    private const TENANT_TABLES = [
        'journal_entries',
        'settings',
        'company_settings',
        'customers',
        'products',
        'delivery_orders',
        'delivery_order_lines',
        'invoices',
        'invoice_lines',
        'invoice_evidence',
        'esign_requests',
    ];

    /** @var string[] */
    private array $createdTenantIds = [];

    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated || ! $this->tenantSchemaExists()) {
            $this->runTenantMigrations();
            RefreshDatabaseState::$migrated = true;
        }

        $this->provisionSchemas();
    }

    protected function tearDown(): void
    {
        try {
            if (app()->bound(\Stancl\Tenancy\Tenancy::class)) {
                app(\Stancl\Tenancy\Tenancy::class)->end();
            }

            $db = DB::connection('pgsql');

            if ($this->createdTenantIds !== [] && $db->getSchemaBuilder()->hasTable('public.document_token_lookup')) {
                $db->table('public.document_token_lookup')->whereIn('tenant_id', $this->createdTenantIds)->delete();
            }

            if ($this->createdTenantIds !== []) {
                $db->table('public.tenants')->whereIn('id', $this->createdTenantIds)->delete();
            }

            $db->statement('SET search_path TO public');

            foreach ([self::SCHEMA_A, self::SCHEMA_B] as $schema) {
                $db->statement("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");
            }
        } finally {
            parent::tearDown();
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // DO portal resolution
    // ─────────────────────────────────────────────────────────────────────

    public function test_registered_delivery_order_token_resolves_via_central_lookup_without_tenant_scan(): void
    {
        $tenant = $this->makeTenant(self::SCHEMA_A, 'I466 DO Tenant');
        $token = (string) Str::uuid();

        $this->seedDeliveryOrder(self::SCHEMA_A, $token, expiresAt: now()->addDays(7));
        $this->registerToken($token, (string) $tenant->id, 'delivery_order', 1, now()->addDays(7));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->getJson("/api/v1/do-accept/{$token}");

        $response->assertOk();
        $this->assertSame('DO-I466-TEST', $response->json('delivery_order.delivery_order_number'));

        $this->assertNotEmpty(
            array_filter($queries, fn (string $sql) => str_contains($sql, 'document_token_lookup')),
            'The public route must resolve the tenant through the central token lookup.'
        );

        $this->assertNoAllTenantScan($queries);
    }

    public function test_unknown_delivery_order_token_is_rejected_with_404(): void
    {
        $this->makeTenant(self::SCHEMA_A, 'I466 Unknown Tenant');

        $this->getJson('/api/v1/do-accept/'.Str::uuid())
            ->assertStatus(404);
    }

    public function test_expired_delivery_order_token_is_still_rejected_with_410_on_upload(): void
    {
        $tenant = $this->makeTenant(self::SCHEMA_A, 'I466 Expired DO Tenant');
        $token = (string) Str::uuid();

        $this->seedDeliveryOrder(self::SCHEMA_A, $token, expiresAt: now()->subDay());
        $this->registerToken($token, (string) $tenant->id, 'delivery_order', 1, now()->subDay());

        $this->postJson("/api/v1/do-accept/{$token}/evidence", [
            'file' => UploadedFile::fake()->create('signed-do.pdf', 8, 'application/pdf'),
        ])->assertStatus(410)->assertJsonPath('code', 'EXPIRED');
    }

    /**
     * Backfill decision (#466): tokens issued before the registry existed are
     * still served by the legacy scan, and the hit self-registers so every
     * following request is the O(1) central lookup — no one-time command and
     * no broken links on deploy.
     */
    public function test_legacy_token_found_by_fallback_scan_self_registers_for_next_request(): void
    {
        $tenant = $this->makeTenant(self::SCHEMA_A, 'I466 Legacy Token Tenant');
        $token = (string) Str::uuid();

        // A DO token with NO central row: a link issued before the registry.
        $this->seedDeliveryOrder(self::SCHEMA_A, $token, expiresAt: now()->addDays(7));

        $this->getJson("/api/v1/do-accept/{$token}")->assertOk();

        $row = DB::connection('pgsql')->table('public.document_token_lookup')
            ->where('token_hash', hash('sha256', $token))
            ->where('document_type', 'delivery_order')
            ->first();

        $this->assertNotNull($row, 'A legacy token found by the fallback scan must self-register centrally.');
        $this->assertSame((string) $tenant->id, (string) $row->tenant_id);

        // A fresh request (the middleware leaves tenancy initialized for the
        // life of one request; production requests are separate processes).
        app(\Stancl\Tenancy\Tenancy::class)->end();
        DB::connection('pgsql')->statement('SET search_path TO public');

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->getJson("/api/v1/do-accept/{$token}")->assertOk();

        $this->assertNoAllTenantScan($queries);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invoice evidence resolution
    // ─────────────────────────────────────────────────────────────────────

    public function test_registered_invoice_evidence_token_resolves_via_central_lookup(): void
    {
        $tenant = $this->makeTenant(self::SCHEMA_A, 'I466 Invoice Tenant');
        $token = (string) Str::uuid();

        $invoiceId = $this->seedInvoice(self::SCHEMA_A, 'INV-I466-TEST');
        $this->seedInvoiceEvidence(self::SCHEMA_A, $invoiceId, $token, expiresAt: now()->addDays(7));
        $this->registerToken($token, (string) $tenant->id, 'invoice', $invoiceId, now()->addDays(7));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->getJson("/api/v1/invoice-accept/{$token}");

        $response->assertOk();
        $this->assertSame('INV-I466-TEST', $response->json('invoice.invoice_number'));

        $this->assertNotEmpty(
            array_filter($queries, fn (string $sql) => str_contains($sql, 'document_token_lookup')),
            'The invoice acceptance route must resolve the tenant through the central token lookup.'
        );

        $this->assertNoAllTenantScan($queries);
    }

    public function test_unknown_invoice_evidence_token_is_rejected_with_404(): void
    {
        $this->makeTenant(self::SCHEMA_A, 'I466 Unknown Invoice Tenant');

        $this->getJson('/api/v1/invoice-accept/'.Str::uuid())
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Issuance writes the registry
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_acceptance_link_issuance_registers_the_token_centrally(): void
    {
        $tenant = $this->makeTenant(self::SCHEMA_A, 'I466 Issuance Tenant');
        $invoiceId = $this->seedInvoice(self::SCHEMA_A, 'INV-I466-ISSUE');

        app(\Stancl\Tenancy\Tenancy::class)->initialize($tenant);

        $link = app(\App\Services\InvoiceEvidenceService::class)->createPortalAcceptanceLink(
            \App\Models\Invoice::query()->findOrFail($invoiceId),
            30,
        );

        $row = DB::connection('pgsql')->table('public.document_token_lookup')
            ->where('token_hash', hash('sha256', (string) $link['token']))
            ->first();

        $this->assertNotNull($row, 'Issuing an invoice acceptance link must register the token centrally.');
        $this->assertSame((string) $tenant->id, (string) $row->tenant_id);
        $this->assertSame('invoice', (string) $row->document_type);
        $this->assertSame($invoiceId, (int) $row->document_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rate limiting
    // ─────────────────────────────────────────────────────────────────────

    public function test_public_delivery_order_evidence_route_is_rate_limited(): void
    {
        $tenant = $this->makeTenant(self::SCHEMA_A, 'I466 Throttle Tenant');
        $token = (string) Str::uuid();

        $this->seedDeliveryOrder(self::SCHEMA_A, $token, expiresAt: now()->subDay());
        $this->registerToken($token, (string) $tenant->id, 'delivery_order', 1, now()->subDay());

        $statuses = [];

        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->postJson("/api/v1/do-accept/{$token}/evidence", [
                'file' => UploadedFile::fake()->create('signed-do.pdf', 1, 'application/pdf'),
            ])->getStatusCode();

            // Each public request is its own process in production; reset the
            // in-test tenancy so the real middleware path runs every time.
            app(\Stancl\Tenancy\Tenancy::class)->end();
            DB::connection('pgsql')->statement('SET search_path TO public');
        }

        $this->assertContains(429, $statuses, 'The public evidence route must throttle bursts: '.implode(',', $statuses));
        $this->assertSame(410, $statuses[0], 'The first request must reach the controller (expired link), not the throttle.');
        $this->assertSame(429, $statuses[10], 'The 11th request in the window must be throttled.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function provisionSchemas(): void
    {
        foreach ([self::SCHEMA_A, self::SCHEMA_B] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");
            DB::statement("CREATE SCHEMA \"{$schema}\"");

            foreach (self::TENANT_TABLES as $table) {
                DB::statement("CREATE TABLE \"{$schema}\".{$table} (LIKE public.{$table} INCLUDING ALL)");
            }
        }

        DB::statement('SET search_path TO public');
    }

    private function makeTenant(string $schema, string $name): Tenant
    {
        $tenant = Tenant::withoutEvents(function () use ($schema, $name) {
            $tenant = Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'email' => 'i466-'.Str::lower(Str::random(8)).'@example.test',
            ]);

            $tenant->schema_name = $schema;
            $tenant->save();

            return $tenant;
        });

        $this->createdTenantIds[] = (string) $tenant->id;

        return $tenant;
    }

    private function seedDeliveryOrder(string $schema, string $token, $expiresAt): void
    {
        DB::statement("SET search_path TO \"{$schema}\", public");

        DB::table('delivery_orders')->insert([
            'delivery_order_number' => 'DO-I466-TEST',
            'customer_id' => 1,
            'created_by' => 1,
            'status' => 'draft',
            'total' => 100,
            'currency_code' => 'USD',
            'acceptance_token' => $token,
            'acceptance_expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement('SET search_path TO public');
    }

    private function seedInvoice(string $schema, string $number): int
    {
        DB::statement("SET search_path TO \"{$schema}\", public");

        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => $number,
            'customer_id' => 1,
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'version_number' => 1,
            'is_active_version' => true,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement('SET search_path TO public');

        return (int) $invoiceId;
    }

    private function seedInvoiceEvidence(string $schema, int $invoiceId, string $token, $expiresAt): int
    {
        DB::statement("SET search_path TO \"{$schema}\", public");

        $evidenceId = DB::table('invoice_evidence')->insertGetId([
            'invoice_id' => $invoiceId,
            'type' => InvoiceEvidence::TYPE_CUSTOMER_ACCEPTANCE,
            'source' => InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE,
            'external_id' => $token,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement('SET search_path TO public');

        return (int) $evidenceId;
    }

    private function registerToken(string $token, string $tenantId, string $documentType, int $documentId, $expiresAt): void
    {
        DB::connection('pgsql')->table('public.document_token_lookup')->insert([
            'token_hash' => hash('sha256', $token),
            'tenant_id' => $tenantId,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The legacy O(tenants) loop starts with a bare `select * from tenants`
     * (Tenant::all()). A central lookup resolves the tenant by primary key
     * instead, so every tenants query in the request must carry a `where`.
     *
     * @param  string[]  $queries
     */
    private function assertNoAllTenantScan(array $queries): void
    {
        $tenantQueries = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'from "public"."tenants"'),
        ));

        $this->assertNotEmpty($tenantQueries, 'Expected the tenant model to be loaded by primary key.');

        $scans = array_values(array_filter(
            $tenantQueries,
            fn (string $sql) => ! str_contains($sql, ' where '),
        ));

        $this->assertSame(
            [],
            $scans,
            'Tenant resolution must not scan every tenant: '.implode(' | ', $scans),
        );
    }
}
