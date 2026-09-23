<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #92 — real Budget Management: CRUD, approval and a variance engine
 * reading actual posted GL activity (the dashboard no longer fabricates
 * budget as actual x 1.1).
 */
class Issue92BudgetManagementTest extends TenantTestCase
{
    private User $user;

    private ChartOfAccount $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '92929292-0202-1212-2222-323334353637',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['budgets', 'budget_lines', 'chart_of_accounts', 'journal_entries', 'journal_entry_lines']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expenseAccount = ChartOfAccount::factory()->create([
            'code' => '5100-92', 'name' => 'Office Expenses', 'type' => 'expense', 'is_active' => true,
        ]);
    }

    private function budgetPayload(float $amount = 500): array
    {
        return [
            'name' => 'FY2026 Operating Budget',
            'fiscal_year' => 2026,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'amount' => $amount],
            ],
        ];
    }

    private function postExpense(float $amount, string $date = '2026-03-15'): void
    {
        $offset = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);

        $entry = JournalEntry::create([
            'entry_number' => 'JE-BUD-'.uniqid(),
            'entry_date' => $date,
            'status' => 'posted',
            'is_active_version' => true,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create(['journal_entry_id' => $entry->id, 'account_id' => $this->expenseAccount->id, 'debit' => $amount, 'credit' => 0]);
        JournalEntryLine::create(['journal_entry_id' => $entry->id, 'account_id' => $offset->id, 'debit' => 0, 'credit' => $amount]);
    }

    public function test_budget_lifecycle_and_variance(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/accounting/budgets', $this->budgetPayload(500));
        $create->assertStatus(201);
        $budgetId = $create->json('data.id');

        // Actual spend inside the budget period, plus one outside it.
        $this->postExpense(400, '2026-03-15');
        $this->postExpense(9999, '2025-12-31');

        $variance = $this->actingAs($this->user)->getJson("/api/v1/accounting/budgets/{$budgetId}/variance");
        $variance->assertOk();

        $line = $variance->json('data.lines.0');
        $this->assertSame(500.0, (float) $line['budget_amount']);
        $this->assertSame(400.0, (float) $line['actual_amount'], 'Only posted activity inside the period counts.');
        $this->assertSame(100.0, (float) $line['variance']);
        $this->assertSame(20.0, (float) $line['variance_pct']);
        $this->assertSame(100.0, (float) $variance->json('data.totals.variance'));

        // Approval transition.
        $this->actingAs($this->user)->postJson("/api/v1/accounting/budgets/{$budgetId}/approve")->assertOk();
        $this->assertSame('approved', $this->actingAs($this->user)->getJson("/api/v1/accounting/budgets/{$budgetId}")->json('data.status'));

        // Approved budgets are locked for edit/delete.
        $this->actingAs($this->user)->putJson("/api/v1/accounting/budgets/{$budgetId}", ['name' => 'Renamed'])->assertStatus(422);
        $this->actingAs($this->user)->deleteJson("/api/v1/accounting/budgets/{$budgetId}")->assertStatus(422);
    }

    public function test_budget_without_lines_cannot_be_approved(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/accounting/budgets', $this->budgetPayload());
        $budgetId = $create->json('data.id');

        // Remove the lines directly to exercise the guard.
        DB::table('budget_lines')->where('budget_id', $budgetId)->delete();

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/budgets/{$budgetId}/approve")
            ->assertStatus(422);
    }

    public function test_budget_validation_rejects_unknown_accounts_and_bad_dates(): void
    {
        $payload = $this->budgetPayload();
        $payload['lines'][0]['account_id'] = 999999;

        $this->actingAs($this->user)->postJson('/api/v1/accounting/budgets', $payload)->assertStatus(422);

        $payload = $this->budgetPayload();
        $payload['period_end'] = '2025-12-31';

        $this->actingAs($this->user)->postJson('/api/v1/accounting/budgets', $payload)->assertStatus(422);
    }

    public function test_an_approved_budget_can_be_archived_instead_of_deleted(): void
    {
        // Hostile re-review (#92): delete says "Archive it instead" — that
        // endpoint now exists.
        $create = $this->actingAs($this->user)->postJson('/api/v1/accounting/budgets', $this->budgetPayload());
        $budgetId = $create->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/accounting/budgets/{$budgetId}/approve")->assertOk();

        $archive = $this->actingAs($this->user)->postJson("/api/v1/accounting/budgets/{$budgetId}/archive");
        $archive->assertOk();
        $this->assertSame('archived', $archive->json('data.status'));

        // Re-archiving is rejected.
        $this->actingAs($this->user)->postJson("/api/v1/accounting/budgets/{$budgetId}/archive")->assertStatus(422);
    }

    public function test_budgets_can_be_filtered_by_year_and_status(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/accounting/budgets', $this->budgetPayload())->assertStatus(201);

        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/budgets?fiscal_year=2026&status=draft');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));

        $empty = $this->actingAs($this->user)->getJson('/api/v1/accounting/budgets?fiscal_year=2025');
        $this->assertCount(0, $empty->json('data'));
    }
}
