<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Http\Middleware\RequirePermission;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ApprovalVisibilityService;
use App\Services\Chatter\AssignedActivityService;
use App\Services\SalesDocumentEntityScope;
use App\Services\SalesDocumentProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * Issue #365 [SALES-DASH]: the Sales cockpit's action queues — what a
 * salesperson should do next, each with a count, the most urgent rows and a
 * link to the matching filtered list.
 *
 * Every queue reuses an existing rule rather than inventing one:
 *   - quotes to send:      draft quotations (the #334 send step);
 *   - quotes expiring:     draft/sent quotations whose `valid_until` falls in
 *                          the next EXPIRING_WINDOW_DAYS days — the same
 *                          statuses the #335 expiry job and the #903 reminder
 *                          scan act on;
 *   - approvals pending:   pending, unexpired ApprovalService requests on a
 *                          Sales document, filtered by ApprovalVisibilityService
 *                          (#651) exactly like the approvals inbox;
 *   - orders to deliver:   open orders with a line short of its ordered
 *                          quantity (the #408 "not fully delivered" rule);
 *   - orders to invoice:   open orders with a line invoiceable NOW under its
 *                          invoicing policy — the SalesOrderService
 *                          createInvoice rule (delivered_qty → delivered −
 *                          invoiced, ordered_qty → ordered − invoiced);
 *   - deliveries to invoice: #688 SalesDocumentProgress "delivered not invoiced";
 *   - invoices to send:    open posted invoices whose InvoiceDeliveryService
 *                          `delivery_status` is not_sent / failed;
 *   - overdue invoices:    the AgedReceivablesService universe (open statuses,
 *                          positive balance) past its due date.
 *
 * The list endpoints apply the very same query through applyListFilter(), so a
 * tile's count always equals the rows of the list it opens.
 *
 * Scoping: each queue needs the view permission of its document (the one that
 * guards that document's list route); queues are confined to the caller's
 * accessible legal entities (empty grants = unscoped, the EnsureEntityAccess
 * fail-open default); `mine` narrows to documents the caller created or whose
 * customer they own as salesperson (the #593 salesperson dimension).
 */
class SalesActionQueueService
{
    /** Days ahead in which a quotation counts as "expiring" (issue #365). */
    public const EXPIRING_WINDOW_DAYS = 7;

    public const DEFAULT_LIMIT = 5;

    public const MAX_LIMIT = 20;

    private const EPSILON = 0.0001;

    /** Order statuses that may still need delivering or invoicing. */
    private const OPEN_ORDER_STATUSES = ['confirmed', 'allocated', 'fulfilled', 'invoiced'];

    /** Invoice statuses carrying an open receivable (AgedReceivablesService). */
    private const OPEN_INVOICE_STATUSES = ['sent', 'posted', 'partial', 'overdue'];

    /**
     * key => [label, document, view permission, list path].
     *
     * @var array<string, array{0: string, 1: string, 2: ?string, 3: string}>
     */
    public const QUEUES = [
        'quotes_to_send' => ['Quotes to send', 'quotation', 'quotations.view', '/sales/quotes'],
        'quotes_expiring' => ['Quotes expiring in 7 days', 'quotation', 'quotations.view', '/sales/quotes'],
        'approvals_pending' => ['Approvals pending', 'approval', null, '/approvals'],
        'orders_to_deliver' => ['Orders to deliver', 'sales_order', 'sales.view', '/sales/orders'],
        'orders_to_invoice' => ['Orders to invoice', 'sales_order', 'sales.view', '/sales/orders'],
        'deliveries_to_invoice' => ['Deliveries to invoice', 'delivery_order', 'sales.view', '/delivery-orders'],
        'invoices_to_send' => ['Invoices to send', 'invoice', 'invoices.view', '/invoices'],
        'overdue_invoices' => ['Overdue invoices', 'invoice', 'invoices.view', '/invoices'],
    ];

    /** Sales documents an approval request may concern (config/approvals.php). */
    private const SALES_APPROVAL_SUBJECTS = [
        Quotation::class => ['quotation_number', '/sales/quotes/'],
        SalesOrder::class => ['sales_order_number', '/sales/orders/'],
        Invoice::class => ['invoice_number', '/invoices/'],
        DeliveryOrder::class => ['delivery_order_number', '/delivery-orders/'],
    ];

    public function __construct(
        private readonly SalesDocumentEntityScope $entityScope,
        private readonly SalesDocumentProgress $progress,
        private readonly ApprovalService $approvals,
        private readonly ApprovalVisibilityService $approvalVisibility,
        private readonly AssignedActivityService $activities,
    ) {}

    /**
     * Counts plus the top $limit rows of every queue the caller may see.
     *
     * @return array{mine: bool, expiring_window_days: int, queues: list<array<string, mixed>>}
     */
    public function summary(Request $request, bool $mine, int $limit): array
    {
        $user = $request->user();
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $queues = [];

        foreach (self::QUEUES as $key => [$label, $document, $permission, $listPath]) {
            if ($permission !== null && ! RequirePermission::allows($request, $permission)) {
                continue;
            }

            [$count, $items] = $document === 'approval'
                ? $this->approvalQueue($user, $mine, $limit)
                : $this->documentQueue($key, $document, $user, $mine, $limit);

            $queue = [
                'key' => $key,
                'label' => $label,
                'document' => $document,
                'count' => $count,
                'link' => $document === 'approval' ? $listPath : $this->listLink($key, $listPath, $mine),
                'items' => $items,
            ];

            if ($key === 'overdue_invoices') {
                // Customer follow-up (#328) for the overdue receivables.
                $queue['secondary_link'] = '/accounting/customers/followup-reports';
            }

            $queues[] = $queue;
        }

        return [
            'mine' => $mine,
            'expiring_window_days' => self::EXPIRING_WINDOW_DAYS,
            'queues' => $queues,
            // Issue #410: the caller's own scheduled activities that are due
            // (overdue or due today), on documents they may open. Kept beside
            // `queues` because it is always the caller's own work (`mine` does
            // not apply) and it opens "My activities", not a document list.
            'activities' => $this->activities->dueSummary(
                $user,
                fn (string $permission) => RequirePermission::allows($request, $permission),
                $limit,
            ),
        ];
    }

    /**
     * List-endpoint hook: `?action_queue=<key>[&mine=1]` narrows a document
     * list to exactly the rows the cockpit tile counted. An unknown key, or a
     * key for another document type, is ignored.
     */
    public function applyListFilter(Request $request, Builder $query, string $document): void
    {
        $key = (string) $request->query('action_queue', '');

        if (! isset(self::QUEUES[$key]) || self::QUEUES[$key][1] !== $document) {
            return;
        }

        $this->scopeQueue($key, $query, $request->user(), $request->boolean('mine'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Document queues
    // ─────────────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: list<array<string, mixed>>} */
    private function documentQueue(string $key, string $document, ?User $user, bool $mine, int $limit): array
    {
        $query = $this->baseQuery($document);
        $this->scopeQueue($key, $query, $user, $mine);

        $count = (clone $query)->count();

        $rows = $this->orderForUrgency($key, $query)
            ->with('customer:id,name')
            ->limit($limit)
            ->get();

        return [$count, $rows->map(fn (Model $row) => $this->item($key, $document, $row))->values()->all()];
    }

    private function baseQuery(string $document): Builder
    {
        return match ($document) {
            'quotation' => Quotation::query(),
            'sales_order' => SalesOrder::query(),
            'delivery_order' => DeliveryOrder::query(),
            'invoice' => Invoice::query(),
        };
    }

    /** Queue rule + legal-entity scope + optional "mine" on one query. */
    private function scopeQueue(string $key, Builder $query, ?User $user, bool $mine): void
    {
        $table = $query->getModel()->getTable();
        $today = now()->toDateString();

        match ($key) {
            'quotes_to_send' => $query->where("{$table}.status", 'draft'),
            'quotes_expiring' => $query->whereIn("{$table}.status", ['draft', 'sent'])
                ->whereNotNull("{$table}.valid_until")
                ->whereDate("{$table}.valid_until", '>=', $today)
                ->whereDate("{$table}.valid_until", '<=', now()->addDays(self::EXPIRING_WINDOW_DAYS)->toDateString()),
            'orders_to_deliver' => $query->whereIn("{$table}.status", self::OPEN_ORDER_STATUSES)
                ->whereHas('lines', fn (Builder $line) => $this->productLines($line)
                    ->whereRaw('quantity - quantity_cancelled > quantity_delivered + ?', [self::EPSILON])),
            'orders_to_invoice' => $query->whereIn("{$table}.status", self::OPEN_ORDER_STATUSES)
                ->whereHas('lines', fn (Builder $line) => $this->productLines($line)
                    ->where(function (Builder $policy) {
                        $policy->where(function (Builder $delivered) {
                            $delivered->where('invoicing_policy', 'delivered_qty')
                                ->whereRaw('quantity_delivered > quantity_invoiced + ?', [self::EPSILON]);
                        })->orWhere(function (Builder $ordered) {
                            $ordered->where(fn (Builder $p) => $p->whereNull('invoicing_policy')->orWhere('invoicing_policy', '!=', 'delivered_qty'))
                                ->whereRaw('quantity - quantity_cancelled > quantity_invoiced + ?', [self::EPSILON]);
                        });
                    })),
            'deliveries_to_invoice' => $this->progress->scopeDeliveredNotInvoiced($query),
            'invoices_to_send' => $query->whereIn("{$table}.status", self::OPEN_INVOICE_STATUSES)
                ->notSuperseded()
                ->whereIn("{$table}.delivery_status", [Invoice::DELIVERY_NOT_SENT, Invoice::DELIVERY_FAILED]),
            'overdue_invoices' => $query->whereIn("{$table}.status", self::OPEN_INVOICE_STATUSES)
                ->notSuperseded()
                ->where("{$table}.balance", '>', 0)
                ->whereNotNull("{$table}.due_date")
                ->whereDate("{$table}.due_date", '<', $today),
        };

        $this->scopeEntities($query, $user);

        if ($mine && $user !== null) {
            $userId = (int) $user->id;
            $query->where(function (Builder $own) use ($table, $userId) {
                $own->where("{$table}.created_by", $userId)
                    ->orWhereIn("{$table}.customer_id", fn ($customers) => $customers
                        ->select('id')->from('customers')->where('salesperson_id', $userId));
            });
        }
    }

    private function productLines(Builder $line): Builder
    {
        return $line->where(fn (Builder $type) => $type->whereNull('line_type')->orWhereNotIn('line_type', ['note', 'section']));
    }

    /**
     * Orders and deliveries carry their own legal entity; quotations only
     * through their customer; invoices by their own entity (#875), falling
     * back to the customer's for rows not yet stamped. The rule lives in
     * SalesDocumentEntityScope (#410) so the activity surfaces share it.
     */
    private function scopeEntities(Builder $query, ?User $user): void
    {
        $this->entityScope->apply($query, $user);
    }

    private function orderForUrgency(string $key, Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        $column = match ($key) {
            'quotes_to_send' => 'quotation_date',
            'quotes_expiring' => 'valid_until',
            'orders_to_deliver', 'orders_to_invoice' => 'order_date',
            'deliveries_to_invoice' => 'delivery_date',
            'invoices_to_send' => 'invoice_date',
            'overdue_invoices' => 'due_date',
        };

        return $query->reorder()->orderBy("{$table}.{$column}")->orderBy("{$table}.id");
    }

    /** @return array<string, mixed> */
    private function item(string $key, string $document, Model $row): array
    {
        [$number, $date, $amount, $path] = match ($document) {
            'quotation' => [$row->quotation_number, $key === 'quotes_expiring' ? $row->valid_until : $row->quotation_date, $row->total, '/sales/quotes/'],
            'sales_order' => [$row->sales_order_number, $row->order_date, $row->total, '/sales/orders/'],
            'delivery_order' => [$row->delivery_order_number, $row->delivery_date, null, '/delivery-orders/'],
            'invoice' => [$row->invoice_number, $key === 'overdue_invoices' ? $row->due_date : $row->invoice_date, $key === 'overdue_invoices' ? $row->balance : $row->total, '/invoices/'],
        };

        return [
            'id' => (int) $row->id,
            'number' => (string) $number,
            'customer' => $row->customer?->name,
            'date' => $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : null,
            'amount' => $amount !== null ? round((float) $amount, 2) : null,
            'currency_code' => $row->currency_code,
            'link' => $path.$row->id,
        ];
    }

    private function listLink(string $key, string $listPath, bool $mine): string
    {
        return $listPath.'?'.http_build_query(array_filter([
            'action_queue' => $key,
            'mine' => $mine ? 1 : null,
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Approvals
    // ─────────────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: list<array<string, mixed>>} */
    private function approvalQueue(?User $user, bool $mine, int $limit): array
    {
        $requests = $this->approvals->getPendingRequests()
            ->filter(fn (ApprovalRequest $request) => isset(self::SALES_APPROVAL_SUBJECTS[$request->subject_type]))
            ->values();

        if ($mine && $user !== null) {
            $requests = $requests->filter(fn (ApprovalRequest $request) => (int) $request->requested_by === (int) $user->id)->values();
        }

        ApprovalRequest::loadSubjects($requests);

        // Same row-level rule as GET /approvals (#651): entity scope plus
        // requester / decider / may-view-the-document.
        $visible = $this->approvalVisibility->visible($requests, $user)->values();

        $items = $visible->take($limit)->map(function (ApprovalRequest $request) {
            [$numberColumn, $path] = self::SALES_APPROVAL_SUBJECTS[$request->subject_type];
            $subject = $request->relationLoaded('subject') ? $request->subject : null;

            return [
                'id' => (int) $request->id,
                'number' => (string) ($subject?->{$numberColumn} ?? $request->request_number),
                'request_number' => $request->request_number,
                'request_type' => $request->request_type,
                'customer' => $subject?->customer?->name,
                'date' => $request->requested_at?->toDateString(),
                'amount' => $request->amount !== null ? round((float) $request->amount, 2) : null,
                'currency_code' => $subject?->currency_code,
                'link' => $path.$request->subject_id,
            ];
        })->values()->all();

        return [$visible->count(), $items];
    }
}
