<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #97 (CRITICAL, ENTITY-1) — Cross-entity data isolation on journal
 * entry reads. An Accountant scoped only to Legal Entity A must NOT be able
 * to read a Legal Entity B journal entry by ID.
 */
class HR97CrossEntityJournalEntryIsolationTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        // Seed access-grant table rows so the user has visible accessible entities.
        // Note: legal_entities.id is auto-increment — we use whatever IDs the
        // migration created for this tenant schema. The seed uses ON CONFLICT
        // DO NOTHING to avoid interfering with other tests.
        // Seed legal_entities. legal_entities is a CENTRAL-schema table (not
        // tenant-scoped). legal_entities requires group_id; seed a group first.
        $groupId = (int) (DB::table('entity_groups')->insertGetId([
            'name' => 'G',
            'code' => 'G',
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        DB::table('legal_entities')->insertOrIgnore([
            ['id' => 1, 'group_id' => $groupId, 'name' => 'A', 'code' => 'A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'group_id' => $groupId, 'name' => 'B', 'code' => 'B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function createUserInEntity(int $entityId): User
    {
        $user = User::factory()->create();
        DB::table('user_entity_access')->insertOrIgnore([
            'user_id' => $user->id,
            'legal_entity_id' => $entityId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Auth::login($user);

        return $user;
    }

    private function createJournalEntry(int $entityId): JournalEntry
    {
        // Ensure a real user exists for created_by FK. Create one if not already
        // authenticated (Auth::id() may be null on the very first test method
        // before createUserInEntity runs).
        $createdBy = Auth::id();
        if (! $createdBy) {
            $user = User::factory()->create();
            Auth::login($user);
            $createdBy = $user->id;
        }

        return JournalEntry::create([
            'entry_number' => 'JE-HR97-'.uniqid(),
            'entry_date' => '2026-01-01',
            'description' => 'HR97 cross-entity isolation test',
            'status' => 'posted',
            'legal_entity_id' => $entityId,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── 1. Same entity — must succeed ──────────────────────────────

    public function test_user_in_entity_a_can_read_entity_a_entry(): void
    {
        $this->createUserInEntity(1);
        $entry = $this->createJournalEntry(1);

        $found = JournalEntry::accessibleBy([1])->find($entry->id);
        $this->assertNotNull($found, 'User in Entity A must read Entity A entry');
        $this->assertSame($entry->id, $found->id);
    }

    // ─── 2. Cross-entity — must NOT succeed ──────────────────────────

    public function test_user_in_entity_a_cannot_read_entity_b_entry(): void
    {
        $this->createUserInEntity(1);
        $entryB = $this->createJournalEntry(2);

        // Query scoped to entity A (the only one this user can access) must
        // NOT return the entity-B entry — even though the entry exists.
        $found = JournalEntry::accessibleBy([1])->find($entryB->id);
        $this->assertNull($found, 'User in Entity A must NOT read Entity B entry');
    }

    // ─── 3. Multi-entity user — must read both ──────────────────────

    public function test_user_with_multi_entity_access_can_read_both(): void
    {
        $user = $this->createUserInEntity(1);
        DB::table('user_entity_access')->insertOrIgnore([
            'user_id' => $user->id,
            'legal_entity_id' => 2,
            'role' => 'accountant',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entryA = $this->createJournalEntry(1);
        $entryB = $this->createJournalEntry(2);

        $accessibleIds = [1, 2];
        $this->assertNotNull(JournalEntry::accessibleBy($accessibleIds)->find($entryA->id));
        $this->assertNotNull(JournalEntry::accessibleBy($accessibleIds)->find($entryB->id));
    }

    // ─── 4. No accessible entities — fail-closed (no rows) ───────────

    public function test_scope_with_empty_accessible_ids_returns_no_rows(): void
    {
        $this->createUserInEntity(1); // user has access to entity 1
        $entry = $this->createJournalEntry(1);

        // Empty scope means no rows visible — fail-closed.
        $found = JournalEntry::accessibleBy([])->find($entry->id);
        $this->assertNull($found, 'Empty accessible_ids must return no rows (fail-closed)');
    }

    // ─── 5. Controller-level: show endpoint respects scope ───────────

    public function test_controller_show_returns_404_for_cross_entity_read(): void
    {
        // Create the entry first as a sysadmin-ish user.
        $admin = User::factory()->create();
        $entry = $this->createJournalEntry(2); // entry belongs to Entity B

        // Now log in as an Entity-A-only user.
        $user = User::factory()->create();
        DB::table('user_entity_access')->insertOrIgnore([
            'user_id' => $user->id,
            'legal_entity_id' => 1,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Auth::login($user);

        $response = $this->getJson("/api/v1/accounting/journal-entries/{$entry->id}");
        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            'Cross-entity read must return 403 or 404 — got '.$response->getStatusCode().' body='.$response->getContent()
        );
    }
}
