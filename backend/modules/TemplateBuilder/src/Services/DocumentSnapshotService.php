<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Models\GeneratedDocument;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;

/**
 * Issue #613 (TP3) — lifecycle entry point for immutable document snapshots.
 *
 * Called after a document becomes issued (invoice posted, receipt posted,
 * delivery order confirmed). The ledger/document state is already durable by
 * then, so a render or storage failure MUST NOT be mistaken for a posting
 * failure: it is reported through the exception handler (visible in logs)
 * and the snapshot is retried by the next lifecycle trigger — mirroring the
 * post-commit compliance auto-submit contract.
 */
final class DocumentSnapshotService
{
    public function __construct(
        private readonly DataSourceRegistry $dataSources,
        private readonly PdfGenerationServiceContract $pdf,
    ) {}

    public function snapshot(string $documentType, string $documentId): ?GeneratedDocument
    {
        // Types without a render path (no data source registered) cannot be
        // snapshotted; they are not errors — there is simply nothing that
        // produces an issued file for them.
        if (! $this->dataSources->has($documentType)) {
            return null;
        }

        try {
            return $this->pdf->snapshot($documentType, $documentId);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
