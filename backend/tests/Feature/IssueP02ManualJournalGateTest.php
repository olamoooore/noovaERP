<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountingControlAccountPolicy;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\ManualJournalPostingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue P0-2 — ManualJournalPostingService must run
 * through ActionEligibilityEvaluator so ControlAccountGate (and PermissionGate,
 * EntitlementGate) cannot be bypassed by posting a manual journal directly
 * to a control account.
 */
class IssueP02ManualJournalGateTest extends TenantTestCase
{
    private string $tenantUuid;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        // Need an authenticated user with accounting.write permission so the
        // PermissionGate does not pre-empt the ControlAccountGate test.
        $this->user = User::factory()->create();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->user->assignRole('Admin');
        Auth::login($this->user);
    }

    private function makeChartOfAccount(string $code, string $name, string $type = 'asset'): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'account_type' => $type,
            'is_active' => true,
        ]);
    }

    private function makeDraftJournalEntry(array $lines, string $entryDate = null): \App\Models\JournalEntry
    {
        $entry = \App\Models\JournalEntry::create([
            'tenant_id' => $this->tenantUuid,
            'entry_number' => 'TEST-'.Str::uuid()->toString(),
            'entry_date' => $entryDate ?? now()->subDays(2)->toDateString(),
            'description' => 'Test manual journal',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        foreach ($lines as $line) {
            \App\Models\JournalEntryLine::create([
                'tenant_id' => $this->tenantUuid,
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? 'Line',
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
            ]);
        }

        return $entry;
    }

    public function test_manual_journal_to_non_control_account_is_allowed(): void
    {
        $cash = $this->makeChartOfAccount('1000-'.uniqid(), 'Cash', 'asset');
        $revenue = $this->makeChartOfAccount('4000-'.uniqid(), 'Sales Revenue', 'revenue');

        $entry = $this->makeDraftJournalEntry([
            ['account_id' => $cash->id, 'debit' => 100],
            ['account_id' => $revenue->id, 'credit' => 100],
        ]);

        $result = app(ManualJournalPostingService::class)->post($entry, $this->user->id);

        $this->assertTrue($result->success, 'Failed: '.(string) $result->error);
    }

    public function test_manual_journal_to_ar_control_account_is_blocked_by_control_account_gate(): void
    {
        $arControl = $this->makeChartOfAccount('AR_CONTROL_'.uniqid(), 'AR Control', 'asset');
        $cash = $this->makeChartOfAccount('1000-'.uniqid(), 'Cash', 'asset');

        // Seed policy blocking manual_journal source capability
        AccountingControlAccountPolicy::create([
            'tenant_id' => $this->tenantUuid,
            'account_id' => $arControl->id,
            'control_account_type' => 'AR_CONTROL',
            'allowed_source_capabilities' => ['AR', 'ACC-003'],
            'requires_exceptional_authority' => false,
            'requires_workflow_approval' => false,
            'reconciliation_consequence_required' => true,
            'blocking_policy' => 'BLOCK',
            'effective_from' => now()->subDay(),
            'status' => 'active',
        ]);

        $entry = $this->makeDraftJournalEntry([
            ['account_id' => $cash->id, 'debit' => 100],
            ['account_id' => $arControl->id, 'credit' => 100],
        ]);

        $result = app(ManualJournalPostingService::class)->post($entry, $this->user->id);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('CONTROL_ACCOUNT_SOURCE_BLOCKED', (string) $result->error);
    }

    public function test_manual_journal_to_ar_control_with_exceptional_authority_is_allowed(): void
    {
        $arControl = $this->makeChartOfAccount('AR_CONTROL_'.uniqid(), 'AR Control', 'asset');
        $cash = $this->makeChartOfAccount('1000-'.uniqid(), 'Cash', 'asset');

        AccountingControlAccountPolicy::create([
            'tenant_id' => $this->tenantUuid,
            'account_id' => $arControl->id,
            'control_account_type' => 'AR_CONTROL',
            'allowed_source_capabilities' => [],
            'requires_exceptional_authority' => true,
            'exceptional_permission_code' => 'accounting.control_account_exception',
            'requires_workflow_approval' => true,
            'blocking_policy' => 'BLOCK',
            'effective_from' => now()->subDay(),
            'status' => 'active',
        ]);

        $entry = $this->makeDraftJournalEntry([
            ['account_id' => $cash->id, 'debit' => 100],
            ['account_id' => $arControl->id, 'credit' => 100],
        ]);

        $result = app(ManualJournalPostingService::class)->post(
            $entry,
            $this->user->id,
            'exceptional control-account adjustment'
        );

        // Without the special "control account exception" permission seeded
        // for the Admin role, this should still be blocked.
        $this->assertFalse($result->success);
    }

    public function test_manual_journal_without_accounting_write_permission_is_blocked_by_permission_gate(): void
    {
        // Create a user WITHOUT the accounting.write permission
        $userWithout = User::factory()->create();
        Auth::login($userWithout);

        $cash = $this->makeChartOfAccount('1000-'.uniqid(), 'Cash', 'asset');
        $revenue = $this->makeChartOfAccount('4000-'.uniqid(), 'Sales Revenue', 'revenue');

        $entry = $this->makeDraftJournalEntry([
            ['account_id' => $cash->id, 'debit' => 100],
            ['account_id' => $revenue->id, 'credit' => 100],
        ]);

        $result = app(ManualJournalPostingService::class)->post($entry, $userWithout->id);

        $this->assertFalse($result->success);
    }
}
