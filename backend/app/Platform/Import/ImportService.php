<?php

namespace App\Platform\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Platform Import Service
 *
 * Reusable CSV import across all modules.
 * Handles parsing, column mapping, validation, row-by-row insert.
 */
class ImportService
{
    /**
     * Import CSV data into a table.
     *
     * @param string $table Target table
     * @param UploadedFile $file The CSV file
     * @param array $columnMap CSV header => DB column mapping
     * @param array $options Additional options
     * @return array{created: int, updated: int, errors: array}
     */
    public function importCSV(string $table, UploadedFile $file, array $columnMap, array $options = []): array
    {
        $tenantId = $options['tenant_id'] ?? tenant()?->id;
        $userId = $options['user_id'] ?? null;
        $upsert = $options['upsert'] ?? false;
        $upsertKey = $options['upsert_key'] ?? 'id';
        $batchSize = $options['batch_size'] ?? 100;
        $rules = $options['rules'] ?? [];
        $transform = $options['transform'] ?? null;

        $created = 0;
        $updated = 0;
        $errors = [];
        $rows = [];

        // Parse CSV
        $handle = fopen($file->getPathname(), 'r');
        if (!$handle) {
            return ['created' => 0, 'updated' => 0, 'errors' => [['row' => 0, 'message' => 'Failed to open file']]];
        }

        // Read headers
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return ['created' => 0, 'updated' => 0, 'errors' => [['row' => 0, 'message' => 'Empty or invalid CSV file']]];
        }

        // Normalize headers and map to DB columns
        $normalizedHeaders = array_map(fn($h) => strtolower(trim(str_replace(' ', '_', $h))), $headers);
        $mappedColumns = [];
        foreach ($normalizedHeaders as $idx => $header) {
            if (isset($columnMap[$header])) {
                $mappedColumns[$idx] = $columnMap[$header];
            }
        }

        // Read rows
        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            if (empty(array_filter($row))) {
                continue; // Skip empty rows
            }

            $record = [];
            foreach ($mappedColumns as $idx => $dbColumn) {
                $value = $row[$idx] ?? null;
                $record[$dbColumn] = $this->cleanValue($value);
            }

            // Apply transform if provided
            if ($transform && is_callable($transform)) {
                $record = $transform($record, $rowNum);
                if ($record === null) {
                    continue; // Transform returned null = skip row
                }
            }

            // Add tenant_id if table supports it
            if ($tenantId && $this->hasColumn($table, 'tenant_id')) {
                $record['tenant_id'] = $tenantId;
            }

            // Add created_by if table supports it
            if ($userId && $this->hasColumn($table, 'created_by')) {
                $record['created_by'] = $userId;
            }

            // Validate
            $validationErrors = $this->validateRow($record, $rules, $rowNum);
            if (!empty($validationErrors)) {
                $errors = array_merge($errors, $validationErrors);
                continue;
            }

