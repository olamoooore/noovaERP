<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #567 follow-up (Decision on #919: unmasked reads are audited once per
 * request). The audit summary must identify the revealed record even when the
 * model is UUID-keyed — e.g. #444's ContactBankAccount. audit_logs.reference_id
 * is an unsigned big integer, so a UUID must not be squeezed into it (casting
 * "9f3a…" to int yields 9, i.e. a different, unrelated record id); the full
 * UUID is kept in a string-safe field instead.
 *
 * Runs through the real router → middleware → controller.
 */
class Issue567SensitiveFieldAuditUuidRecordTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityId;

    private User $accountant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => UserRole::ACCOUNTANT->spatieName(), 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue567 UUID Audit Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue567 Group',
            'code' => 'I567-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => '567-A-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole(UserRole::ACCOUNTANT->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $this->accountant->id,
            'tenant_id' => $this->tenantId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_entity_access')->insert([
            'user_id' => $this->accountant->id,
            'legal_entity_id' => $this->entityId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['is_active' => true, 'legal_entity_id' => $this->entityId]);
    }

    public function test_the_unmasked_read_audit_identifies_the_uuid_keyed_bank_account(): void
    {
        $created = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityId])
            ->postJson("/api/v1/customers/{$this->customer->id}/bank-accounts", [
                'bank_name' => 'Maybank',
                'account_name' => 'Issue567 Customer Sdn Bhd',
                'account_number' => '5140123456789',
                'currency' => 'MYR',
                'is_primary' => true,
            ]);
        $created->assertCreated();
        $id = (string) $created->json('id');
        $this->assertTrue(Str::isUuid($id), 'contact bank accounts are UUID-keyed');

        AuditLog::where('operation', 'sensitive_fields.read')->delete();

        // The holder of contacts.bank_accounts.view reads the full number.
        $list = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityId])
            ->getJson("/api/v1/customers/{$this->customer->id}/bank-accounts");
        $list->assertOk();
        $this->assertSame('5140123456789', $list->json('data.0.account_number'));

        $audits = AuditLog::where('operation', 'sensitive_fields.read')->get();
        $this->assertCount(1, $audits, 'one summary entry per request');
        $audit = $audits->first();

        $this->assertSame('contact_bank_account', $audit->reference_type);
        $this->assertSame($this->accountant->id, (int) $audit->actor_id);

        // The entry's subject is the full UUID, kept in a string-safe field…
        $this->assertSame($id, $audit->metadata['reference_key'] ?? null, 'the audit entry names the revealed record by its full UUID');
        // …and the integer reference_id never claims an unrelated numeric record.
        $this->assertSame(0, (int) $audit->reference_id, 'a UUID is not truncated into an integer record id');
        $this->assertSame([$id], $audit->metadata['records']['contact_bank_account']);
    }
}
