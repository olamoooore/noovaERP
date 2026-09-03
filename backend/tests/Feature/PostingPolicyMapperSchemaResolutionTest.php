<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\DomainPostingLine;
use App\Accounting\DTOs\DomainPostingRequest;
use App\Accounting\Services\PostingPolicyMapper;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * ACC-RM-001 foundation defect found while auditing the Sep 1 report §H-4.
 *
 * The posting profile catalog table is created by the TENANT migration
 * (`database/migrations/tenant/2026_08_29_000001_...`), i.e. it lives in
 * each tenant schema, resolved via search_path. PostingPolicyMapper used to
 * query it as `public.accounting_posting_profiles`, which does not exist in
 * a tenant schema: the query threw, the catch swallowed it, and every call
 * silently degraded to the built-in fallback — the DB catalog was dead code.
 *
 * These two tests are the discriminating pair:
 *   1. catalog row is RESOLVED when the active search_path contains the
 *      table (proves DB wiring, not just fallback).
 *   2. with search_path pointed at a schema WITHOUT the table, resolution
 *      must degrade to the built-in profile — NOT read a same-named table
 *      that exists in `public`. The old `public.`-qualified code resolved
 *      '9.9' here and would fail this test.
 */
class PostingPolicyMapperSchemaResolutionTest extends TenantTestCase
{
    private const CAPABILITY = 'ACC-003';

    private const PROFILE = 'AR_INVOICE_POST';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('mapper-schema-probe');
    }

    private function request(): DomainPostingRequest
    {
        return new DomainPostingRequest(
            postingRequestId: 'DPR-TEST-1',
            idempotencyKey: 'idem-'.uniqid(),
            tenantId: 'tenant-1',
            entityId: 'entity-1',
            accountingBookId: 'book-1',
            sourceCapability: self::CAPABILITY,
            sourceRecordType: 'invoice',
            sourceRecordId: '1',
            sourceRecordVersion: 1,
            postingProfileCode: self::PROFILE,
            transactionDate: '2026-09-01',
            postingDate: '2026-09-01',
            lines: [
                new DomainPostingLine(accountRole: 'accounts_receivable', amount: 100.0),
                new DomainPostingLine(accountRole: 'sales_revenue', amount: 100.0),
            ],
        );
    }

    private function seedPublicCatalog(string $version): void
    {
        DB::table('accounting_posting_profiles')->where(
            'posting_profile_code', self::PROFILE
        )->delete();

        DB::table('accounting_posting_profiles')->insert([
            'tenant_id' => null,
            'entity_id' => null,
            'accounting_book_id' => null,
            'source_capability' => self::CAPABILITY,
            'posting_profile_code' => self::PROFILE,
            'version' => $version,
            'name' => 'Probe profile',
            'mappings' => json_encode([
                'accounts_receivable' => ['direction' => 'debit'],
                'sales_revenue' => ['direction' => 'credit'],
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_mapper_resolves_active_profile_from_search_path_visible_table(): void
    {
        $this->seedPublicCatalog('9.9');

        $resolution = app(PostingPolicyMapper::class)->resolve($this->request());

        $this->assertTrue($resolution->success);
        // Distinctive DB-row version (built-ins are 1.0) proves the catalog
        // — not the fallback — answered this request.
        $this->assertSame('9.9', $resolution->postingRuleVersion);
    }

    public function test_mapper_does_not_bypass_tenancy_by_reading_public_schema_directly(): void
    {
        // The catalog table exists in `public` with a distinctive 9.9 row,
        // while the ACTIVE search_path is a tenant-like schema that has its
        // OWN (empty) copy of the table — the real production topology:
        // every tenant has the table via the tenant migration, and its
        // catalog is independent of other tenants'.
        $this->seedPublicCatalog('9.9');

        $schema = 'probe_tenant_'.bin2hex(random_bytes(3));

        DB::statement("CREATE SCHEMA {$schema}");
        DB::statement("CREATE TABLE {$schema}.accounting_posting_profiles (LIKE public.accounting_posting_profiles INCLUDING ALL)");
        try {
            DB::statement("SET search_path TO {$schema}, public");

            $resolution = app(PostingPolicyMapper::class)->resolve($this->request());

            // Correct (unqualified) lookup: probe schema's table is EMPTY →
            // graceful degrade to the built-in profile (version 1.0).
            // Old `public.`-qualified lookup: would FIND the seeded 9.9 row
            // across schemas — the tenancy-bypass defect under test.
            $this->assertTrue($resolution->success);
            $this->assertNotSame(
                '9.9',
                $resolution->postingRuleVersion,
                'Mapper must resolve the catalog from the active tenant schema, not read `public` cross-schema.'
            );
            // Built-in AR_INVOICE_POST profile answered instead (fallback).
            $this->assertStringStartsWith('1.', (string) $resolution->postingRuleVersion);
        } finally {
            DB::statement('SET search_path TO public');
            DB::statement("DROP SCHEMA {$schema} CASCADE");
        }
    }
}
