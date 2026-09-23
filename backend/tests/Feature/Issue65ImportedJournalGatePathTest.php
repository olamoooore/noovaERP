<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountingImportService;
use App\Services\ManualJournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #65 — AccountingImportService created JournalEntry rows directly.
 * The documented contract is that the import path only ever produces DRAFT
 * bookkeeping rows and the sole draft→posted transition is the gate-checked
 * ManualJournalPostingService. This test proves that contract end-to-end:
 * imported entries cannot reach the ledger without passing the gate pipeline,
 * which fails closed for an unauthorised actor.
 */
class Issue65ImportedJournalGatePathTest extends TenantTestCase
{
    private User $admin;

    private User $viewer;

    private ChartOfAccount $debitAccount;

    private ChartOfAccount $creditAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '31313131-4141-5151-6161-717171717171',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'journal_entries', 'journal_entry_lines', 'chart_of_accounts',
            'posting_audit_logs', 'accounting_import_batches',
        ]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Admin', 'Viewer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenant->id,
            'name' => 'Issue65 tenant',
            'email' => $tenant->id.'@issue65.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('Viewer');

        foreach ([
            ['user' => $this->admin, 'role' => 'admin'],
            ['user' => $this->viewer, 'role' => 'viewer'],
        ] as $entry) {
            DB::table('user_company_access')->insert([
                'user_id' => $entry['user']->id,
                'tenant_id' => $tenant->id,
                'role' => $entry['role'],
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->debitAccount = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);
        $this->creditAccount = ChartOfAccount::factory()->create(['type' => 'revenue', 'is_active' => true]);
    }

    private function importJournalEntry(): JournalEntry
    {
        $service = app(AccountingImportService::class);

        $batch = $service->stageBatch(
            [[
                'entry_number' => 'JE-IMPORT-'.Str::upper(Str::random(6)),
                'entry_date' => now()->toDateString(),
                'description' => 'Imported draft entry',
                'lines' => [
                    ['account_id' => $this->debitAccount->id, 'debit' => 1000, 'credit' => 0],
                    ['account_id' => $this->creditAccount->id, 'debit' => 0, 'credit' => 1000],
                ],
            ]],
            'journal_entry',
            'journal.csv',
            hash('sha256', 'journal-'.Str::random(8)),
            $this->admin->id,
        );

        $service->commitBatch($batch->id, $this->admin->id);

        return JournalEntry::latest('id')->firstOrFail();
    }

    public function test_import_only_produces_drafts_and_the_gate_path_fails_closed_then_posts(): void
    {
        $entry = $this->importJournalEntry();

        // 1. Import produces a draft bookkeeping row — not a ledger write.
        $this->assertSame('draft', $entry->status);
        $this->assertSame(0, DB::table('journal_entry_links')->where('journal_entry_id', $entry->id)->count());
        $this->assertSame(0, DB::table('posting_audit_logs')->where('journal_entry_id', $entry->id)->count());

        // 2. An unauthorised actor cannot post the imported draft: the gate
        //    pipeline fails closed and the entry stays draft.
        $denied = app(ManualJournalPostingService::class)->post(
            $entry,
            $this->viewer->id,
            permissionActorId: $this->viewer->id,
        );

        $this->assertFalse($denied->success, 'Viewer must not be able to post an imported journal entry.');
        $this->assertSame('draft', $entry->fresh()->status);
        $this->assertSame(0, DB::table('posting_audit_logs')->where('journal_entry_id', $entry->id)->count());

        // 3. The authorised gate path posts it and records the audit row.
        $posted = app(ManualJournalPostingService::class)->post(
            $entry,
            $this->admin->id,
            permissionActorId: $this->admin->id,
        );

        $this->assertTrue($posted->success, $posted->error ?? 'Authorised post must succeed.');
        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertSame(1, DB::table('posting_audit_logs')
            ->where('journal_entry_id', $entry->id)
            ->where('action', 'post')
            ->count());
    }
}
