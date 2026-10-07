<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\CloseDependencyDefinition;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\StatutoryReturnFiling;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxPeriodClosingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\FinancialClose\Models\CloseCycle;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #278 (F4): tax period lock integrated with Financial Close.
 *
 * Every scenario drives the real HTTP route (router → middleware →
 * controller → PostingEngine). Business rules under test:
 *  - a filed/closed tax period rejects a taxed invoice dated inside it with
 *    422 + TAX_PERIOD_LOCKED and zero ledger effect; an untaxed manual
 *    journal still posts; the correction posts in the next open period as a
 *    credit note referencing the original invoice;
 *  - the lock is per legal entity AND per regime;
 *  - a close that races an in-flight post cannot commit before that post
 *    (no lost update);
 *  - Financial Close readiness carries the tax fact, and a close attempt with
 *    an unfiled tax period is blocked when the close dependency policy marks
 *    tax as BLOCKING.
 */
class Issue278TaxPeriodLockHttpTest extends TenantTestCase
{
    private const RACE_CONNECTION = 'pgsql_issue278_race';

    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private User $admin;

    private string $lockedMonth;

    private string $lockedDate;

    private string $openDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue278 Tax Lock Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue278 Group',
            'code' => 'I278-'.Str::upper(Str::random(5)),
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = $this->makeEntity($groupId, 'Entity A');
        $this->entityB = $this->makeEntity($groupId, 'Entity B');