            $rows[] = ['data' => $record, 'row' => $rowNum];
        }
        fclose($handle);

        // Process rows in batches
        foreach (array_chunk($rows, $batchSize) as $batch) {
            DB::beginTransaction();
            try {
                foreach ($batch as $item) {
                    $record = $item['data'];
                    $rowNum = $item['row'];

                    if ($upsert && isset($record[$upsertKey])) {
                        $existing = DB::table($table)
                            ->where($upsertKey, $record[$upsertKey])
                            ->when($tenantId && $this->hasColumn($table, 'tenant_id'), fn($q) => $q->where('tenant_id', $tenantId))
                            ->first();

                        if ($existing) {
                            DB::table($table)->where('id', $existing->id)->update($record);
                            $updated++;
                        } else {
                            DB::table($table)->insert($record);
                            $created++;
                        }
                    } else {
                        DB::table($table)->insert($record);
                        $created++;
                    }
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $errors[] = ['row' => 0, 'message' => 'Batch processing error: ' . $e->getMessage()];
            }
        }

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * Import CSV with row grouping (e.g., invoice_number groups lines).
     */
    public function importGroupedCSV(
        string $table,
        string $lineTable,
        UploadedFile $file,
        array $columnMap,
        array $options = []
    ): array {
        $tenantId = $options['tenant_id'] ?? tenant()?->id;
        $userId = $options['user_id'] ?? null;
        $groupKey = $options['group_key'] ?? null; // CSV column that groups rows
        $lineColumnMap = $options['line_column_map'] ?? [];
        $transformHeader = $options['transform_header'] ?? null;
        $transformLine = $options['transform_line'] ?? null;

        $created = 0;
        $errors = [];

        $handle = fopen($file->getPathname(), 'r');
        if (!$handle) {
            return ['created' => 0, 'errors' => [['row' => 0, 'message' => 'Failed to open file']]];
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return ['created' => 0, 'errors' => [['row' => 0, 'message' => 'Empty CSV']]];
        }

        $normalizedHeaders = array_map(fn($h) => strtolower(trim(str_replace(' ', '_', $h))), $headers);

        // Group rows by groupKey
        $groups = [];
        $currentGroup = null;
        $currentLines = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (empty(array_filter($row))) continue;

            $rowData = [];
            foreach ($normalizedHeaders as $idx => $header) {
                $rowData[$header] = $row[$idx] ?? null;
            }

            $groupId = $groupKey ? ($rowData[$groupKey] ?? null) : 'single_' . $rowNum;

            if ($groupId && $groupId !== $currentGroup) {
                if ($currentGroup !== null) {
                    $groups[$currentGroup] = $currentLines;
                }
                $currentGroup = $groupId;
                $currentLines = [$rowData];
            } else {
                $currentLines[] = $rowData;
            }
        }
        if ($currentGroup !== null) {
            $groups[$currentGroup] = $currentLines;
        }
        fclose($handle);

        // Process each group
        foreach ($groups as $groupId => $rows) {
            DB::beginTransaction();
            try {
                // Build header record from first row
                $headerData = [];
                foreach ($columnMap as $csvCol => $dbCol) {
                    if (isset($rows[0][$csvCol]) && $rows[0][$csvCol] !== '') {
                        $headerData[$dbCol] = $this->cleanValue($rows[0][$csvCol]);
                    }
                }

                if ($transformHeader && is_callable($transformHeader)) {
                    $headerData = $transformHeader($headerData, $rows);
                    if ($headerData === null) { DB::rollBack(); continue; }
                }

                if ($tenantId && $this->hasColumn($table, 'tenant_id')) {
                    $headerData['tenant_id'] = $tenantId;
                }
                if ($userId && $this->hasColumn($table, 'created_by')) {
                    $headerData['created_by'] = $userId;
                }
                if ($this->hasColumn($table, 'status')) {
                    $headerData['status'] = $headerData['status'] ?? 'draft';
                }

                $headerId = DB::table($table)->insertGetId($headerData);

                // Build line records
                foreach ($rows as $idx => $row) {
                    $lineData = [$lineColumnMap['header_id'] ?? str_replace('_s', '', $table) . '_id' => $headerId];
                    foreach ($lineColumnMap['map'] ?? [] as $csvCol => $dbCol) {
                        if (isset($row[$csvCol]) && $row[$csvCol] !== '') {
                            $lineData[$dbCol] = $this->cleanValue($row[$csvCol]);
                        }
                    }

                    if ($transformLine && is_callable($transformLine)) {
                        $lineData = $transformLine($lineData, $row, $idx);
                        if ($lineData === null) { continue; }
                    }

                    if ($tenantId && $this->hasColumn($lineTable, 'tenant_id')) {
                        $lineData['tenant_id'] = $tenantId;
                    }

                    DB::table($lineTable)->insert($lineData);
                }

                $created++;
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $errors[] = ['group' => $groupId, 'message' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    /**
     * Validate a single row.
     */
    private function validateRow(array $record, array $rules, int $rowNum): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $record[$field] ?? null;

            if (is_array($rule)) {
                foreach ($rule as $ruleName => $ruleParam) {
                    $error = $this->applyRule($field, $value, $ruleName, $ruleParam);
                    if ($error) {
                        $errors[] = ['row' => $rowNum, 'field' => $field, 'message' => $error];
                    }
                }
            } elseif (is_string($rule)) {
                $error = $this->applyRule($field, $value, $rule, null);
                if ($error) {
                    $errors[] = ['row' => $rowNum, 'field' => $field, 'message' => $error];
                }
            }
        }

        return $errors;
    }

    /**
     * Apply a validation rule.
     */
    private function applyRule(string $field, mixed $value, string $rule, mixed $param): ?string
    {
        return match ($rule) {
            'required' => empty($value) && $value !== '0' ? "{$field} is required" : null,
            'numeric' => $value !== null && !is_numeric($value) ? "{$field} must be numeric" : null,
            'email' => $value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL) ? "{$field} must be a valid email" : null,
            'date' => $value !== null && !strtotime($value) ? "{$field} must be a valid date" : null,
            'max' => $value !== null && strlen($value) > $param ? "{$field} must not exceed {$param} characters" : null,
            'min' => $value !== null && strlen($value) < $param ? "{$field} must be at least {$param} characters" : null,
            'in' => $value !== null && !in_array($value, is_array($param) ? $param : explode(',', $param)) ? "{$field} must be one of: {$param}" : null,
            default => null,
        };
    }

    /**
     * Clean a CSV value.
     */
    private function cleanValue(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Check if a column exists in a table.
     */
    private function hasColumn(string $table, string $column): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Download a CSV template.
     */
    public function downloadTemplate(array $columns, string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $headers = ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$filename}\""];

        return response()->stream(function () use ($columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column($columns, 'name'));
            // Add example row
            fputcsv($handle, array_column($columns, 'example', 'name'));
            fclose($handle);
        }, 200, $headers);
    }
}
