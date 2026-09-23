<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Services\ContactMasterService;
use Tests\TenantTestCase;

/**
 * Issue #221 (regression from the #115 fix) — merge() only reassigned 6 of
 * CoreContact's owned relations, leaving group memberships, registrations,
 * tax profiles, bank accounts and all module profiles pointing at the
 * deactivated duplicate.
 */
class HR221ContactMergeRelationsTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady(['core_contacts', 'contact_group_members', 'contact_registrations']);
    }

    private function contact(string $name): CoreContact
    {
        return CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'display_name' => $name,
        ]);
    }

    private function attachRows(string $contactId): void
    {
        $groupId = (string) Str::uuid();
        DB::table('contact_groups')->insert([
            'id' => $groupId,
            'tenant_id' => $this->tenantId,
            'group_name' => 'HR221 Group',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contact_group_members')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $contactId,
            'group_id' => $groupId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contact_registrations')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $contactId,
            'registration_type' => 'company_no',
            'registration_value' => 'REG-'.Str::upper(Str::random(6)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contact_tax_profiles')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $contactId,
            'tax_profile_type' => 'standard',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contact_bank_accounts')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $contactId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('accounting_customer_profiles')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $contactId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('crm_contact_profiles')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $contactId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function countFor(string $table, string $contactId): int
    {
        return DB::table($table)->where('contact_id', $contactId)->count();
    }

    public function test_merge_reassigns_every_owned_relation_to_the_primary(): void
    {
        $primary = $this->contact('HR221 Primary');
        $merged = $this->contact('HR221 Duplicate');

        $this->attachRows($merged->id);

        $actor = User::factory()->create();

        $result = app(ContactMasterService::class)->merge($primary->id, $merged->id, $this->tenantId, (string) $actor->id);
        $this->assertTrue($result, 'Merge must succeed');

        foreach ([
            'contact_group_members',
            'contact_registrations',
            'contact_tax_profiles',
            'contact_bank_accounts',
            'accounting_customer_profiles',
            'crm_contact_profiles',
        ] as $table) {
            $this->assertSame(1, $this->countFor($table, $primary->id), "{$table} must be reassigned to the primary");
            $this->assertSame(0, $this->countFor($table, $merged->id), "{$table} must no longer point at the merged contact");
        }

        // Merge history records the full set of moved relation groups.
        $history = DB::table('contact_merge_history')
            ->where('primary_contact_id', $primary->id)
            ->where('merged_contact_id', $merged->id)
            ->first();

        $this->assertNotNull($history);
        $details = json_decode($history->merge_details, true) ?? [];
        foreach (['groups', 'registrations', 'tax_profiles', 'bank_accounts', 'accounting_customer_profile', 'crm_profile'] as $label) {
            $this->assertContains($label, $details, "merge_details must list {$label}");
        }
    }

    public function test_primarys_own_profile_is_not_replaced_by_the_duplicates(): void
    {
        $primary = $this->contact('HR221 Primary With Profile');
        $merged = $this->contact('HR221 Duplicate With Profile');

        // Both contacts have an accounting customer profile.
        $this->attachRows($merged->id);
        DB::table('accounting_customer_profiles')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $primary->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = User::factory()->create();
        app(ContactMasterService::class)->merge($primary->id, $merged->id, $this->tenantId, (string) $actor->id);

        // The primary keeps exactly its own profile; the duplicate's stays put
        // (moving it would create a second profile for the same contact).
        $this->assertSame(1, $this->countFor('accounting_customer_profiles', $primary->id));
        $this->assertSame(1, $this->countFor('accounting_customer_profiles', $merged->id));

        // HasMany relations still move regardless.
        $this->assertSame(1, $this->countFor('contact_bank_accounts', $primary->id));
        $this->assertSame(0, $this->countFor('contact_bank_accounts', $merged->id));
    }
}
