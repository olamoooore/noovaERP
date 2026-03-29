<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Staged import pipeline: upload → parse → validate → stage → review → map → preview → commit.
 * Enforces compliance-before-import: country pack must be selected, mandatory roles assigned.
 */
class MigrationWorkbench
{
    private CountryPackRegistry $countryPacks;

    public function __construct(CountryPackRegistry $countryPacks)
    {
        $this->countryPacks = $countryPacks;
    }

    /**
     * Create a new migration job.
     */
    public function createJob(string $type, ?int $sessionId = null, ?int $createdBy = null, ?int $legalEntityId = null): int
    {
        $this->validatePrerequisites();

        return DB::table('migration_jobs')->insertGetId([
            'session_id'      => $sessionId,
            'type'            => $type,
            'status'          => 'pending',
            'created_by'      => $createdBy,
            'legal_entity_id' => $legalEntityId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * Parse uploaded data and create batches.
     */
    public function parseAndStage(int $jobId, array $rows, int $batchSize = 100): array
    {
        $this->updateJobStatus($jobId, 'in_progress');

        $batches = array_chunk($rows, $batchSize);
        $batchIds = [];

        foreach ($batches as $index => $batchRows) {
            $batchId = DB::table('migration_batches')->insertGetId([
                'job_id'       => $jobId,
                'batch_number' => $index + 1,
                'record_count' => count($batchRows),
                'status'       => 'pending',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $batchIds[] = $batchId;
        }

        return $batchIds;
    }

    /**
     * Validate a batch of records. Returns validation results.
     */
    public function validateBatch(int $batchId, array $rows, array $rules): array
    {
        $errors = [];
        $valid = [];

        foreach ($rows as $index => $row) {
            $validator = Validator::make($row, $rules);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $field = array_key_first($validator->failed());
                    DB::table('migration_exceptions')->insert([
                        'batch_id'      => $batchId,
                        'row_number'    => $index + 1,
                        'field'         => $field,
                        'error_type'    => 'validation',
                        'error_message' => $message,
                        'raw_data'      => json_encode($row),
                        'resolution'    => 'pending',
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                    $errors[] = ['row' => $index + 1, 'message' => $message];
                }
            } else {
                $valid[] = $row;
            }
        }

        DB::table('migration_batches')
            ->where('id', $batchId)
            ->update([
                'success_count' => count($valid),
                'error_count'   => count($errors),
                'status'        => count($errors) > 0 ? 'completed' : 'completed',
                'updated_at'    => now(),
            ]);

        return [
            'valid_count' => count($valid),
            'error_count' => count($errors),
            'errors'      => $errors,
            'valid_rows'  => $valid,
        ];
    }

    /**
     * Preview what a commit would do (dry run).
     */
    public function preview(int $jobId): array
    {
        $job = DB::table('migration_jobs')->find($jobId);
        if (!$job) {
            throw new \RuntimeException("Migration job {$jobId} not found.");
        }

        $batches = DB::table('migration_batches')
            ->where('job_id', $jobId)
            ->get();

        $totalRecords = $batches->sum('record_count');
        $totalSuccess = $batches->sum('success_count');
        $totalErrors  = $batches->sum('error_count');

        $unresolvedExceptions = DB::table('migration_exceptions')
            ->whereIn('batch_id', $batches->pluck('id'))
            ->where('resolution', 'pending')
            ->count();

        return [
            'job_id'                => $jobId,
            'type'                  => $job->type,
            'total_records'         => $totalRecords,
            'valid_records'         => $totalSuccess,
            'error_records'         => $totalErrors,
            'unresolved_exceptions' => $unresolvedExceptions,
            'can_commit'            => $unresolvedExceptions === 0,
        ];
    }

    /**
     * Commit validated data. Only proceeds if no unresolved exceptions.
     */
    public function commit(int $jobId, callable $commitCallback): array
    {
        $preview = $this->preview($jobId);

        if (!$preview['can_commit']) {
            throw new \RuntimeException(
                "Cannot commit: {$preview['unresolved_exceptions']} unresolved exception(s)."
            );
        }

        $result = DB::transaction(function () use ($jobId, $commitCallback) {
            return $commitCallback($jobId);
        });

        $this->updateJobStatus($jobId, 'completed');

        return [
            'job_id' => $jobId,
            'status' => 'committed',
            'result' => $result,
        ];
    }

    /**
     * Resolve an exception (fix, skip, or manual override).
     */
    public function resolveException(int $exceptionId, string $resolution, ?int $resolvedBy = null): void
    {
        if (!in_array($resolution, ['resolved', 'skipped'])) {
            throw new \InvalidArgumentException("Resolution must be 'resolved' or 'skipped'.");
        }

        DB::table('migration_exceptions')
            ->where('id', $exceptionId)
            ->update([
                'resolution'  => $resolution,
                'resolved_by' => $resolvedBy,
                'updated_at'  => now(),
            ]);
    }

    /**
     * Get exceptions for a job, optionally filtered.
     */
    public function getExceptions(int $jobId, ?string $resolution = null, ?string $errorType = null): \Illuminate\Support\Collection
    {
        $batchIds = DB::table('migration_batches')
            ->where('job_id', $jobId)
            ->pluck('id');

        $query = DB::table('migration_exceptions')
            ->whereIn('batch_id', $batchIds);

        if ($resolution) {
            $query->where('resolution', $resolution);
        }
        if ($errorType) {
            $query->where('error_type', $errorType);
        }

        return $query->orderBy('row_number')->get();
    }

    /**
     * Enforce compliance-before-import: country pack selected, mandatory roles assigned.
     */
    private function validatePrerequisites(): void
    {
        // Check company_settings has a country_pack assigned
        $settings = DB::table('company_settings')->first();

        if (!$settings || empty($settings->country ?? null)) {
            throw new \RuntimeException(
                'Cannot start import: country pack must be selected in company settings.'
            );
        }

        // Check mandatory account roles are assigned
        $unassigned = DB::table('account_roles')
            ->where('is_mandatory', true)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('entity_account_roles')
                  ->whereColumn('entity_account_roles.role_id', 'account_roles.id')
                  ->where('entity_account_roles.is_active', true);
            })
            ->count();

        if ($unassigned > 0) {
            throw new \RuntimeException(
                "Cannot start import: {$unassigned} mandatory account role(s) not assigned."
            );
        }
    }

    private function updateJobStatus(int $jobId, string $status): void
    {
        $update = ['status' => $status, 'updated_at' => now()];

        if ($status === 'in_progress') {
            $update['started_at'] = now();
        } elseif (in_array($status, ['completed', 'failed'])) {
            $update['completed_at'] = now();
        }

        DB::table('migration_jobs')->where('id', $jobId)->update($update);
    }
}