        // Both entities are GST-registered in Singapore: invoices carry a
        // real tax determination (regime comes from the entity's registration).
        foreach ([$this->entityA, $this->entityB] as $entityId) {
            \App\Models\TaxRegistration::create([
                'legal_entity_id' => $entityId, 'jurisdiction' => 'SG', 'regime' => 'GST',
                'registration_number' => 'M9-'.Str::upper(Str::random(7)), 'effective_from' => '2020-01-01',
                'status' => 'active', 'filing_frequency' => 'quarterly',
            ]);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id, 'tenant_id' => $this->tenantId, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$this->entityA, $this->entityB] as $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $this->admin->id, 'legal_entity_id' => $entityId, 'role' => 'admin',
                'is_default' => $entityId === $this->entityA, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $suffix = Str::upper(Str::random(6));
        $accounts = [
            'accounts_receivable' => ChartOfAccount::factory()->create(['code' => "1200-{$suffix}", 'name' => 'AR', 'type' => 'asset', 'is_active' => true]),
            'sales_revenue' => ChartOfAccount::factory()->create(['code' => "4000-{$suffix}", 'name' => 'Sales', 'type' => 'revenue', 'is_active' => true]),
            'sales_tax_payable' => ChartOfAccount::factory()->create(['code' => "2200-{$suffix}", 'name' => 'GST Payable', 'type' => 'liability', 'is_active' => true]),
        ];
        $accounts['revenue'] = $accounts['sales_revenue'];
        foreach ($accounts as $key => $account) {
            AccountMapping::where('mapping_key', $key)->delete();
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id]);
        }

        // The locked month is LAST month; the next open period is this month.
        $this->lockedDate = now()->subMonthNoOverflow()->startOfMonth()->addDays(9)->toDateString();
        $this->lockedMonth = substr($this->lockedDate, 0, 7);
        $this->openDate = now()->toDateString();
    }

    protected function tearDown(): void
    {
        // The race scenario commits on a second connection; never leak it.
        if (array_key_exists(self::RACE_CONNECTION, config('database.connections'))) {
            DB::connection(self::RACE_CONNECTION)->table('tax_period_locks')
                ->where('period', $this->lockedMonth)->delete();
            DB::disconnect(self::RACE_CONNECTION);
        }

        parent::tearDown();
    }

    private function makeEntity(int $groupId, string $name): int
    {
        return DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => $name,
            'code' => '278-'.Str::upper(Str::random(6)),
            'country_code' => 'SG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTaxedInvoice(int $entityId, string $date): Invoice
    {
        $customer = Customer::factory()->create(['legal_entity_id' => $entityId, 'is_active' => true]);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customer->id,
                'invoice_date' => $date,
                'due_date' => $date,
                'lines' => [[
                    'line_type' => 'product',
                    'description' => 'Issue278 taxed widget',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 9,
                    'discount_amount' => 0,
                ]],
            ]);

        $response->assertCreated();

        return Invoice::findOrFail($response->json('id'));
    }

    private function postInvoice(Invoice $invoice, int $entityId): TestResponse
    {
        return $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson("/api/v1/invoices/{$invoice->id}/post");
    }

    private function closeTaxPeriod(array $body = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson("/api/v1/accounting/tax-periods/{$this->lockedMonth}/close", $body);
    }

    private function assertBlockedByTaxLock(TestResponse $response): void
    {
        $response->assertStatus(422);
        $this->assertSame(
            'TAX_PERIOD_LOCKED',
            $response->json('code'),
            'A post into a locked tax period must answer with the canonical reason code: '.$response->getContent()
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC1: taxed blocked (422 + reason code, zero ledger effect); untaxed
    //      manual journal succeeds; adjustment posts into the next period.
    // ─────────────────────────────────────────────────────────────────────

    public function test_closed_period_blocks_taxed_invoice_allows_untaxed_journal_and_adjustment_posts_next_period(): void
    {
        // An invoice posted while the month is still open (the original).
        $original = $this->createTaxedInvoice($this->entityA, $this->lockedDate);
        $this->postInvoice($original, $this->entityA)->assertOk();

        $this->closeTaxPeriod()->assertCreated();

        // A late taxed invoice dated inside the closed month is refused.
        $late = $this->createTaxedInvoice($this->entityA, $this->lockedDate);
        $entriesBefore = JournalEntry::count();
        $this->assertBlockedByTaxLock($this->postInvoice($late, $this->entityA));
        $this->assertSame($entriesBefore, JournalEntry::count(), 'A blocked post must have zero ledger effect.');
        $this->assertNull($late->fresh()->journal_entry_id);

        // An untaxed manual journal dated in the same month still posts.
        $cash = ChartOfAccount::factory()->create(['code' => 'C278-'.Str::upper(Str::random(5)), 'type' => 'asset', 'is_active' => true]);
        $expense = ChartOfAccount::factory()->create(['code' => 'E278-'.Str::upper(Str::random(5)), 'type' => 'expense', 'is_active' => true]);
        $draft = $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson('/api/v1/accounting/journal-entries', [
                'entry_date' => $this->lockedDate,
                'description' => 'Untaxed accrual in a tax-locked month',
                'lines' => [
                    ['account_id' => $expense->id, 'debit' => 20, 'credit' => 0],
                    ['account_id' => $cash->id, 'debit' => 0, 'credit' => 20],
                ],
            ]);
        $draft->assertCreated();
        $draftId = $draft->json('id');
        $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson("/api/v1/accounting/journal-entries/{$draftId}/post")
            ->assertOk();

        // The correction to the original: a credit note dated in the locked
        // month is refused; the same credit note dated in the next open
        // period posts and references the original invoice.
        $creditLine = [[
            'description' => 'Issue278 correction',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 9,
        ]];

        $lockedCn = $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson('/api/v1/accounting/customers/credit-notes', [
                'customer_id' => $original->customer_id,
                'invoice_id' => $original->id,
                'credit_note_date' => $this->lockedDate,
                'reason' => 'Late price correction',
                'lines' => $creditLine,
            ]);
        $lockedCn->assertCreated();
        $this->assertBlockedByTaxLock(
            $this->actingAs($this->admin)
                ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
                ->postJson('/api/v1/accounting/customers/credit-notes/'.$lockedCn->json('id').'/post')
        );

        $adjustment = $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson('/api/v1/accounting/customers/credit-notes', [
                'customer_id' => $original->customer_id,
                'invoice_id' => $original->id,
                'credit_note_date' => $this->openDate,
                'reason' => 'Late price correction',
                'lines' => $creditLine,
            ]);
        $adjustment->assertCreated();
        $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$adjustment->json('id').'/post')
            ->assertOk();

        $cn = DB::table('customer_credit_notes')->where('id', $adjustment->json('id'))->first();
        $this->assertSame($original->id, (int) $cn->invoice_id, 'The adjustment must reference the original document.');
        $this->assertNotNull($cn->journal_entry_id);
        $this->assertSame(
            substr($this->openDate, 0, 7),
            substr((string) JournalEntry::find($cn->journal_entry_id)->entry_date->toDateString(), 0, 7),
            'The adjustment must post into the next open period.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC2: per tenant, legal entity AND regime.
    // ─────────────────────────────────────────────────────────────────────

    public function test_lock_is_scoped_per_legal_entity_and_regime(): void
    {
        // Both entities are SG-registered, so their invoices are SG regime.

        // A MY (SST) lock on entity A does not block entity A's SG invoices.
        $this->closeTaxPeriod(['entity_id' => $this->entityA, 'regime' => 'MY'])->assertCreated();
        $sgUnderMyLock = $this->createTaxedInvoice($this->entityA, $this->lockedDate);
        $this->postInvoice($sgUnderMyLock, $this->entityA)->assertOk();

        // An SG lock on entity A blocks entity A's SG invoices ...
        $this->closeTaxPeriod(['entity_id' => $this->entityA, 'regime' => 'SG'])->assertCreated();
        $a = $this->createTaxedInvoice($this->entityA, $this->lockedDate);
        $this->assertBlockedByTaxLock($this->postInvoice($a, $this->entityA));

        // ... but not entity B's, in the same month and regime.
        $b = $this->createTaxedInvoice($this->entityB, $this->lockedDate);
        $this->postInvoice($b, $this->entityB)->assertOk();

        // Reopening only entity A's SG lock leaves its MY lock in place.
        $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson("/api/v1/accounting/tax-periods/{$this->lockedMonth}/reopen", [
                'reason' => 'Correct the SG return', 'entity_id' => $this->entityA, 'regime' => 'SG',
            ])->assertOk();
        $this->assertSame(
            ['MY'],
            DB::table('tax_period_locks')->where('period', $this->lockedMonth)
                ->where('legal_entity_id', $this->entityA)->pluck('regime')->all(),
        );
        $sgAgain = $this->createTaxedInvoice($this->entityA, $this->lockedDate);
        $this->postInvoice($sgAgain, $this->entityA)->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC4: concurrent post and lock — no lost update.
    // ─────────────────────────────────────────────────────────────────────

    public function test_close_racing_an_in_flight_post_cannot_commit_before_the_post(): void
    {
        config(['database.connections.'.self::RACE_CONNECTION => config('database.connections.pgsql')]);
        $race = DB::connection(self::RACE_CONNECTION);

        $invoice = $this->createTaxedInvoice($this->entityA, $this->lockedDate);

        $raceOutcome = null;
        DB::listen(function ($query) use (&$raceOutcome, $race) {
            if ($raceOutcome !== null || $query->connectionName === self::RACE_CONNECTION) {
                return;
            }

            // The invoice's journal entry has been written but not committed:
            // a concurrent user closes the same tax period right now.
            if (! str_starts_with($query->sql, 'insert into "journal_entries"')) {
                return;
            }

            $raceOutcome = 'pending';
            $race->statement("SET lock_timeout = '1500ms'");
            $default = DB::getDefaultConnection();
            DB::setDefaultConnection(self::RACE_CONNECTION);

            try {
                app(TaxPeriodClosingService::class)->closePeriod($this->lockedMonth, (int) $this->tenantId);
                $raceOutcome = 'close_committed';
            } catch (\Illuminate\Database\QueryException $e) {
                $raceOutcome = 'close_waited_for_post';
            } finally {
                DB::setDefaultConnection($default);
            }
        });

        $post = $this->postInvoice($invoice, $this->entityA);

        $closeCommittedFirst = $raceOutcome === 'close_committed';
        $postCommitted = $post->status() === 200;

        $this->assertNotNull($raceOutcome, 'The race hook must have fired during the post.');
        $this->assertFalse(
            $closeCommittedFirst && $postCommitted,
            'Lost update: the tax period was closed while the post was in flight and the post still committed into it.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC3 (entity-scoped reopen) is covered in the regime test above; the
    // permission/amendment half is covered by TaxPeriodLockScopeTest.
    // AC5: Financial Close consumes the tax fact.
    // ─────────────────────────────────────────────────────────────────────

    private function makeCloseReadyCycle(): CloseCycle
    {
        $start = now()->subMonthNoOverflow()->startOfMonth();
        $period = FiscalPeriod::create([
            'code' => 'FP278-'.uniqid(),
            'name' => 'Issue278 period',
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) $start->format('Y'),
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);

        return CloseCycle::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'entity_id' => (string) $this->entityA,
            'period_id' => $period->code,
            'name' => 'Issue278 close cycle',
            'status' => 'opened',
            'created_by' => 'system',
            'metadata' => ['cycle_signoffs' => [[
                'role' => 'Admin', 'user_id' => $this->admin->id,
                'signed_at' => now()->toIso8601String(), 'notes' => 'ok',
            ]]],
        ]);
    }

    private function taxDependency(string $policy): void
    {
        CloseDependencyDefinition::create([
            'tenant_id' => $this->tenantId,
            'dependency_code' => 'TAX_READINESS',
            'dependency_name' => 'Tax period filed and reconciled',
            'source_capability' => 'ACC-009',
            'source_readiness_contract' => 'tax-readiness',
            'dependency_mode' => CloseDependencyDefinition::MODE_REQUIRED,
            'blocking_policy' => $policy,
            'status' => 'active',
        ]);
    }

    public function test_financial_close_readiness_returns_tax_fact_and_blocks_close_with_unfiled_tax_period(): void
    {
        $this->taxDependency(CloseDependencyDefinition::POLICY_BLOCKING);
        $cycle = $this->makeCloseReadyCycle();

        $readiness = $this->actingAs($this->admin)->getJson("/api/v1/close/cycles/{$cycle->id}/readiness");
        $readiness->assertOk();
        $this->assertSame([$this->lockedMonth], array_column((array) $readiness->json('data.tax.months'), 'period'));
        $this->assertTrue((bool) $readiness->json('data.tax.months.0.unfiled'), 'The tax fact must say the period is unfiled.');
        $this->assertNotEmpty($readiness->json('data.tax.version'), 'The consumed tax fact must carry its version.');
        $this->assertTrue((bool) $readiness->json('data.tax.blocking'));
        $this->assertFalse((bool) $readiness->json('data.ready_to_close'));

        $denied = $this->actingAs($this->admin)->postJson("/api/v1/close/cycles/{$cycle->id}/close");
        $denied->assertStatus(422);
        $this->assertSame('TAX_READINESS_BLOCKED', $denied->json('reason_code'));
        $this->assertSame('opened', $cycle->fresh()->status);

        // File the return for entity A: the tax fact clears and close proceeds.
        StatutoryReturnFiling::create([
            'tenant_id' => $this->tenantId, 'country' => 'SG', 'form' => 'GST-F5',
            'period' => $this->lockedMonth, 'entity_key' => $this->entityA, 'filing_version' => 1,
            'state' => 'FILED', 'boxes' => [], 'filed_at' => now(),
        ]);

        $after = $this->actingAs($this->admin)->getJson("/api/v1/close/cycles/{$cycle->id}/readiness");
        $this->assertFalse((bool) $after->json('data.tax.blocking'), (string) $after->getContent());
        $this->assertNotSame($readiness->json('data.tax.version'), $after->json('data.tax.version'));

        $this->actingAs($this->admin)->postJson("/api/v1/close/cycles/{$cycle->id}/close")->assertOk();
        $this->assertSame('closed', $cycle->fresh()->status);
    }

    public function test_quarterly_filing_covers_its_months_in_the_tax_fact(): void
    {
        $this->taxDependency(CloseDependencyDefinition::POLICY_BLOCKING);
        $cycle = $this->makeCloseReadyCycle();

        $month = (int) substr($this->lockedMonth, 5, 2);
        $quarter = substr($this->lockedMonth, 0, 4).'-Q'.(int) ceil($month / 3);
        StatutoryReturnFiling::create([
            'tenant_id' => $this->tenantId, 'country' => 'SG', 'form' => 'GST-F5',
            'period' => $quarter, 'entity_key' => $this->entityA, 'filing_version' => 1,
            'state' => 'FILED', 'boxes' => [], 'filed_at' => now(),
        ]);

        $readiness = $this->actingAs($this->admin)->getJson("/api/v1/close/cycles/{$cycle->id}/readiness");
        $readiness->assertOk();
        $this->assertFalse((bool) $readiness->json('data.tax.months.0.unfiled'), 'A quarterly F5 files each month inside it.');
        $this->assertFalse((bool) $readiness->json('data.tax.blocking'));
    }

    public function test_warning_policy_reports_tax_fact_without_blocking_close(): void
    {
        $this->taxDependency(CloseDependencyDefinition::POLICY_WARNING);
        $cycle = $this->makeCloseReadyCycle();

        $readiness = $this->actingAs($this->admin)->getJson("/api/v1/close/cycles/{$cycle->id}/readiness");
        $this->assertTrue((bool) $readiness->json('data.tax.months.0.unfiled'));
        $this->assertFalse((bool) $readiness->json('data.tax.blocking'));
        $this->assertNotEmpty($readiness->json('data.tax.warnings'));

        $this->actingAs($this->admin)->postJson("/api/v1/close/cycles/{$cycle->id}/close")->assertOk();
    }
}
