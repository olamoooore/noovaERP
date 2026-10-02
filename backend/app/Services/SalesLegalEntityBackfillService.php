<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #875 (P0, SA1) backfill — recorded decision 2026-10-02:
 *
 *  - Single-entity tenants: existing Sales documents and journal entries are
 *    stamped automatically (this runs from the #875 tenant migration).
 *  - Multi-entity tenants: nothing is written automatically. mappingReport()
 *    produces the read-only document → proposed entity mapping (with the
 *    reason), unmatched rows stay blank and are listed, and the client
 *    approves the mapping before anything is applied.
 *
 * The service works inside the active tenant schema (search_path); the artisan
 * command wraps it in a per-tenant iteration.
 */
class SalesLegalEntityBackfillService
{
    /**
     * Sales source document types whose journal entries carry the entity.
     */
    public const SALES_SOURCE_TYPES = [
        'invoice',
        'payment',
        'credit_note',
        'payment_refund',
        'delivery_order',
    ];

    public function __construct(
        private readonly LegalEntityContextResolver $entityResolver,
    ) {}

    public function singleActiveEntityId(): ?int
    {
        return $this->entityResolver->singleActiveEntityId();
    }

    /**
     * Stamp existing Sales documents and journal entries with the tenant's
     * only active entity. No-op (and reports applied = false) when the tenant
     * has zero or several active entities.
     *
     * @return array{applied: bool, entity_id: int|null, documents: int, journal_entries: int}
     */
    public function applySingleEntityBackfill(): array
    {
        $entityId = $this->singleActiveEntityId();

        if ($entityId === null) {
            return [
                'applied' => false,
                'entity_id' => null,
                'documents' => 0,
                'journal_entries' => 0,
            ];
        }

        $bookId = $this->entityResolver->resolveBookId($entityId);

        $documents = 0;
        foreach ($this->salesDocumentTables() as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
                continue;
            }

            $documents += DB::table($table)
                ->whereNull('legal_entity_id')
                ->update([
                    'legal_entity_id' => $entityId,
                    'entity_book_id' => $bookId,
                ]);
        }

        $salesEntryIds = $this->unmappedSalesJournalEntryIds();

        $journalEntries = 0;
        if ($salesEntryIds !== []) {
            $journalEntries = DB::table('journal_entries')
                ->whereIn('id', $salesEntryIds)
                ->update([
                    'legal_entity_id' => $entityId,
                    'entity_book_id' => $bookId,
                ]);

            // Reversal entries mirror the entity of the entry they cancel
            // (ReversalCorrectionEngine copies it on new reversals; legacy
            // reversal rows that predate this migration are stamped here).
            $journalEntries += DB::table('journal_entries')
                ->whereNull('legal_entity_id')
                ->whereIn('reversed_entry_id', $salesEntryIds)
                ->update([
                    'legal_entity_id' => $entityId,
                    'entity_book_id' => $bookId,
                ]);
        }

        return [
            'applied' => true,
            'entity_id' => $entityId,
            'documents' => $documents,
            'journal_entries' => $journalEntries,
        ];
    }

    /**
     * The read-only mapping report: every unmapped Sales document and journal
     * entry, with the proposed entity + book and the reason for the proposal.
     *
     * @return array{
     *     tenant_id: string|null,
     *     active_entity_ids: int[],
     *     single_entity: bool,
     *     proposed: array<string, array<string, mixed>>,
     *     unmatched: array<string, array<string, mixed>>,
     *     counts: array{proposed: int, unmatched: int}
     * }
     */
    public function mappingReport(): array
    {
        $proposed = [];
        $unmatched = [];

        foreach (['invoices', 'payments', 'customer_credit_notes', 'refund_transactions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
                continue;
            }

            $type = match ($table) {
                'invoices' => 'invoice',
                'payments' => 'payment',
                'customer_credit_notes' => 'credit_note',
                'refund_transactions' => 'payment_refund',
            };

            foreach (DB::table($table)->whereNull('legal_entity_id')->orderBy('id')->cursor() as $document) {
                $row = $this->buildRow($type, (int) $document->id, $document);

                if ($row['proposed_entity_id'] !== null) {
                    $proposed[$row['key']] = $row;
                } else {
                    $proposed[$row['key']] = $row;
                    $unmatched[$row['key']] = $row;
                }
            }
        }

        foreach ($this->unmappedSalesJournalEntries() as $entry) {
            $row = $this->buildJournalEntryRow($entry);

            if ($row['proposed_entity_id'] !== null) {
                $proposed[$row['key']] = $row;
            } else {
                $proposed[$row['key']] = $row;
                $unmatched[$row['key']] = $row;
            }
        }

        return [
            'tenant_id' => function_exists('tenant') && tenant() ? (string) tenant('id') : null,
            'active_entity_ids' => $this->entityResolver->activeEntityIds(),
            'single_entity' => $this->singleActiveEntityId() !== null,
            'proposed' => $proposed,
            'unmatched' => $unmatched,
            'counts' => [
                'proposed' => count($proposed) - count($unmatched),
                'unmatched' => count($unmatched),
            ],
        ];
    }

    /**
     * @return string[]
     */
    private function salesDocumentTables(): array
    {
        return ['invoices', 'payments', 'customer_credit_notes', 'refund_transactions'];
    }

    /**
     * @return array<int, int>
     */
    private function unmappedSalesJournalEntryIds(): array
    {
        return $this->unmappedSalesJournalEntries()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Sales journal entries (by link to a Sales source document, or by entry
     * type) that still have no legal entity.
     */
    private function unmappedSalesJournalEntries()
    {
        $linkedEntryIds = DB::table('journal_entry_links')
            ->whereIn('source_document_type', self::SALES_SOURCE_TYPES)
            ->whereNull('superseded_at')
            ->pluck('journal_entry_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return DB::table('journal_entries')
            ->whereNull('legal_entity_id')
            ->where(function ($query) use ($linkedEntryIds) {
                $query->whereIn('type', self::SALES_SOURCE_TYPES);

                if ($linkedEntryIds !== []) {
                    $query->orWhereIn('id', $linkedEntryIds);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(string $type, int $id, object $document): array
    {
        $reference = match ($type) {
            'invoice' => $document->invoice_number ?? null,
            'payment' => $document->payment_number ?? null,
            'credit_note' => $document->credit_note_number ?? null,
            'payment_refund' => $document->refund_number ?? null,
            default => null,
        };

        [$entityId, $reason] = $this->proposeForColumns(
            salesOrderId: isset($document->sales_order_id) ? (int) $document->sales_order_id : null,
            invoiceId: isset($document->invoice_id) ? (int) $document->invoice_id : null,
            customerId: isset($document->customer_id) ? (int) $document->customer_id : null,
            sourceType: isset($document->source_type) ? (string) $document->source_type : null,
            sourceId: isset($document->source_id) ? (int) $document->source_id : null,
        );

        return [
            'key' => "{$type}:{$id}",
            'document_type' => $type,
            'document_id' => $id,
            'reference' => $reference,
            'proposed_entity_id' => $entityId,
            'proposed_book_id' => $entityId ? $this->entityResolver->resolveBookId($entityId) : null,
            'reason' => $reason,
            'status' => $entityId ? 'proposed' : 'unmatched',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJournalEntryRow(object $entry): array
    {
        $link = DB::table('journal_entry_links')
            ->where('journal_entry_id', $entry->id)
            ->whereIn('source_document_type', self::SALES_SOURCE_TYPES)
            ->orderByRaw("relation_type = 'post' DESC")
            ->first();

        if ($link) {
            $sourceType = (string) $link->source_document_type;
            $sourceId = (int) $link->source_document_id;
            [$entityId, $innerReason] = $this->proposeForSourceDocument($sourceType, $sourceId);

            $reason = $entityId
                ? "source document {$sourceType} #{$sourceId} ({$innerReason})"
                : "source document {$sourceType} #{$sourceId} has no resolvable entity";
        } else {
            $entityId = null;
            $innerReason = null;

            if (! empty($entry->reversed_entry_id)) {
                $originalEntity = DB::table('journal_entries')
                    ->where('id', $entry->reversed_entry_id)
                    ->value('legal_entity_id');

                if ($originalEntity) {
                    $entityId = (int) $originalEntity;
                    $innerReason = "reversed entry #{$entry->reversed_entry_id}";
                }
            }

            $reason = $entityId
                ? "reversal of entry #{$entry->reversed_entry_id} ({$innerReason})"
                : 'no link to a Sales source document and no entity context';
        }

        return [
            'key' => "journal_entry:{$entry->id}",
            'document_type' => 'journal_entry',
            'document_id' => (int) $entry->id,
            'reference' => $entry->entry_number,
            'proposed_entity_id' => $entityId,
            'proposed_book_id' => $entityId ? $this->entityResolver->resolveBookId($entityId) : null,
            'reason' => $reason,
            'status' => $entityId ? 'proposed' : 'unmatched',
        ];
    }

    /**
     * Propose the entity for a journal entry's source document (by link type).
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function proposeForSourceDocument(string $type, int $id): array
    {
        switch ($type) {
            case 'invoice':
                return $this->proposeForInvoice($id);

            case 'payment':
                $payment = Schema::hasTable('payments')
                    ? DB::table('payments')->where('id', $id)->first()
                    : null;

                if ($payment) {
                    if (Schema::hasColumn('payments', 'legal_entity_id') && $payment->legal_entity_id) {
                        return [(int) $payment->legal_entity_id, "payment #{$id}"];
                    }

                    return $this->proposeForColumns(
                        salesOrderId: null,
                        invoiceId: ! empty($payment->invoice_id) ? (int) $payment->invoice_id : null,
                        customerId: ! empty($payment->customer_id) ? (int) $payment->customer_id : null,
                    );
                }

                return [null, null];

            case 'credit_note':
                $creditNote = Schema::hasTable('customer_credit_notes')
                    ? DB::table('customer_credit_notes')->where('id', $id)->first()
                    : null;

                if ($creditNote) {
                    if (Schema::hasColumn('customer_credit_notes', 'legal_entity_id') && $creditNote->legal_entity_id) {
                        return [(int) $creditNote->legal_entity_id, "credit note #{$id}"];
                    }

                    return $this->proposeForColumns(
                        salesOrderId: ! empty($creditNote->sales_order_id) ? (int) $creditNote->sales_order_id : null,
                        invoiceId: ! empty($creditNote->invoice_id) ? (int) $creditNote->invoice_id : null,
                        customerId: ! empty($creditNote->customer_id) ? (int) $creditNote->customer_id : null,
                    );
                }

                return [null, null];

            case 'delivery_order':
                $deliveryOrder = Schema::hasTable('delivery_orders')
                    ? DB::table('delivery_orders')->where('id', $id)->first()
                    : null;

                if ($deliveryOrder) {
                    return $this->proposeForColumns(
                        salesOrderId: ! empty($deliveryOrder->sales_order_id) ? (int) $deliveryOrder->sales_order_id : null,
                        invoiceId: null,
                        customerId: ! empty($deliveryOrder->customer_id) ? (int) $deliveryOrder->customer_id : null,
                    );
                }

                return [null, null];

            case 'payment_refund':
                // Legacy standalone refund entries were posted with
                // documentId = invoice id.
                return $this->proposeForInvoice($id);
        }

        return [null, null];
    }

    /**
     * Propose the entity for a document from its provenance columns.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function proposeForColumns(
        ?int $salesOrderId,
        ?int $invoiceId,
        ?int $customerId,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): array {
        if ($salesOrderId) {
            $entityId = $this->entityFromTable('sales_orders', $salesOrderId);
            if ($entityId) {
                return [$entityId, "sales order #{$salesOrderId}"];
            }
        }

        if ($invoiceId) {
            [$entityId, $reason] = $this->proposeForInvoice($invoiceId);
            if ($entityId) {
                return [$entityId, $reason];
            }
        }

        if ($sourceType && $sourceId) {
            [$entityId, $reason] = $this->proposeForSource($sourceType, $sourceId);
            if ($entityId) {
                return [$entityId, $reason];
            }
        }

        if ($customerId) {
            $entityId = $this->entityFromTable('customers', $customerId);
            if ($entityId) {
                return [$entityId, "customer #{$customerId}"];
            }
        }

        $requestEntity = $this->entityResolver->fromRequestContext();
        if ($requestEntity) {
            return [$requestEntity, 'request entity context'];
        }

        $single = $this->entityResolver->singleActiveEntityId();
        if ($single) {
            return [$single, 'single active tenant entity'];
        }

        return [null, 'no entity resolvable from sales order, source document, customer or entity context'];
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function proposeForInvoice(int $invoiceId): array
    {
        if (! Schema::hasTable('invoices')) {
            return [null, null];
        }

        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();

        if (! $invoice) {
            return [null, null];
        }

        if (Schema::hasColumn('invoices', 'legal_entity_id') && $invoice->legal_entity_id) {
            return [(int) $invoice->legal_entity_id, "invoice #{$invoiceId}"];
        }

        return $this->proposeForColumns(
            salesOrderId: ! empty($invoice->sales_order_id) ? (int) $invoice->sales_order_id : null,
            invoiceId: null,
            customerId: ! empty($invoice->customer_id) ? (int) $invoice->customer_id : null,
        );
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function proposeForSource(string $sourceType, int $sourceId): array
    {
        $basename = class_basename($sourceType);

        if ($basename === 'Invoice') {
            return $this->proposeForInvoice($sourceId);
        }

        if ($basename === 'PaymentTransaction') {
            if (! Schema::hasTable('payment_transactions')) {
                return [null, null];
            }

            $transaction = DB::table('payment_transactions')->where('id', $sourceId)->first();

            if ($transaction && ! empty($transaction->invoice_id)) {
                [$entityId, $reason] = $this->proposeForInvoice((int) $transaction->invoice_id);

                if ($entityId) {
                    return [$entityId, "payment transaction #{$sourceId} → {$reason}"];
                }
            }

            return [null, null];
        }

        $table = match ($basename) {
            'PosOrder' => 'pos_orders',
            'WebOrder' => 'web_orders',
            'SalesOrder' => 'sales_orders',
            default => null,
        };

        if ($table !== null && Schema::hasTable($table)) {
            $entityId = $this->entityFromTable($table, $sourceId);
            if ($entityId) {
                return [$entityId, "source {$basename} #{$sourceId}"];
            }
        }

        return [null, null];
    }

    private function entityFromTable(string $table, int $id): ?int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
            return null;
        }

        $entityId = DB::table($table)->where('id', $id)->value('legal_entity_id');

        return $entityId ? (int) $entityId : null;
    }
}
