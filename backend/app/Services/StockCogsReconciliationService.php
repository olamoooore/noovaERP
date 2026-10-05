<?php

namespace App\Services;

use App\Accounting\Services\AccountMappingService;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryLink;
use Illuminate\Support\Facades\Log;

/**
 * Issue #696 (INV-D3): the stock & COGS reconciliation badge shared by the
 * sales-order, delivery-order and invoice detail headers, plus the nightly
 * mismatch report.
 *
 * The stock half is a read of #690's {@see DocumentStockMovesService} — the
 * single owner of "what left the warehouse for this document's goods-issue
 * bucket" — so the badge never forks a second movement rule. The business
 * question it answers is deliberately narrower than #690's trail tab: an open
 * order that has not shipped yet is *pending*, not wrong, and a reversed
 * delivery nets its sale and return to zero. Only movements that took out more
 * than the document sold (the #682 double issue, an unallocated residual) turn
 * the stock half red.
 *
 * The COGS half pairs each movement group (one issuing document) with its
 * journal links: a group that net-issued goods must have exactly ONE active
 * COGS posting (a `post` link whose entry carries a COGS line and that no
 * reversal superseded), and a group that net-issued nothing must have none.
 * Bundles decompose into several component movements that still share the one
 * document-level posting, so they stay green; invoice cancellations delete
 * the post link and delivery reversals retire it, so returns/reversals stay
 * green too. POS / web orders are not mapped: they issue stock in their own
 * channel services rather than through the sales COGS posting, so flagging
 * them would be a false positive.
 */
class StockCogsReconciliationService
{
    private const EPSILON = 0.0001;

    /**
     * Movement reference_type → journal-entry-link source_document_type for
     * the documents that post their own COGS through the PostingEngine
     * (InvoiceService, DeliveryOrderService). Channel orders are omitted on
     * purpose — see the class docblock.
     */
    private const COGS_SOURCE_TYPES = [
        'DeliveryOrder' => 'delivery_order',
        'Invoice' => 'invoice',
    ];

    public function __construct(
        private DocumentStockMovesService $stockMoves,
        private AccountMappingService $accountMapping,
    ) {}

    /**
     * The badge payload for one sales document.
     *
     * @return array{
     *     document: array<string, mixed>,
     *     has_mismatch: bool,
     *     stock: array{ok: bool, issued: float, sold: float, offenders: array<int, array<string, mixed>>},
     *     cogs: array{ok: bool, posted_count: int, offenders: array<int, array<string, mixed>>}
     * }
     */
    public function badge(string $documentType, int $documentId): array
    {
        $trail = $this->stockMoves->forDocument($documentType, $documentId);

        $stock = $this->reconcileStock($trail);
        $cogs = $this->reconcileCogs($trail);

        return [
            'document' => $trail['document'],
            'has_mismatch' => ! $stock['ok'] || ! $cogs['ok'],
            'stock' => $stock,
            'cogs' => $cogs,
        ];
    }

    /**
     * Stock half: aggregate the per-line net issued / sold quantities from the
     * #690 read model and turn every over-issued (unallocated) component into
     * an offender carrying the movements a person must look at.
     *
     * @param  array<string, mixed>  $trail
     * @return array{ok: bool, issued: float, sold: float, offenders: array<int, array<string, mixed>>}
     */
    private function reconcileStock(array $trail): array
    {
        $stockLines = collect($trail['lines'])->where('stock_tracked', true);

        // The per-line net issued figures are allocations capped at each
        // line's demand; unallocated residuals are the extra units no demand
        // line claimed. What actually left the warehouse is both.
        $excess = collect($trail['unallocated'] ?? [])->sum('quantity');

        $issued = round((float) $stockLines->sum('net_issued_quantity') + (float) $excess, 4);
        $sold = round((float) $stockLines->sum('required_stock_quantity'), 4);

        // Per component product, what each line demanded and was allocated.
        $requiredByProduct = [];
        $allocatedByProduct = [];

        foreach ($stockLines as $line) {
            foreach ($line['components'] as $component) {
                $productId = (int) $component['product_id'];
                $requiredByProduct[$productId] = ($requiredByProduct[$productId] ?? 0.0)
                    + (float) $component['required_quantity'];
                $allocatedByProduct[$productId] = ($allocatedByProduct[$productId] ?? 0.0)
                    + (float) $component['net_issued_quantity'];
            }
        }

        $offenders = [];

        foreach ($trail['unallocated'] ?? [] as $residual) {
            $productId = (int) $residual['product_id'];
            $excess = round((float) $residual['quantity'], 4);
            $required = round((float) ($requiredByProduct[$productId] ?? 0.0), 4);
            $allocated = round((float) ($allocatedByProduct[$productId] ?? 0.0), 4);

            $offenders[] = [
                'kind' => 'over_issued',
                'product_id' => $productId,
                'product' => $residual['product'],
                'issued' => round($allocated + $excess, 4),
                'expected' => $required,
                'excess' => $excess,
                'movements' => $this->movementsForProduct($trail['movements'], $productId),
            ];
        }

        return [
            'ok' => $offenders === [],
            'issued' => $issued,
            'sold' => $sold,
            'offenders' => $offenders,
        ];
    }

