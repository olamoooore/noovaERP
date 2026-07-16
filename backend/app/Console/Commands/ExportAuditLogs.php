<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class ExportAuditLogs extends Command
{
    protected $signature = 'audit:export
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}
                            {--operation= : Filter by operation type}
                            {--actor= : Filter by actor ID}
                            {--format=csv : Export format (csv|json)}
                            {--output= : Output file path}';

    protected $description = 'Export audit logs for compliance and reporting';

    public function handle()
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $operation = $this->option('operation');
        $actorId = $this->option('actor');
        $format = $this->option('format');
        $output = $this->option('output');

        $this->info('Exporting audit logs...');

        $query = AuditLog::query();

        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        if ($to) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        if ($operation) {
            $query->where('operation', $operation);
        }

        if ($actorId) {
            $query->where('actor_id', $actorId);
        }

        $logs = $query->orderBy('created_at')->get();

        if ($logs->isEmpty()) {
            $this->warn('No audit logs found matching the criteria.');

            return 0;
        }

        $this->info("Found {$logs->count()} audit log entries.");

        $filename = $output ?? 'audit_logs_'.now()->format('Y-m-d_His').'.'.$format;

        if ($format === 'csv') {
            $this->exportCsv($logs, $filename);
        } else {
            $this->exportJson($logs, $filename);
        }

        $this->info("Audit logs exported to: {$filename}");

        return 0;
    }

    protected function exportCsv($logs, $filename)
    {
        $handle = fopen($filename, 'w');

        // Write header
        fputcsv($handle, [
            'ID',
            'Tenant ID',
            'Actor ID',
            'Terminal ID',
            'Session ID',
            'Operation',
            'Reference Type',
            'Reference ID',
            'Source Route',
            'Idempotency Key',
            'Metadata',
            'Created At',
        ]);

        // Write data
        foreach ($logs as $log) {
            fputcsv($handle, [
                $log->id,
                $log->tenant_id,
                $log->actor_id,
                $log->terminal_id,
                $log->session_id,
                $log->operation,
                $log->reference_type,
                $log->reference_id,
                $log->source_route,
                $log->idempotency_key,
                json_encode($log->metadata),
                $log->created_at->toIso8601String(),
            ]);
        }

        fclose($handle);
    }

    protected function exportJson($logs, $filename)
    {
        $data = $logs->map(function ($log) {
            return [
                'id' => $log->id,
                'tenant_id' => $log->tenant_id,
                'actor_id' => $log->actor_id,
                'terminal_id' => $log->terminal_id,
                'session_id' => $log->session_id,
                'operation' => $log->operation,
                'reference_type' => $log->reference_type,
                'reference_id' => $log->reference_id,
                'source_route' => $log->source_route,
                'idempotency_key' => $log->idempotency_key,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ];
        });

        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
    }
}
