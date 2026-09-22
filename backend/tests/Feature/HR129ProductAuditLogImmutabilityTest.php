<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #129 — ProductAuditLog claimed immutability but had no guard. Both
 * layers are now enforced: the Eloquent boot guard (runtime exceptions) and
 * Postgres triggers (raw SQL blocked) from migration 2026_09_22_000018.
 */
class HR129ProductAuditLogImmutabilityTest extends TenantTestCase
{
    private string $tenantId;

    private int $productId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->userId = \App\Models\User::factory()->create()->id;
        $this->actingAs(\App\Models\User::find($this->userId));

        $this->productId = \App\Models\Product::factory()->create()->id;
    }

    private function createAuditLog(): ProductAuditLog
    {
        return ProductAuditLog::create([
            'product_id' => $this->productId,
            'user_id' => $this->userId,
            'action' => 'create',
            'field_name' => 'name',
            'old_value' => null,
            'new_value' => 'Created for immutability test',
            'created_at' => now(),
        ]);
    }

    public function test_eloquent_update_throws(): void
    {
        $log = $this->createAuditLog();

        $this->expectException(\RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_eloquent_delete_throws(): void
    {
        $log = $this->createAuditLog();

        $this->expectException(\RuntimeException::class);
        $log->delete();
    }

    public function test_create_is_allowed(): void
    {
        $log = $this->createAuditLog();

        $this->assertDatabaseHas('product_audit_logs', ['id' => $log->id, 'action' => 'create']);
    }

    public function test_raw_sql_update_is_blocked_by_the_database_trigger(): void
    {
        $log = $this->createAuditLog();

        try {
            DB::table('product_audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
            $this->fail('Raw UPDATE must be refused by the append-only trigger');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_raw_sql_delete_is_blocked_by_the_database_trigger(): void
    {
        $log = $this->createAuditLog();

        try {
            DB::table('product_audit_logs')->where('id', $log->id)->delete();
            $this->fail('Raw DELETE must be refused by the append-only trigger');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }
}
