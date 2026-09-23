<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #165 — deleting a Legal Entity only checked branches/books, never the
 * ledger; `journal_entries.legal_entity_id` had no FK either, so a delete
 * silently orphaned posted GL history. Covers the app-level guard, the new
 * DB-level FK backstop, and the unchanged group-delete guard.
 */
class LegalEntityDeleteGuardTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'Entity Guard Tenant', 'email' => 'entity-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole('Admin');

        $this->user = $user;
    }

    private function group(): int
    {
        return (int) DB::table('entity_groups')->insertGetId([
            'name' => 'Guard Group',
            'code' => 'GRP-'.Str::upper(Str::random(6)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function entity(int $groupId): int
    {
        return (int) DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Guard Entity',
            'code' => 'ENT-'.Str::upper(Str::random(6)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_entity_with_journal_entries_cannot_be_deleted(): void
    {
        $entityId = $this->entity($this->group());

        JournalEntry::factory()->create([
            'legal_entity_id' => $entityId,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/accounting/legal-entities/'.$entityId);

        $response->assertStatus(422);
        $this->assertStringContainsString('journal entr', $response->json('message'));
        $this->assertDatabaseHas('legal_entities', ['id' => $entityId]);
    }

    public function test_entity_without_financial_data_can_be_deleted(): void
    {
        $entityId = $this->entity($this->group());

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/accounting/legal-entities/'.$entityId)
            ->assertOk();

        $this->assertDatabaseMissing('legal_entities', ['id' => $entityId]);
    }

    public function test_database_fk_blocks_orphaning_even_without_the_app_guard(): void
    {
        $entityId = $this->entity($this->group());

        JournalEntry::factory()->create([
            'legal_entity_id' => $entityId,
            'created_by' => $this->user->id,
        ]);

        // The FK (ON DELETE RESTRICT) is the backstop: a raw delete must fail.
        $this->expectException(QueryException::class);
        DB::table('legal_entities')->where('id', $entityId)->delete();
    }

    public function test_group_with_legal_entities_cannot_be_deleted(): void
    {
        $groupId = $this->group();
        $this->entity($groupId);

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/accounting/groups/'.$groupId)
            ->assertStatus(422);

        $this->assertDatabaseHas('entity_groups', ['id' => $groupId]);
    }
}
