<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issues #117/#118: the encrypted casts were added to ContactBankAccount and
 * ContactIdentifier without backfilling existing plaintext rows — reading them
 * through Eloquent would throw DecryptException. This migration encrypts the
 * legacy plaintext values exactly once (idempotent: values that already decrypt
 * are left untouched).
 *
 * Note: encrypted values are non-deterministic, so the
 * contact_identifier_unique index can no longer enforce plaintext uniqueness —
 * the application must dedupe before writing (documented trade-off of the fix).
 */
return new class extends Migration
{
    private const BANK_COLUMNS = ['account_number', 'routing_number', 'swift_code', 'iban'];

    public function up(): void
    {
        if (Schema::hasTable('contact_bank_accounts')) {
            DB::table('contact_bank_accounts')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach (self::BANK_COLUMNS as $column) {
                        $value = $row->{$column} ?? null;
                        if ($value !== null && ! $this->isEncrypted($value)) {
                            $updates[$column] = Crypt::encryptString($value);
                        }
                    }

                    if ($updates !== []) {
                        DB::table('contact_bank_accounts')->where('id', $row->id)->update($updates);
                    }
                }
            });
        }

        if (Schema::hasTable('contact_identifiers')) {
            DB::table('contact_identifiers')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $value = $row->identifier_value ?? null;
                    if ($value !== null && ! $this->isEncrypted($value)) {
                        DB::table('contact_identifiers')
                            ->where('id', $row->id)
                            ->update(['identifier_value' => Crypt::encryptString($value)]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately not reverted: re-exposing encrypted PII as plaintext is
        // never a safe rollback.
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
