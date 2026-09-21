<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #115 (BLOCKER) — Contact merge endpoint was non-functional because
 * ContactMergeHistory was declared as a second class inside the misnamed
 * ContactDuplicate.php (which matched neither class it contained), so PSR-4
 * autoloading could never resolve it and merge() threw a fatal Error on every
 * call.
 *
 * Fix: ContactMergeHistory and ContactDuplicateCandidate now live in their own
 * PSR-4-correct files; the phantom `ContactDuplicate` contract reference was
 * corrected to ContactDuplicateCandidate. This is a REAL HTTP test (the issue's
 * required fix #3 — the contract evidence pack previously misrepresented this
 * as tested).
 */
class HR115ContactMergeTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR115 Tenant', 'email' => 'hr115-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        User::factory()->create();
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('public.user_company_access')->where('user_id', $centralId)->delete();
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole('Admin');

        return $user;
    }

    private function makeContact(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('core_contacts')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'display_name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ─── The autoload fix itself ────────────────────────────────────

    public function test_contact_merge_history_class_autoloads(): void
    {
        $this->assertTrue(
            class_exists(\Modules\Contact\src\Models\ContactMergeHistory::class),
            'ContactMergeHistory must be PSR-4 autoloadable'
        );
        $this->assertTrue(
            class_exists(\Modules\Contact\src\Models\ContactDuplicateCandidate::class),
            'ContactDuplicateCandidate must be PSR-4 autoloadable'
        );
    }

    // ─── Real HTTP merge test ───────────────────────────────────────

    public function test_merge_endpoint_succeeds_and_records_history(): void
    {
        $admin = $this->actingAsAdmin();
        $primaryId = $this->makeContact('Primary Contact');
        $mergedId = $this->makeContact('Duplicate Contact');

        $response = $this->actingAs($admin)->postJson('/api/v1/contacts/merge', [
            'primary_contact_id' => $primaryId,
            'merged_contact_id' => $mergedId,
        ]);

        $this->assertSame(200, $response->getStatusCode(), 'Merge must succeed: '.$response->getContent());

        // The merged contact must be marked inactive + linked to the primary.
        $mergedRow = DB::table('core_contacts')->where('id', $mergedId)->first();
        $this->assertNotNull($mergedRow);
        $this->assertFalse((bool) $mergedRow->is_active, 'Merged contact must be inactive');
        $this->assertTrue((bool) $mergedRow->is_duplicate, 'Merged contact must be flagged duplicate');
        $this->assertSame($primaryId, $mergedRow->merged_into_id, 'Merged contact must point at the primary');

        // A contact_merge_history row must exist (the class that used to fail).
        $history = DB::table('contact_merge_history')
            ->where('primary_contact_id', $primaryId)
            ->where('merged_contact_id', $mergedId)
            ->first();
        $this->assertNotNull($history, 'contact_merge_history row must be recorded');
        $this->assertSame($this->tenantId, $history->tenant_id);
    }

    public function test_merge_missing_contact_returns_422(): void
    {
        $admin = $this->actingAsAdmin();
        $primaryId = $this->makeContact('Primary Only');

        $response = $this->actingAs($admin)->postJson('/api/v1/contacts/merge', [
            'primary_contact_id' => $primaryId,
            'merged_contact_id' => (string) Str::uuid(),
        ]);

        // Validation requires the merged id to exist.
        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_merge_reassigns_child_rows_to_primary(): void
    {
        $admin = $this->actingAsAdmin();
        $primaryId = $this->makeContact('Primary Reassign');
        $mergedId = $this->makeContact('Merged Reassign');

        // Attach an identifier to the merged contact (via the model so the
        // #118 encrypted cast applies).
        if (\Illuminate\Support\Facades\Schema::hasTable('contact_identifiers')) {
            \Modules\Contact\src\Models\ContactIdentifier::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'contact_id' => $mergedId,
                'identifier_type' => 'registration_number',
                'identifier_value' => 'REASSIGN-TEST-1',
            ]);
        } else {
            $this->markTestSkipped('contact_identifiers table not provisioned');
        }

        $response = $this->actingAs($admin)->postJson('/api/v1/contacts/merge', [
            'primary_contact_id' => $primaryId,
            'merged_contact_id' => $mergedId,
        ]);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        // The identifier must now belong to the primary contact.
        // Issue #118: identifier_value is encrypted at rest, so resolve it
        // through the model (which decrypts) rather than a raw column match.
        $moved = \Modules\Contact\src\Models\ContactIdentifier::where('contact_id', $primaryId)->get()
            ->first(fn ($identifier) => $identifier->identifier_value === 'REASSIGN-TEST-1');
        $this->assertNotNull($moved, 'The identifier must have been reassigned to the primary contact');
        $this->assertSame($primaryId, $moved->contact_id, 'Child rows must be reassigned to the primary contact');
    }
}
