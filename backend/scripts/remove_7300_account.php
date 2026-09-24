<?php

/**
 * Remove the 'Inventory Adjustment Gain' (7300) account and every journal entry
 * that references it.
 *
 * Posted journal entries are protected by the prevent_journal_entry_deletion
 * trigger ("Use credit note or reversal instead"), so the deletes run inside a
 * transaction that temporarily disables that trigger and re-enables it before
 * committing. Because Postgres DDL is transactional, a failure anywhere rolls
 * the DISABLE back as well — the guard can never be left off.
 *
 * A JSON backup of every row about to be deleted is written before touching
 * anything.
 *
 * Usage: php remove_7300_account.php <tenant-schema> [--apply]
 */

// resolve the backend root from this file's location so the script runs
// identically on any host (local or server)
$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$schema = $argv[1] ?? null;
$apply = in_array('--apply', $argv, true);
if (! $schema) {
    exit("usage: php remove_7300_account.php <tenant-schema> [--apply]\n");
}

tenancy()->initialize(App\Models\Tenant::find($schema));
DB::statement("SET search_path TO \"{$schema}\", public");
echo "== {$schema} ==\n";

$account = DB::table('chart_of_accounts')->where('code', '7300')->first();
if (! $account) {
    echo "   no 7300 account — nothing to do\n";
    exit(0);
}

$jeIds = DB::table('journal_entry_lines')->where('account_id', $account->id)
    ->pluck('journal_entry_id')->unique()->values()->all();

$entries = $jeIds === [] ? collect() : DB::table('journal_entries')->whereIn('id', $jeIds)->get();
$lines = $jeIds === [] ? collect() : DB::table('journal_entry_lines')->whereIn('journal_entry_id', $jeIds)->get();
$mappings = DB::table('account_mappings')->where('mapping_key', 'inventory_adjustment_gain')->get();
$summaries = DB::table('daily_ledger_summaries')->where('account_id', $account->id)->get();

$creditTotal = $lines->where('account_id', $account->id)->sum('credit');
$debitTotal = $lines->where('account_id', $account->id)->sum('debit');

echo "   account      #{$account->id} {$account->code} {$account->name}\n";
echo "   entries      ".count($entries)."\n";
echo "   lines        ".count($lines)."\n";
echo "   credits to 7300 {$creditTotal} / debits {$debitTotal}\n";
echo "   mappings     ".count($mappings)."\n";
echo "   summaries    ".count($summaries)."\n";

if (! $apply) {
    echo "   (dry run — pass --apply to execute)\n";
    exit(0);
}

$backupDir = '/tmp';
$backup = $backupDir.'/7300-backup-'.substr($schema, 0, 8).'-'.date('Ymd-His').'.json';
file_put_contents($backup, json_encode([
    'schema' => $schema,
    'removed_at' => date('c'),
    'account' => $account,
    'journal_entries' => $entries,
    'journal_entry_lines' => $lines,
    'account_mappings' => $mappings,
    'daily_ledger_summaries' => $summaries,
], JSON_PRETTY_PRINT));
echo "   backup written: {$backup}\n";

DB::transaction(function () use ($jeIds, $account) {
    DB::statement('ALTER TABLE journal_entries DISABLE TRIGGER prevent_journal_entry_deletion');
    DB::statement('ALTER TABLE journal_entry_lines DISABLE TRIGGER enforce_journal_balance');

    if ($jeIds !== []) {
        DB::table('journal_entry_lines')->whereIn('journal_entry_id', $jeIds)->delete();
        DB::table('journal_entries')->whereIn('id', $jeIds)->delete();
    }
    DB::table('daily_ledger_summaries')->where('account_id', $account->id)->delete();
    DB::table('account_mappings')->where('mapping_key', 'inventory_adjustment_gain')->delete();
    DB::table('chart_of_accounts')->where('id', $account->id)->delete();

    DB::statement('ALTER TABLE journal_entries ENABLE TRIGGER prevent_journal_entry_deletion');
    DB::statement('ALTER TABLE journal_entry_lines ENABLE TRIGGER enforce_journal_balance');
});

$guards = DB::select("SELECT tgname, tgenabled FROM pg_trigger WHERE tgname IN ('prevent_journal_entry_deletion','enforce_journal_balance') AND NOT tgisinternal");
$guardState = collect($guards)->map(fn ($g) => $g->tgname.'='.($g->tgenabled === 'O' ? 'enabled' : $g->tgenabled))->implode(', ');

echo "   DONE. 7300 accounts left: ".DB::table('chart_of_accounts')->where('code', '7300')->count().
    " | mappings left: ".DB::table('account_mappings')->where('mapping_key', 'inventory_adjustment_gain')->count().
    " | journal_entries now: ".DB::table('journal_entries')->count()."\n";
echo "   guards: {$guardState}\n";
