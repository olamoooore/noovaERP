<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pillar 2 Part 3 §1.3: encrypt sensitive tax, bank, and payroll values at
 * rest. Existing Eloquent APIs retain their logical field names; only the
 * physical storage representation changes to text ciphertext.
 *
 * The migration is intentionally tenant-scoped. Every affected table is
 * created under a tenant migration path and must be converted inside each
 * tenant schema. It fails closed if an existing value cannot be encrypted.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private array $fields = [
        'contact_tax_profiles' => [
            'tax_id' => 'text',
        ],
        'hr_staff_profiles' => [
            'salary' => 'text',
            'bank_details' => 'text',
        ],
        'hrm_contracts' => [
            'base_monthly_salary' => 'text',
        ],
        'hrm_payrolls' => [
            'base_monthly_salary' => 'text',
            'net_pay' => 'text',
        ],
        'hrm_payroll_runs' => [
            'total_gross' => 'text',
            'total_deductions' => 'text',
            'total_net' => 'text',
        ],
    ];

    public function up(): void
    {
        foreach ($this->fields as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $type) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // Ciphertext is longer than the original varchar/decimal/json
                // types. PostgreSQL text conversion preserves the plaintext
                // bytes until the row-by-row encryption below.
                $dataType = DB::scalar(
                    'SELECT data_type FROM information_schema.columns
                     WHERE table_name = ? AND column_name = ? LIMIT 1',
                    [$table, $column]
                );
                if ($dataType !== 'text') {
                    DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE text USING \"{$column}\"::text");
                }

                DB::table($table)
                    ->whereNotNull($column)
                    ->select(['id', $column])
                    ->orderBy('id')
                    ->chunkById(500, function ($rows) use ($table, $column) {
                        foreach ($rows as $row) {
                            $value = $row->{$column};
                            if ($value === null || $value === '') {
                                continue;
                            }

                            // Idempotence for an interrupted/manual rerun:
                            // leave values that already decrypt successfully.
                            try {
                                Crypt::decryptString((string) $value);

                                continue;
                            } catch (\Throwable) {
                                // Legacy plaintext; encrypt below.
                            }

                            $plaintext = in_array($column, ['bank_details', 'tax_id'], true)
                                ? (string) $value
                                : number_format((float) $value, 2, '.', '');

                            DB::table($table)
                                ->where('id', $row->id)
                                ->update([$column => Crypt::encryptString($plaintext)]);
                        }
                    }, 'id');
            }
        }
    }

    public function down(): void
    {
        $types = [
            'contact_tax_profiles' => ['tax_id' => 'varchar(255)'],
            'hr_staff_profiles' => ['salary' => 'numeric(15,2)', 'bank_details' => 'json'],
            'hrm_contracts' => ['base_monthly_salary' => 'numeric(12,2)'],
            'hrm_payrolls' => ['base_monthly_salary' => 'numeric(12,2)', 'net_pay' => 'numeric(12,2)'],
            'hrm_payroll_runs' => ['total_gross' => 'numeric(12,2)', 'total_deductions' => 'numeric(12,2)', 'total_net' => 'numeric(12,2)'],
        ];

        foreach ($types as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $type) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->whereNotNull($column)
                    ->select(['id', $column])
                    ->orderBy('id')
                    ->chunkById(500, function ($rows) use ($table, $column) {
                        foreach ($rows as $row) {
                            $plaintext = Crypt::decryptString((string) $row->{$column});
                            DB::table($table)
                                ->where('id', $row->id)
                                ->update([$column => $plaintext]);
                        }
                    }, 'id');

                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE {$type} USING \"{$column}\"::{$type}");
            }
        }
    }
};
