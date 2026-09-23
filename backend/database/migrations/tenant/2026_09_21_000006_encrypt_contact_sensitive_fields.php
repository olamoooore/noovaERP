<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Issues #117 / #118 (HIGH): sensitive contact fields were stored plaintext
 * while the sibling ContactTaxProfile.tax_id already used Laravel's encrypted
 * cast.
 *
 *   contact_bank_accounts.account_number / routing_number / swift_code / iban
 *   contact_identifiers.identifier_value
 *
 * This migration:
 *   1. widens those varchar(255) columns to text — an encrypted short value is
 *      ~200-260 chars in Laravel's AES payload, so a 34-char IBAN (256 chars
 *      encrypted) would not fit in 255;
 *   2. encrypts existing plaintext rows in place, idempotently (values that
 *      already decrypt with the current APP_KEY are skipped; null/empty left
 *      alone).
 */
return new class extends Migration
{
    /** @var array<string, string[]> table => columns */
    private const TARGETS = [
        'contact_bank_accounts' => ['account_number', 'routing_number', 'swift_code', 'iban'],
        'contact_identifiers' => ['identifier_value'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // 1. Widen so ciphertext fits.
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE text");

                // 2. Encrypt existing plaintext in place (raw access includes
                //    soft-deleted rows).
                DB::table($table)
                    ->select('id', $column)
                    ->orderBy('id')
                    ->chunkById(100, function ($rows) use ($table, $column) {
                        foreach ($rows as $row) {
                            $value = $row->{$column};

                            if ($value === null || $value === '') {
                                continue;
                            }

                            // Already encrypted? Leave it alone.
                            try {
                                Crypt::decryptString($value);

                                continue;
                            } catch (\Throwable) {
                                // Plaintext — encrypt it.
                            }

                            DB::table($table)
                                ->where('id', $row->id)
                                ->update([$column => Crypt::encryptString($value)]);
                        }
                    });
            }
        }

        Log::info('Issues #117/#118: contact bank/identifier fields encrypted at rest');
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->select('id', $column)
                    ->orderBy('id')
                    ->chunkById(100, function ($rows) use ($table, $column) {
                        foreach ($rows as $row) {
                            $value = $row->{$column};

                            if ($value === null || $value === '') {
                                continue;
                            }

                            try {
                                $plaintext = Crypt::decryptString($value);
                            } catch (\Throwable) {
                                continue;
                            }

                            DB::table($table)
                                ->where('id', $row->id)
                                ->update([$column => $plaintext]);
                        }
                    });
            }
        }
    }
};
