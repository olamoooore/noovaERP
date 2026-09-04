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
 * COA deletion guard (production issue 2026-09-04, tenant Acme account
 * 500001 "Testing"): a fully corrected/reversed account was permanently
 * undeletable because destroy() blocked on ANY journal_entry_lines row,
 * including lines belonging to superseded (is_active_version = false)
 * entries. The guard is now version-aware, and live config pointers
 * (journal templates, GL mappings, company defaults, …) are reported
 * explicitly instead of silently dangling.
 */
class ChartOfAccountDeletionGuardTest extends TenantTestCase
{
    private string $tenantId;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'COA Deletion Guard Tenant',
            'email' => 'coa-del-guard@example.com',
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

    private function createAccount(string $prefix = 'DEL'): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $prefix . '-' . uniqid(),
            'name' => 'Guard Probe Account',
            'type' => 'expense',
            'account_type' => 'expense',
            'is_active' => true,
            'is_system' => false,
        ]);
    }

    /** Balanced two-line entry touching $account. */
    private function postEntry(ChartOfAccount $account, bool $activeVersion): JournalEntry
    {
        $offset = $this->createAccount('OFS');

        $entry = JournalEntry::create([
            'entry_number' => 'JE-' . uniqid('', true),
            'entry_date' => '2026-01-05',
            'description' => 'Guard probe entry',
            'status' => 'posted',
            'posted_at' => now(),
            'is_active_version' => $activeVersion,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'description' => 'Probe debit',
            'debit' => 100,
            'credit' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $offset->id,
            'description' => 'Probe credit',
            'debit' => 0,
            'credit' => 100,
        ]);

        return $entry->refresh();
    }

    private function deleteUrl(int $id): string
    {
        return "/api/v1/accounting/chart-of-accounts/{$id}";
    }

    public function test_unused_account_can_be_deleted(): void
    {
        $account = $this->createAccount();

        $this->actingAs($this->adminUser)
            ->deleteJson($this->deleteUrl($account->id))
            ->assertOk()
            ->assertJsonFragment(['message' => 'Account deleted successfully']);

        $this->assertNull(ChartOfAccount::find($account->id));
    }

    public function test_system_account_cannot_be_deleted(): void
    {
        $account = $this->createAccount('SYS');
        $account->update(['is_system' => true]);

        $this->actingAs($this->adminUser)
            ->deleteJson($this->deleteUrl($account->id))
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'Cannot delete system accounts']);
    }

    public function test_account_with_active_version_entries_is_blocked(): void
    {
        $account = $this->createAccount();
        $this->postEntry($account, activeVersion: true);

        $this->actingAs($this->adminUser)
            ->deleteJson($this->deleteUrl($account->id))
            ->assertForbidden()
            ->assertJsonFragment([
                'message' => 'Cannot delete account with transactions. Correct or replace the active journal entries first, or deactivate the account instead.',
            ]);

        $this->assertNotNull(ChartOfAccount::find($account->id));
    }

    public function test_fully_corrected_account_with_inactive_history_only_is_deletable(): void
    {
        $account = $this->createAccount();

        // Original entry + its full reversal, both superseded by a
        // correction — exactly the state of production account 500001.
        $original = $this->postEntry($account, activeVersion: false);
        $reversal = $this->postEntry($account, activeVersion: false);
        $reversal->update(['description' => 'FULL REVERSAL: wrong data']);

        $this->assertEquals(2,
            JournalEntryLine::where('account_id', $account->id)->count()
        );

        $this->actingAs($this->adminUser)
            ->deleteJson($this->deleteUrl($account->id))
            ->assertOk();

        // Soft delete: gone from live queries, audit rows intact.
        $this->assertNull(ChartOfAccount::find($account->id));
        $this->assertNotNull(ChartOfAccount::withTrashed()->find($account->id));
        $this->assertDatabaseHas('journal_entry_lines', [
            'account_id' => $account->id,
            'journal_entry_id' => $original->id,
        ]);
    }

    public function test_live_config_pointer_reference_is_reported(): void
    {
        $account = $this->createAccount();

        DB::table('journals')->insert([
            'code' => 'TST-' . Str::random(6),
            'name' => 'Guard probe journal',
            'type' => 'manual',
            'default_debit_account_id' => $account->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson($this->deleteUrl($account->id))
            ->assertForbidden();

        $response->assertJsonPath('references.0', 'journal templates (1)');
        $this->assertStringContainsString('journal templates', $response->json('message'));
        $this->assertNotNull(ChartOfAccount::find($account->id));
    }
}
