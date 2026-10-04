<?php

namespace App\Services\BankStatements;

use App\Models\BankAccount;
use App\Models\BankStatementAllocation;
use App\Models\BankStatementImport;
use App\Models\BankStatementMatch;
use App\Models\BankStatementTransaction;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BankStatementImportService
{
    public function __construct(
        private BankStatementParser $parser,
        private BankStatementAuditService $audit,
        private StatementAmountParser $amounts = new StatementAmountParser,
    ) {}

    public function preview(UploadedFile $file, int $bankAccountId, int $userId, int $headerRow = 1, ?string $worksheet = null): array
    {
        $account = BankAccount::active()->with('glAccount')->findOrFail($bankAccountId);
        if (! $account->glAccount) {
            throw new \InvalidArgumentException('Selected bank account is not linked to a GL account.');
        }
        $type = strtolower($file->getClientOriginalExtension());
        if (! in_array($type, ['csv', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Only CSV and XLSX statements are supported.');
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $duplicate = BankStatementImport::where('bank_account_id', $bankAccountId)->where('file_hash', $hash)->where('status', '!=', 'reversed')->first();
        if ($duplicate) {
            throw new DuplicateStatementFile($duplicate->id);
        }
        $tenantId = (string) tenant()->getTenantKey();
        $path = $file->storeAs("bank-statements/{$tenantId}/previews", Str::uuid().'.'.$type, ['disk' => 'local']);
        $parsed = $this->parser->read(Storage::disk('local')->path($path), $type, $headerRow, $worksheet);
        $proposedMapping = $this->parser->proposeMapping($parsed['headers']);
        $dateOrder = $this->inferSlashDateOrder($parsed['rows'], (string) ($proposedMapping['transaction_date'] ?? ''));
        // BK7: an overlapping export (or a re-download with a different file
        // hash) is not a whole-file duplicate, so flag the rows whose content
        // key already exists — the operator can skip them (default) or choose
        // to import anyway.
        $duplicateRows = $this->findExistingRows($parsed['rows'], [
            'mapping' => $proposedMapping, 'currency_code' => $account->currency_code,
        ], $bankAccountId, $dateOrder);
        $token = Str::random(64);
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($file->getClientOriginalName()));
        Cache::put('bank-statement-preview:'.$token, ['tenant_id' => $tenantId, 'user_id' => $userId, 'bank_account_id' => $bankAccountId, 'path' => $path, 'type' => $type, 'hash' => $hash, 'file_name' => $safeName, 'header_row' => $headerRow, 'worksheet' => $parsed['worksheet']], now()->addHour());
        $this->audit->record('import_preview_generated', ['actor_id' => $userId, 'bank_account_id' => $bankAccountId, 'metadata' => ['file_hash' => $hash, 'file_type' => $type, 'source_rows' => count($parsed['rows'])]]);

        return [
            'preview_token' => $token, 'file_hash' => $hash, 'headers' => $parsed['headers'],
            'proposed_mapping' => $proposedMapping, 'source_rows' => count($parsed['rows']),
            'sample' => array_slice($parsed['rows'], 0, 100),
            // BK1: the day/month order inferred from the whole date column, so
            // the operator can see (and override) it before importing.
            'date_column_format' => $dateOrder === 'd/m' ? 'd/m/Y' : ($dateOrder === 'm/d' ? 'm/d/Y' : null),
            'date_column_ambiguous' => $this->hasSlashDate($parsed['rows'], (string) ($proposedMapping['transaction_date'] ?? '')) && $dateOrder === null,
            // BK7: rows of this file that already exist for the account, so the
            // preview can offer "skip / import anyway".
            'duplicate_row_count' => count($duplicateRows),
            'duplicate_rows' => array_slice($duplicateRows, 0, 100),
        ];
    }

    public function confirm(array $input, int $userId): BankStatementImport
    {
        $key = 'bank-statement-preview:'.$input['preview_token'];
        $preview = Cache::get($key);
        if (! $preview || $preview['tenant_id'] !== (string) tenant()->getTenantKey() || (int) $preview['user_id'] !== $userId) {
            throw new \InvalidArgumentException('Preview token is invalid or expired.');
        }
        $account = BankAccount::active()->findOrFail($preview['bank_account_id']);
        if (strtoupper($input['currency_code']) !== strtoupper($account->currency_code)) {
            throw new \InvalidArgumentException('Statement currency does not match the selected bank account.');
        }
        if (hash_file('sha256', Storage::disk('local')->path($preview['path'])) !== $preview['hash']) {
            throw new \RuntimeException('Uploaded statement integrity check failed.');
        }
        $parsed = $this->parser->read(Storage::disk('local')->path($preview['path']), $preview['type'], $preview['header_row'], $preview['worksheet']);

        // BK1: slash/dot dates never fall back to Carbon::parse (which reads
        // them m/d/Y and silently swaps day/month). The order comes from the
        // explicit format (bank format profile) or is inferred from the whole
        // column; a column that cannot settle its own order blocks the import.
        $explicitFormat = isset($input['date_format']) && trim((string) $input['date_format']) !== ''
            ? trim((string) $input['date_format'])
            : null;
        $dateColumn = (string) ($input['mapping']['transaction_date'] ?? '');
        $inferredOrder = $explicitFormat === null ? $this->inferSlashDateOrder($parsed['rows'], $dateColumn) : null;
        if ($explicitFormat === null && $this->hasSlashDate($parsed['rows'], $dateColumn) && $inferredOrder === null) {
            throw new \InvalidArgumentException(
                'Ambiguous transaction date column: the day/month order cannot be determined '
                .'(no date has a day above 12, or rows disagree on the order). '
                .'Choose a date format (d/m/Y or m/d/Y) and import again.'
            );
        }

        return DB::transaction(function () use ($input, $userId, $preview, $parsed, $key, $inferredOrder) {
            $import = BankStatementImport::create([
                'bank_account_id' => $preview['bank_account_id'], 'file_name' => $preview['file_name'], 'file_hash' => $preview['hash'],
                'storage_path' => $preview['path'], 'file_type' => $preview['type'], 'status' => 'importing',
                'statement_start_date' => $input['statement_start_date'] ?? null, 'statement_end_date' => $input['statement_end_date'],
                'opening_balance' => $input['opening_balance'] ?? null, 'closing_balance' => $input['closing_balance'],
                'currency_code' => strtoupper($input['currency_code']), 'row_count' => count($parsed['rows']), 'mapping' => $input['mapping'],
                'metadata' => ['worksheet' => $parsed['worksheet'], 'header_row' => $preview['header_row'], 'parser_version' => 1], 'imported_by' => $userId,
            ]);
            $this->audit->record('import_confirmed', ['actor_id' => $userId, 'bank_account_id' => $import->bank_account_id, 'import_id' => $import->id, 'after_state' => ['mapping' => $input['mapping']], 'metadata' => ['file_hash' => $preview['hash']]]);
            $groups = [];
            $errors = 0;
            $zeroRows = 0;
            $occurrences = [];
            foreach ($parsed['rows'] as $row) {
                try {
                    $normal = $this->normalize($row, $input, $preview['bank_account_id'], $inferredOrder);
                } catch (UnparsableAmountException $e) {
                    $errors++;
                    DB::table('bank_statement_import_errors')->insert([
                        'id' => (string) Str::uuid(),
                        'import_id' => $import->id,
                        'source_row' => $row['source_row'],
                        'field' => $e->field,
                        'code' => 'AMOUNT_UNPARSEABLE',
                        'severity' => 'error',
                        'message' => $e->getMessage(),
                        'safe_value' => mb_substr($e->raw, 0, 255),
                        'created_at' => now(),
                    ]);

                    continue;
                } catch (RowFieldException $e) {
                    // BK8: the error names the offending field and keeps a safe
                    // preview of the raw value, so the operator can fix the file.
                    $errors++;
                    DB::table('bank_statement_import_errors')->insert([
                        'id' => (string) Str::uuid(),
                        'import_id' => $import->id,
                        'source_row' => $row['source_row'],
                        'field' => $e->field,
                        'code' => $e->errorCode,
                        'severity' => 'error',
                        'message' => $e->getMessage(),
                        'safe_value' => $e->raw === '' ? null : mb_substr($e->raw, 0, 255),
                        'created_at' => now(),
                    ]);

                    continue;
                } catch (\Throwable) {
                    $errors++;
                    DB::table('bank_statement_import_errors')->insert([
                        'id' => (string) Str::uuid(),
                        'import_id' => $import->id,
                        'source_row' => $row['source_row'],
                        'field' => null,
                        'code' => 'ROW_NORMALIZATION_FAILED',
                        'severity' => 'error',
                        'message' => 'The row could not be normalized. Check its date, amount, and required mapped values.',
                        'safe_value' => null,
                        'created_at' => now(),
                    ]);

                    continue;
                }
                if ((float) $normal['amount'] === 0.0) {
                    // BK2: a real zero amount is counted and reported — one
                    // warning row per source line, plus the total on the
                    // import's metadata — never dropped silently.
                    $zeroRows++;
                    DB::table('bank_statement_import_errors')->insert([
                        'id' => (string) Str::uuid(),
                        'import_id' => $import->id,
                        'source_row' => $normal['source_row'],
                        'field' => 'amount',
                        'code' => 'ZERO_AMOUNT_ROW',
                        'severity' => 'warning',
                        'message' => 'The row amount is 0.00, so it was not imported as a transaction.',
                        'safe_value' => '0',
                        'created_at' => now(),
                    ]);

                    continue;
                }
                // BK7: the file-independent content key identifies the money
                // movement; the occurrence counter keeps identical same-day
                // rows inside this file apart (and reproduces the same
                // occurrence order when an overlapping export is imported).
                $occurrence = ($occurrences[$normal['content_key']] ?? 0) + 1;
                $occurrences[$normal['content_key']] = $occurrence;
                $normal['transaction_key'] = $this->transactionKey($normal['content_key'], $occurrence);
                unset($normal['content_key']);
                $groups[$normal['transaction_key']] ??= ['transaction' => $normal, 'allocations' => []];
                if ($normal['allocation_reference']) {
                    $groups[$normal['transaction_key']]['allocations'][] = $normal;
                }
            }
            $created = 0;
            $duplicates = 0;
            $duplicatesImported = 0;
            $importDuplicates = (bool) ($input['import_duplicates'] ?? false);
            foreach ($groups as $group) {
                $data = $group['transaction'];
                // BK8: a row error makes the import all-or-nothing for matching.
                // Valid rows are still recorded for traceability, but they are
                // compensated (reversed) in the same run, so a failed import
                // never leaves a matchable transaction behind.
                if ($errors > 0) {
                    $data['status'] = 'reversed';
                }
                $allocationReference = $data['allocation_reference'];
                $allocationAmount = $data['allocation_amount'];
                unset($data['allocation_reference'], $data['allocation_amount']);
                $transaction = BankStatementTransaction::where('bank_account_id', $preview['bank_account_id'])->where('transaction_key', $data['transaction_key'])->where('status', '!=', 'reversed')->first();
                if ($transaction) {
                    if (! $importDuplicates) {
                        $duplicates++;

                        continue;
                    }
                    // BK7: the operator explicitly chose "import anyway"; the
                    // kept copy earns a unique key so both records stay side
                    // by side without weakening the content key of either.
                    $data['transaction_key'] = hash('sha256', $data['transaction_key'].'|'.$import->id);
                    $duplicatesImported++;
                }
                $transaction = BankStatementTransaction::create(['import_id' => $import->id, ...$data]);
                $created++;
                foreach ($group['allocations'] as $sequence => $allocation) {
                    BankStatementAllocation::create(['bank_statement_transaction_id' => $transaction->id, 'allocation_type' => 'invoice_reference', 'external_reference' => $allocation['allocation_reference'], 'amount' => $allocation['allocation_amount'], 'sequence' => $sequence + 1, 'raw_data' => $allocation['raw_data']]);
                }
                if ($allocationReference && $group['allocations'] === []) {
                    BankStatementAllocation::create(['bank_statement_transaction_id' => $transaction->id, 'allocation_type' => 'invoice_reference', 'external_reference' => $allocationReference, 'amount' => $allocationAmount, 'sequence' => 1, 'raw_data' => $data['raw_data']]);
                }
            }
            $import->update(['status' => $errors ? 'failed' : 'imported', 'transaction_count' => $errors ? 0 : $created, 'duplicate_count' => $duplicates, 'error_count' => $errors, 'imported_at' => $errors ? null : now(), 'metadata' => array_merge($import->metadata ?? [], ['zero_amount_rows' => $zeroRows, 'compensated_transaction_count' => $errors ? $created : 0, 'duplicates_imported' => $duplicatesImported])]);
            $this->audit->record($errors ? 'import_failed' : 'import_completed', ['actor_id' => $userId, 'bank_account_id' => $import->bank_account_id, 'import_id' => $import->id, 'after_state' => ['status' => $errors ? 'failed' : 'imported', 'row_count' => count($parsed['rows']), 'transaction_count' => $errors ? 0 : $created, 'compensated_transaction_count' => $errors ? $created : 0, 'duplicate_count' => $duplicates, 'duplicates_imported' => $duplicatesImported, 'error_count' => $errors]]);
            Cache::forget($key);

            return $import->fresh();
        });
    }

    public function reverse(string $importId, string $reason, int $actorId): BankStatementImport
    {
        return DB::transaction(function () use ($importId, $reason, $actorId) {
            $import = BankStatementImport::lockForUpdate()->findOrFail($importId);
            if ($import->status === 'reversed') {
                return $import;
            }
            $previousStatus = $import->status;
            $transactions = BankStatementTransaction::where('import_id', $import->id)->orderBy('id')->lockForUpdate()->get();
            $matches = BankStatementMatch::whereIn('bank_statement_transaction_id', $transactions->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            if ($matches->contains(fn ($match) => $match->reconciliation_id !== null)) {
                throw new \DomainException('Reverse the completed reconciliation before reversing this import.');
            }
            BankStatementMatch::whereIn('id', $matches->pluck('id'))->update(['status' => 'reversed', 'version' => DB::raw('version + 1')]);
            BankStatementTransaction::whereIn('id', $transactions->pluck('id'))->update(['status' => 'reversed']);
            $import->update(['status' => 'reversed']);
            $this->audit->record('import_reversed', ['actor_id' => $actorId, 'bank_account_id' => $import->bank_account_id, 'import_id' => $import->id, 'reason' => $reason, 'before_state' => ['status' => $previousStatus], 'after_state' => ['status' => 'reversed']]);

            return $import->fresh();
        });
    }

    private function normalize(array $row, array $input, int $bankAccountId, ?string $inferredDateOrder = null): array
    {
        $m = $input['mapping'];
        $v = $row['values'];
        $get = fn (string $key) => isset($m[$key]) ? trim((string) ($v[$m[$key]] ?? '')) : '';
        $dateFormat = $input['date_format'] ?? null;
        $date = $this->parseTransactionDate($get('transaction_date'), $dateFormat, $inferredDateOrder);
        // BK2: ONE amount parser (leading/trailing signs, DR/CR, parentheses,
        // currency codes/symbols, thousands separators). Anything it cannot
        // read throws UnparsableAmountException — a field-named row error —
        // rather than being coerced to 0 and skipped as an empty row.
        $numeric = fn (string $value, string $field): float => $this->amounts->parse($value, $field);
        $amount = isset($m['amount'])
            ? $numeric($get('amount'), 'amount')
            : $numeric($get('credit'), 'credit') - abs($numeric($get('debit'), 'debit'));
        $description = $get('description');
        if ($description === '') {
            throw new RowFieldException('description', 'DESCRIPTION_MISSING', '', 'Missing description.');
        }
        $reference = $get('bank_reference');
        $running = $get('running_balance');
        $runningBalance = $running === '' ? null : $numeric($running, 'running_balance');
        // BK7: every source row is an independently matchable allocation, but a
        // row's identity is the money movement itself — never the file hash or
        // the row number, which change between overlapping exports. The content
        // key therefore carries account + date + amount + bank reference
        // (+ running balance where the export has one); confirm() appends an
        // occurrence counter for identical same-day rows within one file.
        $contentKey = $this->contentKey(
            $bankAccountId,
            strtoupper($input['currency_code']),
            $date->format('Y-m-d'),
            $amount,
            $reference,
            $runningBalance,
        );

        return ['bank_account_id' => $bankAccountId, 'content_key' => $contentKey, 'source_row' => $row['source_row'], 'value_date' => $get('value_date') ?: null, 'transaction_date' => $date->format('Y-m-d'), 'transaction_time' => $get('transaction_time') ?: null, 'description' => $description, 'bank_reference' => $reference ?: null, 'customer_reference' => $get('customer_reference') ?: null, 'customer_code' => $get('customer_code') ?: null, 'amount' => $amount, 'currency_code' => strtoupper($input['currency_code']), 'running_balance' => $runningBalance, 'status' => 'unmatched', 'matched_amount' => 0, 'residual_amount' => $amount, 'needs_review' => $reference === '', 'raw_data' => $v, 'allocation_reference' => $get('invoice_reference') ?: null, 'allocation_amount' => $get('allocation_amount') === '' ? null : $numeric($get('allocation_amount'), 'allocation_amount')];
    }

    /**
     * BK7: file-independent identity of one money movement.
     */
    private function contentKey(int $bankAccountId, string $currencyCode, string $date, float $amount, string $reference, ?float $runningBalance): string
    {
        return implode('|', [
            $bankAccountId,
            strtoupper($currencyCode),
            $date,
            number_format($amount, 8, '.', ''),
            strtolower(preg_replace('/\s+/', '', $reference)),
            $runningBalance === null ? '' : number_format($runningBalance, 8, '.', ''),
        ]);
    }

    private function transactionKey(string $contentKey, int $occurrence): string
    {
        return hash('sha256', $contentKey.'|'.$occurrence);
    }

    /**
     * BK7: source rows of a preview whose content key already exists as an
     * active transaction for the account. Rows that cannot be normalized or
     * whose date column is still ambiguous are left to confirm() to report.
     */
    private function findExistingRows(array $rows, array $input, int $bankAccountId, ?string $inferredDateOrder): array
    {
        $occurrences = [];
        $computed = [];
        foreach ($rows as $row) {
            try {
                $normal = $this->normalize($row, $input, $bankAccountId, $inferredDateOrder);
            } catch (\Throwable) {
                continue;
            }
            if ((float) $normal['amount'] === 0.0) {
                continue;
            }
            $occurrence = ($occurrences[$normal['content_key']] ?? 0) + 1;
            $occurrences[$normal['content_key']] = $occurrence;
            $computed[$this->transactionKey($normal['content_key'], $occurrence)] = [
                'source_row' => $row['source_row'],
                'transaction_date' => $normal['transaction_date'],
                'amount' => $normal['amount'],
                'description' => $normal['description'],
                'bank_reference' => $normal['bank_reference'],
            ];
        }
        if ($computed === []) {
            return [];
        }
        $existing = array_flip(BankStatementTransaction::where('bank_account_id', $bankAccountId)
            ->where('status', '!=', 'reversed')
            ->whereIn('transaction_key', array_keys($computed))
            ->pluck('transaction_key')
            ->all());
        $duplicates = [];
        foreach ($computed as $key => $info) {
            if (isset($existing[$key])) {
                $duplicates[] = $info;
            }
        }

        return $duplicates;
    }

    private function parseTransactionDate(string $rawDate, ?string $dateFormat, ?string $inferredDateOrder = null): Carbon
    {
        $raw = trim($rawDate);

        try {
            // ISO dates carry a 4-digit year first — never ambiguous.
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}(?:[T ].*)?$/', $raw)) {
                return Carbon::createFromFormat('!Y-m-d', substr($raw, 0, 10));
            }

            // BK1: slash/dot numeric dates must come from the profile or the
            // column inference — Carbon::parse would read them m/d/Y and swap
            // 3/4/2026 (3 April) into 4 March with no warning.
            if (preg_match('#^(\\d{1,2})[/.](\\d{1,2})[/.](\\d{4})$#', $raw)) {
                $pattern = $dateFormat ?: match ($inferredDateOrder) {
                    'd/m' => 'd/m/Y',
                    'm/d' => 'm/d/Y',
                    default => null,
                };
                if ($pattern === null) {
                    throw new RowFieldException('transaction_date', 'AMBIGUOUS_TRANSACTION_DATE', $raw, 'Ambiguous transaction date "'.$raw.'": day/month order unknown — provide the statement date format.');
                }

                // Accept profile spellings (dd/mm/yyyy) and any ./- separators.
                $fmt = str_replace(
                    ['yyyy', 'yy', 'dd', 'mm', 'YYYY', 'YY', 'DD', 'MM'],
                    ['Y', 'y', 'd', 'm', 'Y', 'y', 'd', 'm'],
                    $pattern,
                );
                $fmt = str_replace(['.', '-'], '/', $fmt);
                $rawNormalized = str_replace(['.', '-'], '/', $raw);
                $parts = explode('/', $rawNormalized);
                $parts[0] = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                $parts[1] = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
                $padded = implode('/', $parts);

                $date = Carbon::createFromFormat('!'.$fmt, $padded);
                if ($date === false || $date->format($fmt) !== $padded) {
                    throw new RowFieldException('transaction_date', 'INVALID_TRANSACTION_DATE', $raw, 'Invalid transaction date "'.$raw.'".');
                }

                return $date;
            }

            // Non-numeric formats (e.g. "3 Apr 2026") keep the existing parser —
            // the m/d ambiguity this issue targets only applies to slash/dot dates.
            return Carbon::parse($raw);
        } catch (RowFieldException $e) {
            throw $e;
        } catch (\Throwable) {
            // BK8: an unreadable date is a field-named row error with a safe
            // preview — never a generic "row could not be normalized" message.
            throw new RowFieldException('transaction_date', 'INVALID_TRANSACTION_DATE', $raw, 'Invalid transaction date "'.$raw.'".');
        }
    }

    /**
     * Vote across the WHOLE transaction-date column: any value with a day
     * above 12 settles d/m vs m/d; conflicting votes mean the column mixes
     * orders and nothing can be inferred.
     */
    private function inferSlashDateOrder(array $rows, string $dateColumn): ?string
    {
        if ($dateColumn === '') {
            return null;
        }

        $order = null;
        foreach ($rows as $row) {
            $raw = trim((string) ($row['values'][$dateColumn] ?? ''));
            if (! preg_match('#^(\\d{1,2})[/.](\\d{1,2})[/.](\\d{4})$#', $raw, $m)) {
                continue;
            }
            $a = (int) $m[1];
            $b = (int) $m[2];
            if ($a > 12 && $b > 12) {
                return null;
            }
            $vote = $a > 12 ? 'd/m' : ($b > 12 ? 'm/d' : null);
            if ($vote === null) {
                continue;
            }
            if ($order !== null && $order !== $vote) {
                return null;
            }
            $order = $vote;
        }

        return $order;
    }

    private function hasSlashDate(array $rows, string $dateColumn): bool
    {
        if ($dateColumn === '') {
            return false;
        }
        foreach ($rows as $row) {
            $raw = trim((string) ($row['values'][$dateColumn] ?? ''));
            if ($raw !== '' && preg_match('#^\\d{1,2}[/.]\\d{1,2}[/.]\\d{4}$#', $raw)) {
                return true;
            }
        }

        return false;
    }
}
