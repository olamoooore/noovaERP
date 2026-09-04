<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Client request 2026-09-04: system accounts must be dynamically editable
 * (code + type included). Previously update() short-circuited system
 * accounts to a name/subtype/description/is_active-only branch and
 * silently dropped code/type/account_group/parent_id, so a client who
 * needed a different account type on a seeded system account had no path.
 */
class ChartOfAccountSystemEditTest extends TenantTestCase
{
    private string $tenantId;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'COA System Edit Tenant',
            'email' => 'coa-system-edit@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $this->adminUser = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->adminUser->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAccount(array $overrides = []): ChartOfAccount
    {
        return ChartOfAccount::create(array_merge([
            'code' => 'SYS-' . uniqid(),
            'name' => 'System Probe',
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
            'is_system' => true,
        ], $overrides));
    }

    private function postActiveEntry(ChartOfAccount $account): JournalEntry
    {
        $offset = $this->createAccount(['is_system' => false]);
        $entry = JournalEntry::create([
            'entry_number' => 'JE-' . uniqid('', true),
            'entry_date' => '2026-02-01',
            'description' => 'System edit probe entry',
            'status' => 'posted',
            'posted_at' => now(),
            'is_active_version' => true,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => 100,
            'credit' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $offset->id,
            'debit' => 0,
            'credit' => 100,
        ]);

        return $entry;
    }

    public function test_system_account_code_and_type_are_editable(): void
    {
        $account = $this->createAccount(['type' => 'asset']);

        $response = $this->actingAs($this->adminUser)->putJson(
            "/api/v1/accounting/chart-of-accounts/{$account->id}",
            [
                'code' => '9100',
                'name' => $account->name,
                'type' => 'expense',
                'account_group' => '9100',
                'is_active' => true,
            ]
        )->assertOk();

        $response->assertJsonPath('data.code', '9100');
        $response->assertJsonPath('data.type', 'expense');
        $response->assertJsonPath('data.account_group', '9100');
        $response->assertJsonPath('message', 'System account updated successfully');
    }

    public function test_duplicate_code_still_rejected_on_system_account(): void
    {
        $system = $this->createAccount();
        $this->createAccount(['code' => 'TAKEN-1', 'is_system' => false]);

        $this->actingAs($this->adminUser)->putJson(
            "/api/v1/accounting/chart-of-accounts/{$system->id}",
            [
                'code' => 'TAKEN-1',
                'name' => $system->name,
                'type' => $system->type,
                'is_active' => true,
            ]
        )->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_type_change_with_posted_entries_succeeds_with_warning(): void
    {
        $account = $this->createAccount(['type' => 'asset']);
        $this->postActiveEntry($account);

        $response = $this->actingAs($this->adminUser)->putJson(
            "/api/v1/accounting/chart-of-accounts/{$account->id}",
            [
                'code' => $account->code,
                'name' => $account->name,
                'type' => 'liability',
                'subtype' => 'current_asset', // stale subtype from old type
                'is_active' => true,
            ]
        )->assertOk();

        $response->assertJsonPath('data.type', 'liability');
        // Stale subtype for the new type is cleared...
        $response->assertJsonPath('data.subtype', null);
        // ...and the user is warned about both facts.
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $joined = implode(' ', $warnings);
        $this->assertStringContainsString('posted journal entries', $joined);
        $this->assertStringContainsString('Subtype was cleared', $joined);
    }

    public function test_system_account_cannot_be_deleted(): void
    {
        $account = $this->createAccount();

        $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/accounting/chart-of-accounts/{$account->id}")
            ->assertForbidden();
    }
}
