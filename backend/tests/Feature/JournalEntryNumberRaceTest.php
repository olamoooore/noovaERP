<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

/**
 * Journal entry number race.
 *
 * Journal::getNextEntryNumber() reads `sequence_number` from the model it was
 * loaded with, then increments the row. Two requests that load the journal
 * before either has incremented it get the same number, and the second insert
 * hits the unique key on journal_entries.entry_number.
 *
 * Business rule asserted: every concurrently created draft in one journal is
 * accepted and gets its own entry number.
 *
 * The requests run in separate PHP processes (tests/concurrency_worker.php),
 * each sending a real POST /api/v1/accounting/journal-entries through the HTTP
 * kernel with a Sanctum token, so they use separate database connections and
 * separate transactions. Fixtures are committed by a worker so all processes
 * can see them, and are removed again at the end.
 */
class JournalEntryNumberRaceTest extends TenantTestCase
{
    private const WORKERS = 8;

    public function test_concurrent_drafts_in_one_journal_all_succeed_with_distinct_entry_numbers(): void
    {
        $fixture = $this->runWorker(['action' => 'setup_je_number_race']);
        $this->assertEquals('ok', $fixture['status'] ?? null, 'fixture worker must succeed: '.json_encode($fixture));

        try {
            $goFile = tempnam(sys_get_temp_dir(), 'jenrgo_');
            @unlink($goFile);

            $payload = [
                'journal_id' => (int) $fixture['journal_id'],
                'entry_date' => now()->toDateString(),
                'description' => 'Journal number race',
                'lines' => [
                    ['account_id' => (int) $fixture['account_ids'][0], 'debit' => 100, 'credit' => 0],
                    ['account_id' => (int) $fixture['account_ids'][1], 'debit' => 0, 'credit' => 100],
                ],
            ];

            $results = [];
            $procs = [];
            for ($i = 0; $i < self::WORKERS; $i++) {
                $resultFile = tempnam(sys_get_temp_dir(), "jenrres{$i}_");
                $results[] = $resultFile;
                $procs[] = $this->spawnWorker([
                    'action' => 'http_request',
                    'method' => 'POST',
                    'uri' => '/api/v1/accounting/journal-entries',
                    'token' => $fixture['api_token'],
                    'payload' => $payload,
                    'result_file' => $resultFile,
                    'barrier_file' => $goFile,
                    'barrier_wait_ms' => 60000,
                ]);
            }

            // All workers have booted and are parked on the barrier, so the
            // requests reach the journal row at the same time.
            foreach ($results as $resultFile) {
                $this->waitForFile($resultFile.'.ready', 60, 'request worker must signal readiness before the barrier releases');
            }
            file_put_contents($goFile, 'go');

            foreach ($results as $index => $resultFile) {
                $this->waitForFile($resultFile, 120, 'request worker must finish and write its result');
                $this->assertEquals(0, proc_close($procs[$index]), 'worker process must exit cleanly');
            }

            $outcomes = array_map(fn ($file) => json_decode((string) file_get_contents($file), true), $results);
            @unlink($goFile);
            foreach ($results as $resultFile) {
                @unlink($resultFile);
                @unlink($resultFile.'.ready');
            }

            $summary = array_map(fn ($o) => [
                'http_status' => $o['http_status'] ?? null,
                'entry_number' => $o['body']['entry']['entry_number'] ?? null,
                'error' => $o['body']['error'] ?? $o['body']['message'] ?? $o['message'] ?? null,
            ], $outcomes);

            $created = array_values(array_filter($summary, fn ($s) => $s['http_status'] === 201));
            $this->assertCount(
                self::WORKERS,
                $created,
                'every concurrent draft in the journal must be accepted; outcomes: '.json_encode($summary, JSON_PRETTY_PRINT)
            );

            $numbers = array_column($created, 'entry_number');
            $this->assertCount(self::WORKERS, array_unique($numbers), 'every draft must get its own entry number: '.json_encode($numbers));
        } finally {
            $this->purgeCommittedFixtures($fixture);
        }
    }

    /**
     * @return resource
     */
    private function spawnWorker(array $config)
    {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            'php '.base_path('tests/concurrency_worker.php').' '.escapeshellarg(json_encode($config)),
            $desc,
            $pipes
        );
        $this->assertNotFalse($proc, 'proc_open failed to spawn a worker');
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return $proc;
    }

    private function runWorker(array $config): array
    {
        $resultFile = tempnam(sys_get_temp_dir(), 'jenrsetup_');
        $config['result_file'] = $resultFile;
        $this->assertEquals(0, proc_close($this->spawnWorker($config)), 'fixture worker must exit cleanly');
        $this->assertFileExists($resultFile, 'fixture worker must write a result file');
        $result = json_decode((string) file_get_contents($resultFile), true) ?: [];
        @unlink($resultFile);

        return $result;
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
     * The workers commit, so their rows outlive the test transaction; remove
     * them in FK-safe order on a separate connection.
     */
    private function purgeCommittedFixtures(array $fixture): void
    {
        if (empty($fixture['journal_id'])) {
            return;
        }

        $pdo = new \PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                config('database.connections.pgsql.host'),
                config('database.connections.pgsql.port'),
                config('database.connections.pgsql.database')
            ),
            config('database.connections.pgsql.username'),
            config('database.connections.pgsql.password'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $journalId = (int) $fixture['journal_id'];
        $userId = (int) $fixture['user_id'];
        $accountIds = implode(',', array_map('intval', $fixture['account_ids'] ?? []));
        $tenantId = $pdo->quote((string) $fixture['race_tenant_id']);

        $statements = [
            "DELETE FROM journal_entry_lines WHERE journal_entry_id IN (SELECT id FROM journal_entries WHERE journal_id = {$journalId})",
            "DELETE FROM journal_entries WHERE journal_id = {$journalId}",
            "DELETE FROM journals WHERE id = {$journalId}",
            "DELETE FROM chart_of_accounts WHERE id IN ({$accountIds})",
            "DELETE FROM personal_access_tokens WHERE tokenable_id = {$userId}",
            "DELETE FROM user_company_access WHERE user_id = {$userId}",
            "DELETE FROM tenants WHERE id = {$tenantId}",
        ];
        if (! empty($fixture['fiscal_period_id'])) {
            $statements[] = 'DELETE FROM fiscal_periods WHERE id = '.(int) $fixture['fiscal_period_id'];
        }
        $statements[] = "DELETE FROM users WHERE id = {$userId}";

        // Best-effort teardown: a cleanup failure must not mask the test's own
        // outcome; these rows live in a disposable test database.
        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                fwrite(STDERR, "JournalEntryNumberRaceTest cleanup: {$e->getMessage()}\n");
            }
        }
    }
}
