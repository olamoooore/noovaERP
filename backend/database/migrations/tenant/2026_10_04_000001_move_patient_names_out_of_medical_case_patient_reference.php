<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #568 (SALES-MED P1 / BS7): the CSV import paths wrote the patient's
 * NAME into medical_cases.patient_reference — a plain, searchable column
 * documented as a "non-identifying patient/case reference" — bypassing the
 * encryption added by #159/#222.
 *
 * This migration:
 *   1. moves any value still sitting in patient_reference into the encrypted
 *      patient_name_encrypted column when that column is empty (preserving the
 *      data — where the import paths used to write the name), and
 *   2. replaces patient_reference with the row's own case code, which is
 *      unique and non-identifying (medical-case spec §5.2).
 *
 * Idempotent: a row whose patient_name_encrypted is already populated keeps it,
 * and a re-run only rewrites patient_reference to the same case code. Raw
 * table access includes soft-deleted rows.
 *
 * Note: the plaintext patient_name columns on invoices, quotations and
 * delivery_order_lines were already moved to their encrypted counterparts and
 * dropped by issue #222's migration (2026_09_22_000002).
 *
 * down() is intentionally a no-op: putting plaintext PHI back into the
 * searchable reference column is the vulnerability this fixes. Rollback of the
 * code re-imports names into the encrypted field via CaseImportService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medical_cases')
            || ! Schema::hasColumn('medical_cases', 'patient_reference')
            || ! Schema::hasColumn('medical_cases', 'patient_name_encrypted')) {
            return;
        }

        DB::table('medical_cases')
            ->select('id', 'case_no', 'patient_reference', 'patient_name_encrypted')
            ->whereNotNull('patient_reference')
            ->where('patient_reference', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($cases) {
                foreach ($cases as $case) {
                    $reference = $case->patient_reference;

                    if ($reference === null || $reference === '') {
                        continue;
                    }

                    $update = [];

                    if ($case->patient_name_encrypted === null || $case->patient_name_encrypted === '') {
                        try {
                            // Already a Laravel ciphertext payload → keep it as
                            // is; otherwise encrypt the plaintext reference.
                            Crypt::decryptString($reference);
                            $update['patient_name_encrypted'] = $reference;
                        } catch (\Throwable) {
                            $update['patient_name_encrypted'] = Crypt::encryptString($reference);
                        }
                    }

                    // The reference must not keep holding the name; the case
                    // code is the non-identifying reference the spec intends.
                    $update['patient_reference'] = $case->case_no;

                    DB::table('medical_cases')
                        ->where('id', $case->id)
                        ->update($update);
                }
            });

        Log::info('Issue #568: medical case patient names moved out of patient_reference');
    }

    public function down(): void
    {
        // No-op by design — see class docblock.
    }
};
