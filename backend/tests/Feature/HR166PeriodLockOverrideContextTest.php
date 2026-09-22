<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\LockDate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #166 — PostingEngine ran two independent period-lock gates with
 * different identities: the lock-date gate evaluated the resolved tenant actor,
 * while the fiscal-period gate evaluated ambient auth()->user() (a central user
 * row). A properly approved lock-date override therefore did not carry to the
 * fiscal gate.
 */
class HR166PeriodLockOverrideContextTest extends TenantTestCase
{
    private User $actor;

    private User $approver;

    private User $plainTenantUser;

    private User $ambientCentralUser;

    private string $postingDate = '2026-03-15';

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '44444444-3333-2222-1111-000000000000',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'chart_of_accounts', 'account_mappings', 'fiscal_periods',
            'lock_dates', 'lock_date_overrides', 'journal_entries',
        ]);

        foreach (['financial_controller', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->actor = User::create([
            'name' => 'HR166 Actor',
            'email' => 'hr166-actor-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->actor->assignRole(['financial_controller', 'admin']);

        $this->approver = User::create([
            'name' => 'HR166 Approver',
            'email' => 'hr166-approver-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->approver->assignRole(['financial_controller', 'admin']);

        $this->plainTenantUser = User::create([
            'name' => 'HR166 Plain',
            'email' => 'hr166-plain-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        // A central-only user (no tenant row): before the fix the fiscal gate
        // evaluated this user and refused an override the actor was entitled to.
        $centralId = DB::table('public.users')->insertGetId([
            'name' => 'HR166 Central',
            'email' => 'hr166-central-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ambientCentralUser = new User([
            'name' => 'HR166 Central',
            'email' => 'hr166-central-'.$centralId.'@test.local',
        ]);
        $this->ambientCentralUser->id = $centralId;
        $this->ambientCentralUser->exists = true;

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-HR166', 'name' => 'Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-HR166', 'name' => 'AR', 'type' => 'asset', 'is_active' => true]);
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
    }

    private function lockFiscalPeriod(string $date): void
    {
        $year = (int) substr($date, 0, 4);
        FiscalPeriod::createMonthlyPeriodsForYear($year);

        FiscalPeriod::where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->update(['status' => 'locked']);
    }

    private function postingRequest(int $userId, ?string $overrideReason = null, ?int $approvedBy = null): PostingRequest
    {
        return new PostingRequest(
            documentType: 'credit_note',
            documentId: 999,
            postingDate: $this->postingDate,
            description: 'HR166 override context',
            lines: [
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: null, debit: 100, credit: 0, description: 'DR'),
                new PostingRequestLine(accountMappingKey: 'accounts_receivable', accountId: null, debit: 0, credit: 100, description: 'CR'),
            ],
            userId: $userId,
            overrideReason: $overrideReason,
            approvedBy: $approvedBy,
        );
    }

    public function test_fiscal_gate_evaluates_the_resolved_actor_not_ambient_auth(): void
    {
        $this->lockFiscalPeriod($this->postingDate);
        Auth::login($this->ambientCentralUser);

        $result = app(PostingEngine::class)->post($this->postingRequest($this->actor->id));

        $this->assertTrue($result->success, $result->error ?? 'Posting must succeed via the actor override');

        $this->assertDatabaseHas('lock_date_overrides', [
            'user_id' => $this->actor->id,
            'transaction_type' => 'fiscal_period',
        ]);
    }

    public function test_approved_lock_date_override_carries_to_the_fiscal_gate(): void
    {
        $this->lockFiscalPeriod($this->postingDate);

        LockDate::create([
            'lock_type' => 'sales',
            'lock_date' => '2026-03-31',
            'is_active' => true,
            'set_by' => $this->approver->id,
        ]);

        Auth::login($this->ambientCentralUser);

        $result = app(PostingEngine::class)->post($this->postingRequest(
            $this->actor->id,
            overrideReason: 'Month-end correction approved by controller',
            approvedBy: $this->approver->id,
        ));

        $this->assertTrue($result->success, $result->error ?? 'The approved override must carry to the fiscal gate');

        // Both overrides are audited: the lock-date one by the actor with the
        // approver recorded, and the fiscal one under the approver's authority.
        $this->assertDatabaseHas('lock_date_overrides', [
            'user_id' => $this->actor->id,
            'transaction_type' => 'sales',
            'approved_by' => $this->approver->id,
        ]);
        $this->assertDatabaseHas('lock_date_overrides', [
            'user_id' => $this->approver->id,
            'transaction_type' => 'fiscal_period',
        ]);
    }

    public function test_locked_fiscal_period_still_blocks_without_any_override_authority(): void
    {
        $this->lockFiscalPeriod($this->postingDate);
        Auth::login($this->plainTenantUser);

        $result = app(PostingEngine::class)->post($this->postingRequest($this->plainTenantUser->id));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('locked', (string) $result->error);
    }
}
