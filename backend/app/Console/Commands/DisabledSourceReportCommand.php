<?php

namespace App\Console\Commands;

use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * #812 EP1 / #813 EP2 data remediation: every supplier bill, vendor debit
 * note and journal entry that was submitted to LHDN before those source types
 * were disabled — per tenant, with the LHDN UUID and status — so finance can
 * assess cancellation / remediation at the authority.
 */
class DisabledSourceReportCommand extends Command
{
    protected $signature = 'compliance:disabled-source-report
        {--source=* : Source document types to include (default: bill, vendor_debit_note, journal_entry)}
        {--format=table : Output format: table or json}
        {--cancel-in-window : Attempt LHDN cancellation for documents still inside the cancellation window}
        {--mark-remediation : Write a remediation note on every document that was not cancelled}';

    protected $description = 'Report previously submitted bill / vendor debit note / journal entry compliance documents and optionally remediate them (#812/#813).';

    public function handle(): int
    {
        $sources = array_values(array_filter((array) $this->option('source')))
            ?: ['bill', 'vendor_debit_note', 'journal_entry'];

        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error("Unknown format '{$format}' — use table or json.");

            return self::FAILURE;
        }

        $cancelInWindow = (bool) $this->option('cancel-in-window');
        $markRemediation = (bool) $this->option('mark-remediation');

        $rows = [];

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $documents = ComplianceDocument::query()
                    ->whereIn('document_type', $sources)
                    ->whereHas('submissions')
                    ->with(['submissions' => fn ($query) => $query->orderByDesc('id')])
                    ->orderBy('document_type')
                    ->orderBy('document_number')
                    ->get();

                foreach ($documents as $document) {
                    $latest = $document->submissions->first();
                    $action = null;

                    if ($latest && $cancelInWindow) {
                        // The service enforces LHDN's cancellation window and
                        // the state checks, so an out-of-window document is
                        // refused here and falls through to the note.
                        $result = app(ComplianceService::class)->cancelSubmission(
                            $latest->id,
                            'Data remediation (#812/#813): non-invoice document submitted before that source type was disabled.',
                        );

                        if ($result['success'] ?? false) {
                            $action = 'cancelled';
                            $document->refresh();
                        } else {
                            $action = 'cancel_failed: '.($result['message'] ?? 'unknown error');
                        }
                    }

                    if ($latest && $markRemediation && $action !== 'cancelled') {
                        $note = '(#812/#813 remediation '.now()->toDateString().') '
                            .($cancelInWindow
                                ? 'Could not be cancelled at LHDN (outside the cancellation window or the authority refused); assess manual remediation with the tax agent.'
                                : 'Submitted before purchase-side / journal submission was disabled; assess remediation with the tax agent.');

                        $document->update([
                            'compliance_notes' => trim((string) ($document->compliance_notes ?? '').' '.$note),
                        ]);

                        $action = $action ? $action.'; remediation_noted' : 'remediation_noted';
                    }

                    $rows[] = [
                        'tenant_id' => $tenant->id,
                        'document_type' => $document->document_type,
                        'document_number' => $document->document_number,
                        'compliance_status' => $document->compliance_status,
                        'submission_status' => $latest?->submission_status,
                        'external_document_uuid' => $latest?->external_document_uuid,
                        'provider_reference' => $latest?->provider_reference,
                        'submitted_at' => optional($latest?->submitted_at)->toIso8601String(),
                        'remediation_action' => $action,
                    ];
                }
            } catch (\Throwable $e) {
                // One tenant failing must not stop the sweep.
                Log::warning('Disabled-source compliance report failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                tenancy()->end();
            }
        }

        if ($format === 'json') {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No previously submitted bill, vendor debit note or journal entry was found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tenant', 'Type', 'Number', 'Compliance', 'Submission', 'LHDN UUID', 'Reference', 'Submitted', 'Action'],
            array_map(static fn (array $row) => array_values($row), $rows)
        );

        $this->info(count($rows).' previously submitted non-invoice document(s) found.');

        return self::SUCCESS;
    }
}
