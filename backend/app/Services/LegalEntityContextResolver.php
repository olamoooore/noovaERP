<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #875 (P0, SA1): resolves the legal entity (and its primary book) for
 * Sales documents and postings.
 *
 * Resolution order (first hit wins):
 *   1. explicit caller-supplied entity
 *   2. sales order                     (sales_orders.legal_entity_id, #140 pattern)
 *   3. invoice                         (invoices.legal_entity_id → its sales order)
 *   4. source document (morph source, e.g. RefundTransaction → PaymentTransaction)
 *   5. customer                        (customers.legal_entity_id, #140 pattern)
 *   6. request context                 (ResolveEntityContext: X-Entity-Id / entity_id / user default)
 *   7. the tenant's only active entity (unambiguous single-entity tenant)
 *
 * When nothing resolves and the tenant HAS legal entities, a required
 * resolution throws ENTITY_CONTEXT_REQUIRED instead of silently writing a
 * NULL entity (fail-closed — a NULL entity is invisible to entity-filtered
 * reports and to entity-scoped users, see JournalEntry::scopeAccessibleBy).
 */
class LegalEntityContextResolver
{
    public const MISSING_CONTEXT_MESSAGE = 'ENTITY_CONTEXT_REQUIRED';

    /** @var int[]|null */
    private ?array $activeEntityIds = null;

    private ?bool $entityTableHasRows = null;

    /**
     * Does this tenant have any legal entity defined at all?
     */
    public function entityExists(): bool
    {
        if ($this->entityTableHasRows !== null) {
            return $this->entityTableHasRows;
        }

        if (! Schema::hasTable('legal_entities')) {
            return $this->entityTableHasRows = false;
        }

        return $this->entityTableHasRows = DB::table('legal_entities')->exists();
    }

    /**
     * @return int[]
     */
    public function activeEntityIds(): array
    {
        if ($this->activeEntityIds !== null) {
            return $this->activeEntityIds;
        }

        if (! Schema::hasTable('legal_entities')) {
            return $this->activeEntityIds = [];
        }

        return $this->activeEntityIds = DB::table('legal_entities')
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The tenant's only active entity, or null when there are zero or several.
     */
    public function singleActiveEntityId(): ?int
    {
        $ids = $this->activeEntityIds();

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Entity selected for this request by ResolveEntityContext middleware
     * (X-Entity-Id header, entity_id input, or the user's default entity).
     */
    public function fromRequestContext(): ?int
    {
        if (! function_exists('request')) {
            return null;
        }

        $request = request();
        if (! $request) {
            return null;
        }

        $entityId = $request->attributes->get('legal_entity_id');

        return $entityId ? (int) $entityId : null;
    }

    /**
     * Resolve the entity for a Sales document/posting.
     *
     * @param  bool  $required  throw when the tenant has entities but nothing resolved
     */
    public function resolveEntity(
        ?int $explicit = null,
        ?int $salesOrderId = null,
        ?int $invoiceId = null,
        ?int $customerId = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        bool $required = true,
    ): ?int {
        $entityId = $explicit;

        if (! $entityId && $salesOrderId) {
            $entityId = $this->entityFromTable('sales_orders', $salesOrderId);
        }

        if (! $entityId && $invoiceId) {
            $entityId = $this->entityFromInvoice($invoiceId);
        }

        if (! $entityId && $sourceType && $sourceId) {
            $entityId = $this->entityFromSource($sourceType, $sourceId);
        }

        if (! $entityId && $customerId) {
            $entityId = $this->entityFromTable('customers', $customerId);
        }

        if (! $entityId) {
            $entityId = $this->fromRequestContext();
        }

        if (! $entityId) {
            $entityId = $this->singleActiveEntityId();
        }

        $entityId = $entityId ? (int) $entityId : null;

        if (! $entityId && $required && $this->entityExists()) {
            throw new \RuntimeException(
                'A legal entity is required: '.self::MISSING_CONTEXT_MESSAGE.
                '. The tenant has legal entities but none could be resolved for this document. '.
                'Supply X-Entity-Id / entity_id, or set the customer/sales order legal entity.'
            );
        }

        return $entityId;
    }

    /**
     * Primary active book of the entity, when the tenant keeps entity books.
     */
    public function resolveBookId(?int $entityId): ?int
    {
        if (! $entityId || ! Schema::hasTable('entity_books')) {
            return null;
        }

        $bookId = DB::table('entity_books')
            ->where('legal_entity_id', $entityId)
            ->where('is_primary', true)
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id');

        return $bookId ? (int) $bookId : null;
    }

    private function entityFromInvoice(int $invoiceId): ?int
    {
        if (! Schema::hasTable('invoices')) {
            return null;
        }

        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();

        if (! $invoice) {
            return null;
        }

        if (Schema::hasColumn('invoices', 'legal_entity_id') && $invoice->legal_entity_id) {
            return (int) $invoice->legal_entity_id;
        }

        if (! empty($invoice->sales_order_id)) {
            $entityId = $this->entityFromTable('sales_orders', (int) $invoice->sales_order_id);
            if ($entityId) {
                return $entityId;
            }
        }

        if (! empty($invoice->customer_id)) {
            return $this->entityFromTable('customers', (int) $invoice->customer_id);
        }

        return null;
    }

    private function entityFromSource(string $sourceType, int $sourceId): ?int
    {
        $table = match (class_basename($sourceType)) {
            'Invoice' => 'invoices',
            'SalesOrder' => 'sales_orders',
            'PosOrder' => 'pos_orders',
            'WebOrder' => 'web_orders',
            'PaymentTransaction' => 'payment_transactions',
            default => null,
        };

        if ($table === null || ! Schema::hasTable($table)) {
            return null;
        }

        if ($table === 'invoices') {
            return $this->entityFromInvoice($sourceId);
        }

        $row = DB::table($table)->where('id', $sourceId)->first();

        if (! $row) {
            return null;
        }

        if (Schema::hasColumn($table, 'legal_entity_id') && $row->legal_entity_id) {
            return (int) $row->legal_entity_id;
        }

        if (! empty($row->invoice_id)) {
            $entityId = $this->entityFromInvoice((int) $row->invoice_id);
            if ($entityId) {
                return $entityId;
            }
        }

        if (! empty($row->sales_order_id)) {
            $entityId = $this->entityFromTable('sales_orders', (int) $row->sales_order_id);
            if ($entityId) {
                return $entityId;
            }
        }

        if (! empty($row->customer_id)) {
            return $this->entityFromTable('customers', (int) $row->customer_id);
        }

        return null;
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
