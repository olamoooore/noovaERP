<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #513 (CN4) — posting a credit note only checked the SUM of posted
 * credits against the invoice total, before the transaction and without a row
 * lock. Nothing tracked how much of each invoice line had already been
 * credited, so a line invoiced at quantity 2 could be credited at quantity 10
 * (or at a higher price) as long as the document total still fit — and two
 * concurrent posts could both pass the pre-transaction check and together
 * exceed the invoice.
 *
 * Contract asserted here:
 *  - per invoice line: credited quantity <= invoiced quantity and credited net
 *    <= invoiced net, counting earlier POSTED credit notes;
 *  - the caps are evaluated inside the posting transaction against the
 *    invoice row locked with lockForUpdate();
 *  - two concurrent posts of two drafts that individually fit but together
 *    exceed the invoice: exactly one succeeds, the loser is rejected.
 */
class CreditNoteLineCapTest extends TenantTestCase
{
    private string $tenantId = '88888888-7777-6666-5555-444444444444';

    private User $user;

    private ?Customer $customer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => $this->tenantId,
        ]));
        $this->bindTenantContext($tenant->id);

        $this->assertTenantSchemaReady([
            'invoices',
            'invoice_lines',
            'customers',
            'customer_credit_notes',
            'customer_credit_note_lines',
            'chart_of_accounts',
            'account_mappings',
            'journal_entries',
        ]);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // #992: the customer is created on first use, not here. Customer is
        // audited, and an audited write in this never-committed test
        // transaction creates and holds the audit chain's single 'head' row;
        // the concurrency test's worker processes would then block on it.

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN4', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN4', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN4', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function customer(): Customer
    {
        return $this->customer ??= Customer::factory()->create();
    }

    /**
     * Invoice with two lines: A = 10 x 100 (1,000), B = 2 x 50 (100), total
     * 1,100. The document cap is wide enough that a per-line violation slips
     * through it, which is exactly what CN4 is about.
     */
    private function invoiceWithTwoLines(): array
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer()->id,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);

        $lineA = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Line A',
            'quantity' => 10,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $lineB = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Line B',
            'quantity' => 2,
            'unit_price' => 50,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        $invoice = $invoice->fresh();

        $this->assertEqualsWithDelta(1100.0, (float) $invoice->total, 0.01, 'invoice fixture must be 1,100');

        return [$invoice, $lineA, $lineB];
    }

    private function creditNoteForLine(Invoice $invoice, InvoiceLine $origin, float $quantity, float $unitPrice): CustomerCreditNote
    {
        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/customers/credit-notes',
            [
                'customer_id' => $this->customer()->id,
                'invoice_id' => $invoice->id,
                'credit_note_date' => now()->toDateString(),
                'reason' => 'CN4 line cap',
                'lines' => [[
                    'invoice_line_id' => $origin->id,
                    'description' => $origin->description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => 0,
                ]],
            ],
        );

        $response->assertCreated();

        return CustomerCreditNote::findOrFail($response->json('data.id'));
    }

    private function postCreditNote(CustomerCreditNote $creditNote)
    {
        return $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post");
    }

    public function test_line_quantity_above_invoiced_quantity_is_rejected(): void
    {
        [$invoice, , $lineB] = $this->invoiceWithTwoLines();

        // Quantity 10 against an invoiced 2, priced so the credited amount
        // (10 x 5 = 50) still fits inside line B's net (100) — the violation
        // is purely the quantity.
        $creditNote = $this->creditNoteForLine($invoice, $lineB, 10, 5);

        $this->assertEqualsWithDelta(50.0, (float) $creditNote->total_amount, 0.01, 'fixture credit note must stay under the document cap');
        $this->assertLessThan((float) $invoice->total, (float) $creditNote->total_amount);

        $response = $this->postCreditNote($creditNote);

        $response->assertStatus(422);
        $this->assertStringContainsString('quantity', strtolower($response->json('error') ?? ''));
        $this->assertEquals('draft', $creditNote->fresh()->status, 'a rejected credit note must stay a draft');
        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->credited_amount, 0.01, 'a rejected credit note must not settle the invoice');
        $this->assertNull($creditNote->fresh()->journal_entry_id, 'a rejected credit note must not reach the ledger');
    }

    public function test_line_amount_above_invoiced_line_amount_is_rejected(): void
    {
        [$invoice, , $lineB] = $this->invoiceWithTwoLines();

        // Quantity 2 (as invoiced) but at 100 instead of 50: 200 credited
        // against line B's net of 100 — the violation is purely the amount.
        $creditNote = $this->creditNoteForLine($invoice, $lineB, 2, 100);

        $this->assertEqualsWithDelta(200.0, (float) $creditNote->total_amount, 0.01, 'fixture credit note must stay under the document cap');
        $this->assertLessThan((float) $invoice->total, (float) $creditNote->total_amount);

        $response = $this->postCreditNote($creditNote);

        $response->assertStatus(422);
        $this->assertStringContainsString('amount', strtolower($response->json('error') ?? ''));
        $this->assertEquals('draft', $creditNote->fresh()->status, 'a rejected credit note must stay a draft');
        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->credited_amount, 0.01, 'a rejected credit note must not settle the invoice');
        $this->assertNull($creditNote->fresh()->journal_entry_id, 'a rejected credit note must not reach the ledger');
    }

    public function test_line_caps_accumulate_across_earlier_posted_credit_notes(): void
    {
        [$invoice, , $lineB] = $this->invoiceWithTwoLines();

        // First credit note takes half of line B (quantity 1 = 50). It is
        // within every cap and must post normally.
        $first = $this->creditNoteForLine($invoice, $lineB, 1, 50);
        $this->postCreditNote($first)->assertOk();
        $this->assertEquals('posted', $first->fresh()->status);
        $this->assertEqualsWithDelta(50.0, (float) $invoice->fresh()->credited_amount, 0.01);

        // Second credit note takes the other half again (quantity 2 = 100):
        // quantity 1 + 2 > 2 invoiced, and 50 + 100 > 100 net, even though the
        // document total 50 + 100 = 150 is well under the invoice's 1,100.
        $second = $this->creditNoteForLine($invoice, $lineB, 2, 50);

        $this->assertEqualsWithDelta(100.0, (float) $second->total_amount, 0.01, 'the second credit note itself is 2 x 50 = 100');

        $response = $this->postCreditNote($second);

        $response->assertStatus(422);
        $this->assertEquals('draft', $second->fresh()->status, 'the rejected credit note must stay a draft');
        $this->assertEqualsWithDelta(50.0, (float) $invoice->fresh()->credited_amount, 0.01, 'only the first credit note may settle the invoice');
    }

    public function test_concurrent_posts_of_drafts_that_together_exceed_the_invoice_allow_only_one(): void
    {
        $fixture = $this->runWorker([
            'action' => 'setup_cn_race',
        ]);

        $this->assertEquals('ok', $fixture['status'], 'fixture worker must succeed: '.($fixture['message'] ?? ''));

        $invoiceId = (int) $fixture['invoice_id'];
        $cnIds = array_map('intval', $fixture['cn_ids']);
        $this->assertCount(2, $cnIds);

        try {
            $goFile = tempnam(sys_get_temp_dir(), 'cn4go_');
            @unlink($goFile);

            $results = [];
            $procs = [];
            foreach ($cnIds as $index => $cnId) {
                $resultFile = tempnam(sys_get_temp_dir(), "cn4res{$index}_");
                // #992: tempnam() creates the file; remove it so waitForFile()
                // waits for the worker's result instead of passing at once and
                // blocking in proc_close() on a worker that never finishes.
                @unlink($resultFile);
                $results[] = $resultFile;
                $procs[] = $this->spawnPostingWorker($cnId, $resultFile, $goFile, (string) $fixture['race_tenant_id']);
            }

            // Both workers have booted and are parked on the barrier, so the two
            // posts hit the invoice row at the same time.
            foreach ($results as $resultFile) {
                $this->waitForFile($resultFile.'.ready', 20, 'posting worker must signal readiness before the barrier releases');
            }
            file_put_contents($goFile, 'go');

            foreach ($results as $index => $resultFile) {
                $this->waitForFile($resultFile, 60, 'posting worker must finish and write its result');
                $this->closeWorker($procs[$index]);
            }

            $outcomes = array_map(fn ($file) => json_decode((string) file_get_contents($file), true), $results);

            $succeeded = array_values(array_filter($outcomes, fn ($o) => ($o['status'] ?? null) === 'ok'));
            $rejected = array_values(array_filter($outcomes, fn ($o) => ($o['status'] ?? null) !== 'ok'));

            $this->assertCount(1, $succeeded, 'exactly one concurrent post must succeed; outcomes: '.json_encode($outcomes));
            $this->assertCount(1, $rejected, 'the losing post must be rejected');
            $this->assertStringContainsString(
                'exceeds',
                strtolower((string) ($rejected[0]['message'] ?? '')),
                'the losing post must be rejected for exceeding the invoice credit, got: '.json_encode($rejected[0])
            );

            $statuses = CustomerCreditNote::whereIn('id', $cnIds)->pluck('status', 'id');
            $this->assertSame(1, $statuses->filter(fn ($s) => $s === 'posted')->count(), 'exactly one credit note may be posted');
            $this->assertSame(1, $statuses->filter(fn ($s) => $s === 'draft')->count(), 'the rejected credit note must stay a draft');

            $postedTotal = (float) CustomerCreditNote::whereIn('id', $cnIds)->where('status', 'posted')->sum('total_amount');
            $this->assertLessThanOrEqual((float) Invoice::find($invoiceId)->total, $postedTotal, 'posted credits must never exceed the invoice total');
        } finally {
            // #992: stop any worker still running before purging, so a stuck
            // worker fails the test instead of hanging it.
            $this->terminateWorkers($procs ?? []);
            $this->purgeCommittedFixtures($fixture);
        }
    }

    /**
     * Stop and reap worker processes that were not closed normally, e.g. after
     * a failed wait. A worker blocked on a lock held by this test's own
     * transaction never exits, so waiting for it would hang the test.
     *
     * @param  array<int, resource>  $procs
     */
    private function terminateWorkers(array $procs): void
    {
        foreach ($procs as $proc) {
            if (is_resource($proc)) {
                proc_terminate($proc, 9);
                proc_close($proc);
            }
        }
    }

    /**
     * Spawn an independent PHP process that boots Laravel with its own database
     * connection and calls the real CustomerCreditNoteService::postCreditNote()
     * once the shared barrier file appears.
     *
     * @return resource
     */
    private function spawnPostingWorker(int $creditNoteId, string $resultFile, string $goFile, string $raceTenantId)
    {
        // The tenant context makes the worker boot with tenancy initialized —
        // PostingEngine refuses to post without it. The tenant schema does not
        // exist in the test database (tenant migrations run into `public`),
        // so the worker's search_path falls through to public, which is where
        // the fixtures live.
        $workerConfig = json_encode([
            'action' => 'post_credit_note',
            'credit_note_id' => $creditNoteId,
            'tenant_id' => $raceTenantId,
            'result_file' => $resultFile,
            'barrier_file' => $goFile,
            'barrier_wait_ms' => 30000,
        ]);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(
            // `exec` replaces the shell, so terminateWorkers() signals PHP itself.
            'exec php '.base_path('tests/concurrency_worker.php').' '.escapeshellarg($workerConfig),
            $desc,
            $pipes
        );

        $this->assertNotFalse($proc, 'proc_open failed to spawn the posting worker');

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return $proc;
    }

    /**
     * @param  resource  $proc
     */
    private function closeWorker($proc): void
    {
        if (! is_resource($proc)) {
            return;
        }

        $exitCode = proc_close($proc);
        $this->assertEquals(0, $exitCode, 'worker process must exit cleanly');
    }

    private function waitForFile(string $path, int $timeoutSeconds, string $message): void
    {
        $start = microtime(true);
        while (! file_exists($path) && (microtime(true) - $start) < $timeoutSeconds) {
            usleep(25000);
        }

        $this->assertFileExists($path, $message);
    }

    /**
     * Run a worker action and return its decoded result.
     */
    private function runWorker(array $config): array
    {
        $resultFile = tempnam(sys_get_temp_dir(), 'cn4setup_');
        $config['result_file'] = $resultFile;

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(
            'php '.base_path('tests/concurrency_worker.php').' '.escapeshellarg(json_encode($config)),
            $desc,
            $pipes
        );

        $this->assertNotFalse($proc, 'proc_open failed to spawn the fixture worker');

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $this->assertEquals(0, proc_close($proc), 'fixture worker must exit cleanly');
        $this->assertFileExists($resultFile, 'fixture worker must write a result file');

        return json_decode((string) file_get_contents($resultFile), true) ?: [];
    }

    /**
     * The fixture worker commits, so its rows outlive the test transaction —
     * remove them again in FK-safe order.
     */
    private function purgeCommittedFixtures(array $fixture): void
    {
        if (empty($fixture['invoice_id']) && empty($fixture['customer_id'])) {
            return;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            config('database.connections.pgsql.host'),
            config('database.connections.pgsql.port'),
            config('database.connections.pgsql.database')
        );

        $pdo = new \PDO(
            $dsn,
            config('database.connections.pgsql.username'),
            config('database.connections.pgsql.password'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        // #992: a killed worker's backend can still hold row locks until its
        // own lock wait ends; never let the best-effort purge wait on them.
        $pdo->exec("SET lock_timeout = '5s'");

        $cnIds = implode(',', array_map('intval', $fixture['cn_ids'] ?? []));
        $accountIds = implode(',', array_map('intval', $fixture['account_ids'] ?? []));
        $invoiceId = (int) ($fixture['invoice_id'] ?? 0);
        $customerId = (int) ($fixture['customer_id'] ?? 0);
        $userId = (int) ($fixture['user_id'] ?? 0);

        // Best-effort teardown: a cleanup failure must not mask the test's own
        // assertion outcome, and these rows live in a disposable test database.
        $purge = function (string $sql) use ($pdo): void {
            try {
                $pdo->exec($sql);
            } catch (\PDOException) {
                // ignored — teardown only
            }
        };

        // The linked journal entries are captured from the notes themselves —
        // origin metadata is not guaranteed on every posting path.
        $jeIds = '';
        if ($cnIds !== '') {
            try {
                $jeIds = implode(',', array_filter(array_map(
                    'intval',
                    $pdo->query("SELECT journal_entry_id FROM customer_credit_notes WHERE id IN ({$cnIds}) AND journal_entry_id IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN)
                )));
            } catch (\PDOException) {
                $jeIds = '';
            }
        }

        // Posted documents are guarded against deletion at the database level
        // (prevent_posted_document_deletion) — unpost the disposable fixtures
        // first, or the whole purge silently leaves rows behind and later
        // suites see them.
        if ($cnIds !== '') {
            $purge("UPDATE customer_credit_notes SET status = 'draft' WHERE id IN ({$cnIds})");
        }
        if ($invoiceId > 0) {
            $purge("UPDATE invoices SET status = 'draft', lifecycle_status = 'draft' WHERE id = {$invoiceId}");
        }
        if ($jeIds !== '') {
            $purge("UPDATE journal_entries SET status = 'draft', lifecycle_status = 'draft' WHERE id IN ({$jeIds})");
        }

        if ($cnIds !== '') {
            $purge("DELETE FROM customer_credit_note_lines WHERE credit_note_id IN ({$cnIds})");
            $purge("DELETE FROM customer_credit_notes WHERE id IN ({$cnIds})");
            $purge("DELETE FROM tax_transaction_details WHERE document_type = 'credit_note' AND document_id IN ({$cnIds})");
        }

        if ($jeIds !== '') {
            $purge("DELETE FROM journal_entry_lines WHERE journal_entry_id IN ({$jeIds})");
            $purge("DELETE FROM journal_entries WHERE id IN ({$jeIds})");
        }

        if ($invoiceId > 0) {
            $purge("DELETE FROM invoice_lines WHERE invoice_id = {$invoiceId}");
            $purge("DELETE FROM invoices WHERE id = {$invoiceId}");
        }

        if ($accountIds !== '') {
            $purge("DELETE FROM account_mappings WHERE account_id IN ({$accountIds})");
            $purge("DELETE FROM chart_of_accounts WHERE id IN ({$accountIds})");
        }

        if ($customerId > 0) {
            $purge("DELETE FROM customers WHERE id = {$customerId}");
        }

        if ($userId > 0) {
            $purge("DELETE FROM fiscal_periods WHERE created_by = {$userId}");
            $purge("DELETE FROM user_company_access WHERE user_id = {$userId}");
            $purge("DELETE FROM users WHERE id = {$userId}");
        }

        // #992: the posting workers run with the race tenant initialized, so
        // their audited writes commit hash-chained audit rows and advance the
        // audit chain's 'head' row. Remove them too, or later tests in the same
        // run start from a chain that is not empty.
        if (! empty($fixture['race_tenant_id'])) {
            $raceTenant = $pdo->quote((string) $fixture['race_tenant_id']);
            $purge("DELETE FROM platform_system_logs WHERE tenant_id = {$raceTenant}");
            if ($invoiceId > 0) {
                $purge("DELETE FROM audit_logs WHERE reference_type = 'invoice' AND reference_id = {$invoiceId}");
            }
            if ($cnIds !== '') {
                $purge("DELETE FROM audit_logs WHERE reference_type IN ('credit_note', 'customer_credit_note') AND reference_id IN ({$cnIds})");
            }
            $purge("DELETE FROM audit_chain_heads WHERE id = 'head' AND tenant_id = {$raceTenant}");
        }

        if (! empty($fixture['race_tenant_id'])) {
            $purge("DELETE FROM tenants WHERE id = '".$fixture['race_tenant_id']."'");
        }

        $pdo = null;
    }
}