    /**
     * COGS half: one active COGS journal entry per issuing movement group.
     *
     * @param  array<string, mixed>  $trail
     * @return array{ok: bool, posted_count: int, offenders: array<int, array<string, mixed>>}
     */
    private function reconcileCogs(array $trail): array
    {
        $groups = [];

        foreach ($trail['movements'] as $movement) {
            $sourceType = $movement['source_document']['type'] ?? null;
            $sourceId = $movement['source_document']['id'] ?? null;

            if ($sourceType === null || $sourceId === null || ! isset(self::COGS_SOURCE_TYPES[$sourceType])) {
                continue;
            }

            $key = $sourceType.'|'.$sourceId;
            $delta = $movement['direction'] === 'in'
                ? -(float) $movement['quantity']
                : (float) $movement['quantity'];

            $groups[$key] ??= [
                'type' => $sourceType,
                'id' => (int) $sourceId,
                'number' => $movement['source_document']['number'] ?? null,
                'net_issued' => 0.0,
            ];
            $groups[$key]['net_issued'] += $delta;
        }

        $cogsAccountId = $this->cogsAccountId();
        $offenders = [];
        $postedCount = 0;

        if ($cogsAccountId !== null) {
            foreach ($groups as $group) {
                $activeEntries = $this->activeCogsEntries(
                    self::COGS_SOURCE_TYPES[$group['type']],
                    $group['id'],
                    $cogsAccountId,
                );

                $postedCount += count($activeEntries);
                $net = round($group['net_issued'], 4);

                if ($net > self::EPSILON && count($activeEntries) === 0) {
                    $offenders[] = $this->cogsOffender('missing_cogs', $group, $net, $activeEntries);
                } elseif ($net > self::EPSILON && count($activeEntries) > 1) {
                    $offenders[] = $this->cogsOffender('duplicate_cogs', $group, $net, $activeEntries);
                } elseif ($net <= self::EPSILON && $activeEntries !== []) {
                    $offenders[] = $this->cogsOffender('cogs_without_issue', $group, $net, $activeEntries);
                }
            }
        }

        return [
            'ok' => $offenders === [],
            'posted_count' => $postedCount,
            'offenders' => $offenders,
        ];
    }

    /**
     * COGS journal entries for one issuing document that are live now: a
     * `post` link that is not superseded, whose entry carries a COGS-account
     * line, and that no reversal link or retirement has undone.
     *
     * @return array<int, int>
     */
    private function activeCogsEntries(string $sourceType, int $sourceId, int $cogsAccountId): array
    {
        $postLinks = JournalEntryLink::query()
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $sourceId)
            ->where('relation_type', 'post')
            ->whereNull('superseded_at')
            ->whereNull('reversal_journal_entry_id')
            ->orderBy('id')
            ->get();

        if ($postLinks->isEmpty()) {
            return [];
        }

        $entryIds = $postLinks->pluck('journal_entry_id')->map(fn ($id) => (int) $id)->all();

        $reversedEntryIds = JournalEntryLink::query()
            ->where('relation_type', 'reversal')
            ->whereIn('original_journal_entry_id', $entryIds)
            ->pluck('original_journal_entry_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $cogsEntryIds = JournalEntryLine::query()
            ->whereIn('journal_entry_id', $entryIds)
            ->where('account_id', $cogsAccountId)
            ->pluck('journal_entry_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        return array_values(array_diff($cogsEntryIds, $reversedEntryIds));
    }

    /**
     * @param  array{type: string, id: int, number: string|null, net_issued: float}  $group
     * @param  array<int, int>  $activeEntries
     * @return array<string, mixed>
     */
    private function cogsOffender(string $kind, array $group, float $net, array $activeEntries): array
    {
        $expected = $net > self::EPSILON ? 1 : 0;

        return [
            'kind' => $kind,
            'source_document' => [
                'type' => $group['type'],
                'id' => $group['id'],
                'number' => $group['number'],
            ],
            'net_issued' => $net,
            'expected_postings' => $expected,
            'posted_count' => count($activeEntries),
            'journal_entry_ids' => $activeEntries,
            'message' => match ($kind) {
                'missing_cogs' => 'Goods were issued but no COGS journal entry is active for this document.',
                'duplicate_cogs' => 'More than one active COGS journal entry was found for this document.',
                default => 'A COGS journal entry is active although no goods are net issued by this document.',
            },
        ];
    }

    /**
     * The movement rows (with their source document) an accountant can open to
     * see where the excess came from. The read model already enriched them, so
     * the badge does not re-query the movements.
     *
     * @param  array<int, array<string, mixed>>  $movements
     * @return array<int, array<string, mixed>>
     */
    private function movementsForProduct(array $movements, int $productId): array
    {
        return array_values(array_map(
            fn (array $movement) => [
                'id' => (int) $movement['id'],
                'type' => $movement['type'],
                'direction' => $movement['direction'],
                'quantity' => (float) $movement['quantity'],
                'date' => $movement['date'] ?? null,
                'product' => $movement['product'] ?? null,
                'source_document' => $movement['source_document'],
            ],
            array_filter(
                $movements,
                fn (array $movement) => (int) $movement['product_id'] === $productId
                    && in_array($movement['type'], ['sale', 'return'], true),
            ),
        ));
    }

    /**
     * The COGS control account. A tenant with no chart of accounts cannot have
     * posted COGS at all, so the read degrades to "nothing to pair" and logs
     * the missing mapping instead of failing the whole badge.
     */
    private function cogsAccountId(): ?int
    {
        try {
            return (int) $this->accountMapping->resolveId('cogs');
        } catch (\Throwable $e) {
            Log::warning('Stock/COGS reconciliation: COGS account could not be resolved', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
