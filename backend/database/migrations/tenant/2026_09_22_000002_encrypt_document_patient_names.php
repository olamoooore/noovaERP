<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #222 (regression from the #159 fix): the patient name PHI that was
 * encrypted on medical_cases is stored in cleartext on invoices, quotations
 * and delivery_order_lines. Move each `patient_name` value into a new
 * `patient_name_encrypted` column (encrypted at rest) and drop the plaintext
 * column.
 *
 * Idempotent: values that already decrypt with the current APP_KEY are left
 * untouched, so a re-run after a partial failure is safe.
 */
return new class extends Migration
{
    private const TABLES = ['invoices', 'quotations', 'delivery_order_lines'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'patient_name')) {
                continue;
            }

            if (! Schema::hasColumn($table, 'patient_name_encrypted')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->text('patient_name_encrypted')->nullable();
                });
            }

            DB::table($table)
                ->select('id', 'patient_name')
                ->whereNotNull('patient_name')
                ->where('patient_name', '!=', '')
                ->chunkById(100, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        $value = $row->patient_name;

                        try {
                            Crypt::decryptString($value);

                            continue;
                        } catch (\Throwable) {
                            // Plaintext (or unreadable legacy ciphertext) — encrypt it.
                        }

                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['patient_name_encrypted' => Crypt::encryptString($value)]);
                    }
                });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('patient_name');
            });
        }

        Log::info('Issue #222: document patient names encrypted at rest');
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'patient_name_encrypted')) {
                continue;
            }

            if (! Schema::hasColumn($table, 'patient_name')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->text('patient_name')->nullable();
                });
            }

            DB::table($table)
                ->select('id', 'patient_name_encrypted')
                ->whereNotNull('patient_name_encrypted')
                ->where('patient_name_encrypted', '!=', '')
                ->chunkById(100, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        $value = $row->patient_name_encrypted;

                        try {
                            $plain = Crypt::decryptString($value);
                        } catch (\Throwable) {
                            $plain = $value;
                        }

                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['patient_name' => $plain]);
                    }
                });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('patient_name_encrypted');
            });
        }
    }
};
