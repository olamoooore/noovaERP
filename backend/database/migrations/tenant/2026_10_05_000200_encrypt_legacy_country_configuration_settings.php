<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `acct_country_configurations.settings` / `api_credentials` are cast as
 * `encrypted:array`, but several tenants hold rows written as plaintext JSON
 * (seeded/imported directly). Reading one throws DecryptException — observed
 * in production: the InvoicePosted → compliance auto-submit listener read the
 * config outside its try/catch, so every invoice POST returned 422
 * "The payload is invalid." after the invoice had already been posted.
 *
 * This migration encrypts those legacy plaintext values exactly once,
 * idempotent: values that already decrypt are left untouched. Mirrors the
 * Contact module's legacy-encryption backfill precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acct_country_configurations')) {
            return;
        }

        DB::table('acct_country_configurations')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                $updates = [];

                foreach (['settings', 'api_credentials'] as $column) {
                    $value = $row->{$column} ?? null;

                    if ($value === null || $value === '' || $this->isEncrypted((string) $value)) {
                        continue;
                    }

                    // encrypted:array = encryptString(json_encode($value))
                    $decoded = json_decode((string) $value, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // Legacy non-JSON value: preserve it as a JSON string.
                        $decoded = (string) $value;
                    }

                    $updates[$column] = Crypt::encryptString(json_encode($decoded));
                }

                if ($updates !== []) {
                    DB::table('acct_country_configurations')->where('id', $row->id)->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately not reverted: re-exposing encrypted provider credentials
        // and settings as plaintext is never a safe rollback.
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
