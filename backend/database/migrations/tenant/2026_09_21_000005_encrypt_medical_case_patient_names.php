<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issue #159 (CRITICAL): medical_cases.patient_name_encrypted was a bare text
 * column — the `_encrypted` suffix asserted a protection that did not exist.
 * The MedicalCase model now carries Laravel's `encrypted` cast; this migration
 * encrypts the values that were already stored as plaintext.
 *
 * Idempotent: values that already decrypt with the current APP_KEY are left
 * untouched, so re-running (or running after a partial failure) is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('medical_cases')
            || ! DB::getSchemaBuilder()->hasColumn('medical_cases', 'patient_name_encrypted')) {
            return;
        }

        // Raw table access includes soft-deleted rows.
        DB::table('medical_cases')
            ->select('id', 'patient_name_encrypted')
            ->orderBy('id')
            ->chunkById(100, function ($cases) {
                foreach ($cases as $case) {
                    $value = $case->patient_name_encrypted;

                    if ($value === null || $value === '') {
                        continue;
                    }

                    // Already encrypted? Leave it alone.
                    try {
                        Crypt::decryptString($value);

                        continue;
                    } catch (\Throwable) {
                        // Plaintext (or unreadable legacy ciphertext) — encrypt it.
                    }

                    DB::table('medical_cases')
                        ->where('id', $case->id)
                        ->update(['patient_name_encrypted' => Crypt::encryptString($value)]);
                }
            });

        Log::info('Issue #159: medical case patient names encrypted at rest');
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('medical_cases')
            || ! DB::getSchemaBuilder()->hasColumn('medical_cases', 'patient_name_encrypted')) {
            return;
        }

        // Decrypt back to plaintext so the previous (cast-free) code keeps
        // working after a rollback.
        DB::table('medical_cases')
            ->select('id', 'patient_name_encrypted')
            ->orderBy('id')
            ->chunkById(100, function ($cases) {
                foreach ($cases as $case) {
                    $value = $case->patient_name_encrypted;

                    if ($value === null || $value === '') {
                        continue;
                    }

                    try {
                        $plaintext = Crypt::decryptString($value);
                    } catch (\Throwable) {
                        continue;
                    }

                    DB::table('medical_cases')
                        ->where('id', $case->id)
                        ->update(['patient_name_encrypted' => $plaintext]);
                }
            });
    }
};
