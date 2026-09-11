<?php

namespace App\Services\BankStatements;

use App\Models\BankAccount;
use App\Models\BankStatementAllocation;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BankStatementImportService
{
    public function __construct(private BankStatementParser $parser) {}

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
        $token = Str::random(64);
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($file->getClientOriginalName()));
        Cache::put('bank-statement-preview:'.$token, ['tenant_id' => $tenantId, 'user_id' => $userId, 'bank_account_id' => $bankAccountId, 'path' => $path, 'type' => $type, 'hash' => $hash, 'file_name' => $safeName, 'header_row' => $headerRow, 'worksheet' => $parsed['worksheet']], now()->addHour());

        return ['preview_token' => $token, 'file_hash' => $hash, 'headers' => $parsed['headers'], 'proposed_mapping' => $this->parser->proposeMapping($parsed['headers']), 'source_rows' => count($parsed['rows']), 'sample' => array_slice($parsed['rows'], 0, 100)];
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

        return DB::transaction(function () use ($input, $userId, $preview, $parsed, $key) {
            $import = BankStatementImport::create([
                'bank_account_id' => $preview['bank_account_id'], 'file_name' => $preview['file_name'], 'file_hash' => $preview['hash'],
                'storage_path' => $preview['path'], 'file_type' => $preview['type'], 'status' => 'importing',
                'statement_start_date' => $input['statement_start_date'] ?? null, 'statement_end_date' => $input['statement_end_date'],
                'opening_balance' => $input['opening_balance'] ?? null, 'closing_balance' => $input['closing_balance'],
                'currency_code' => strtoupper($input['currency_code']), 'row_count' => count($parsed['rows']), 'mapping' => $input['mapping'],
                'metadata' => ['worksheet' => $parsed['worksheet'], 'header_row' => $preview['header_row'], 'parser_version' => 1], 'imported_by' => $userId,
            ]);
            $groups = [];
            $errors = 0;
            foreach ($parsed['rows'] as $row) {
                try {
                    $normal = $this->normalize($row, $input, $preview['bank_account_id']);
                } catch (\Throwable) {
                    $errors++;

                    continue;
                }
                if ((float) $normal['amount'] === 0.0) {
                    continue;
                }
                $groups[$normal['transaction_key']] ??= ['transaction' => $normal, 'allocations' => []];
                if ($normal['allocation_reference']) {
                    $groups[$normal['transaction_key']]['allocations'][] = $normal;
                }
            }
            $created = 0;
            $duplicates = 0;
            foreach ($groups as $group) {
                $data = $group['transaction'];
                $allocationReference = $data['allocation_reference'];
                $allocationAmount = $data['allocation_amount'];
                unset($data['allocation_reference'], $data['allocation_amount']);
                $transaction = BankStatementTransaction::where('bank_account_id', $preview['bank_account_id'])->where('transaction_key', $data['transaction_key'])->where('status', '!=', 'reversed')->first();
                if ($transaction) {
                    $duplicates++;

                    continue;
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
            $import->update(['status' => $errors ? 'failed' : 'imported', 'transaction_count' => $created, 'duplicate_count' => $duplicates, 'error_count' => $errors, 'imported_at' => $errors ? null : now()]);
            Cache::forget($key);

            return $import->fresh();
        });
    }

    private function normalize(array $row, array $input, int $bankAccountId): array
    {
        $m = $input['mapping'];
        $v = $row['values'];
        $get = fn (string $key) => isset($m[$key]) ? trim((string) ($v[$m[$key]] ?? '')) : '';
        $dateFormat = $input['date_format'] ?? null;
        $date = $dateFormat ? Carbon::createFromFormat($dateFormat, $get('transaction_date')) : Carbon::parse($get('transaction_date'));
        $numeric = fn ($value) => (float) str_replace([',', ' '], '', (string) $value);
        $amount = isset($m['amount']) ? $numeric($get('amount')) : $numeric($get('deposit')) - abs($numeric($get('withdrawal')));
        $description = $get('description');
        if ($description === '') {
            throw new \InvalidArgumentException('Missing description.');
        }
        $reference = $get('bank_reference');
        $running = $get('running_balance');
        $canonical = implode('|', [$bankAccountId, strtoupper($input['currency_code']), $date->format('Y-m-d'), number_format($amount, 8, '.', ''), strtolower(preg_replace('/\s+/', '', $reference)), strtolower(preg_replace('/\s+/', ' ', $description)), $running]);

        return ['bank_account_id' => $bankAccountId, 'transaction_key' => hash('sha256', $canonical), 'source_row' => $row['source_row'], 'value_date' => $get('value_date') ?: null, 'transaction_date' => $date->format('Y-m-d'), 'transaction_time' => $get('transaction_time') ?: null, 'description' => $description, 'bank_reference' => $reference ?: null, 'customer_reference' => $get('customer_reference') ?: null, 'customer_code' => $get('customer_code') ?: null, 'amount' => $amount, 'currency_code' => strtoupper($input['currency_code']), 'running_balance' => $running === '' ? null : $numeric($running), 'status' => 'unmatched', 'matched_amount' => 0, 'residual_amount' => $amount, 'needs_review' => $reference === '', 'raw_data' => $v, 'allocation_reference' => $get('invoice_reference') ?: null, 'allocation_amount' => $get('allocation_amount') === '' ? null : $numeric($get('allocation_amount'))];
    }
}
