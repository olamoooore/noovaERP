<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SalesApprovalRule;
use App\Models\Setting;
use App\Models\User;
use App\Platform\Notifications\NotificationReferences;
use App\Platform\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * Config-driven approval gate for Sales documents (Quotation, SalesOrder, Invoice).
 *
 * Issue #180: there was no config-driven approval gate on the Quotation/SO/Invoice
 * confirm paths. With this gate in place, a tenant can set a threshold amount; any
 * document whose total meets or exceeds it triggers an `ApprovalRequest` (in addition
 * to, and independent of, the existing user-toggled `Invoice.requires_approval`).
 *
 * Issue #649:
 *   - The tenant-wide `sales.approval_threshold_amount` stays the fallback, but a
 *     per-document-type override (`sales.{type}.approval_threshold_amount`) wins
 *     when set.
 *   - Documents are compared in BASE currency using the document's own
 *     `exchange_rate`/`base_currency_code` snapshot (base = total × rate), not in
 *     document currency.
 *   - A tenant can additionally configure `sales_approval_rules` rows
 *     (`SalesApprovalRule`) with an amount / discount % / margin / credit-exposure
 *     condition per document type. Any matching active rule fires the gate even
 *     without a threshold.
 *
 * Behaviour:
 *   - Setting `sales.approval_threshold_amount` (or a per-type override) is read
 *     per-tenant; null/0 disables that threshold.
 *   - When the gate fires, the caller's controller returns HTTP 202 with the
 *     `ApprovalRequest` payload so the caller knows approval is pending.
 *   - The document's own status is NOT advanced (SO/Quotation stay `draft`);
 *     an operator (or follow-up workflow job) confirms the order after approval.
 */
class SalesApprovalGate
{
    public const SETTING_KEY = 'sales.approval_threshold_amount';

    /**
     * Per-document-type threshold overrides (#649), applied before the
     * tenant-wide {@see self::SETTING_KEY} fallback. The document-type names
     * match {@see SalesApprovalRule} and the reviewer-setting prefixes.
     *
     * @var array<string, string>
     */
    public const DOCUMENT_THRESHOLD_SETTING_KEYS = [
        SalesApprovalRule::DOCUMENT_TYPE_QUOTATION => 'sales.quotation.approval_threshold_amount',
        SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER => 'sales.sales_order.approval_threshold_amount',
        SalesApprovalRule::DOCUMENT_TYPE_INVOICE => 'sales.invoice.approval_threshold_amount',
        SalesApprovalRule::DOCUMENT_TYPE_DELIVERY_ORDER => 'sales.delivery_order.approval_threshold_amount',
    ];

    /**
     * Document class → document type. Drives both the per-type threshold key
     * and the rule lookup.
     *
     * @var array<class-string, string>
     */
    private const DOCUMENT_CLASS_TYPES = [
        Quotation::class => SalesApprovalRule::DOCUMENT_TYPE_QUOTATION,
        SalesOrder::class => SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER,
        \App\Models\Invoice::class => SalesApprovalRule::DOCUMENT_TYPE_INVOICE,
        DeliveryOrder::class => SalesApprovalRule::DOCUMENT_TYPE_DELIVERY_ORDER,
    ];

    /**
     * How long a gate-raised request stays actionable. Reuses the tenant
     * setting the Sales Settings screen already publishes
     * (`sales.invoice.approval_expiry_days`, issue #650) — the previous
     * hard-coded window (7 days) is the fallback when the tenant never set it.
     */
    public const EXPIRY_SETTING_KEY = 'sales.invoice.approval_expiry_days';

    public const DEFAULT_EXPIRY_DAYS = 7;

    public const REQUEST_TYPE_SALES_ORDER = 'sales_order_approval';

    public const REQUEST_TYPE_QUOTATION = 'quotation_approval';

    public const REQUEST_TYPE_INVOICE = 'invoice_threshold_approval';

    public const REQUEST_TYPE_DELIVERY_ORDER = 'delivery_order_approval';

    /**
     * Request types whose reviewers come from Sales Settings → Approval
     * Workflow. ApprovalService::userCanApprove() routes exactly these through
     * the reviewer lists below — everything else stays entitlement-driven.
     *
     * @var string[]
     */
    public const SALES_REQUEST_TYPES = [
        self::REQUEST_TYPE_QUOTATION,
        self::REQUEST_TYPE_SALES_ORDER,
        self::REQUEST_TYPE_INVOICE,
        self::REQUEST_TYPE_DELIVERY_ORDER,
        InvoiceApprovalService::REQUEST_TYPE,
    ];

    /**
     * Setting prefix per request type. Each type can override its reviewer pool
     * with `{prefix}.reviewer_user_ids` / `{prefix}.reviewer_roles`; a type
     * with nothing configured falls back to the `sales.invoice.*` pool that
     * pre-dates per-type lists (it used to cover invoices and delivery orders).
     *
     * @var array<string, string>
     */
    private const REVIEWER_SETTING_PREFIXES = [
        self::REQUEST_TYPE_QUOTATION => 'sales.quotation',
        self::REQUEST_TYPE_SALES_ORDER => 'sales.sales_order',
        self::REQUEST_TYPE_INVOICE => 'sales.invoice_threshold',
        self::REQUEST_TYPE_DELIVERY_ORDER => 'sales.delivery_order',
        InvoiceApprovalService::REQUEST_TYPE => 'sales.invoice',
    ];

    /**
     * Permission fallback for the invoice request types when no reviewer list
     * is configured.
     */
    private const INVOICE_PERMISSION_FALLBACK = 'invoices.approve';

    public static function isSalesRequestType(string $requestType): bool
    {
        return in_array($requestType, self::SALES_REQUEST_TYPES, true);
    }

    /**
     * Setting prefix that stores the reviewer list for a request type, or null
     * for request types that are not reviewer-driven.
     */
    public static function reviewerSettingPrefix(string $requestType): ?string
    {
        return self::REVIEWER_SETTING_PREFIXES[$requestType] ?? null;
    }

    /**
     * Whether a document requires approval — the threshold and every active
     * rule are OR-ed together. An empty configuration disables the gate.
     *
     * When a `$document` is supplied its per-type threshold wins over the
     * tenant-wide setting and the comparison happens in BASE currency via the
     * document's FX snapshot (#649). Without a document the legacy
     * class+face-amount behaviour is preserved (no conversion, no rules —
     * neither can be resolved without the row).
     *
     * Fail safe: a configured threshold with a document whose FX snapshot
     * cannot be resolved (foreign currency, missing/zero rate) requires
     * approval instead of guessing at a base amount.
     */
    public function requiresApproval(string $subjectClass, float $amount, ?Model $document = null): bool
    {
        $threshold = $this->threshold($document);
        $rules = $document !== null ? $this->activeRulesFor($document) : collect();

        $thresholdActive = $threshold !== null && $threshold > 0.0;

        if (! $thresholdActive && $rules->isEmpty()) {
            return false;
        }

        $baseAmount = $document !== null
            ? $this->baseCurrencyAmount($document, $amount)
            : $amount;

        if ($baseAmount === null) {
            Log::warning('SalesApprovalGate: document FX snapshot cannot be resolved; requiring approval', [
                'document_class' => $document::class,
                'document_id' => $document?->getKey(),
                'currency_code' => $document->currency_code,
                'base_currency_code' => $document->base_currency_code,
                'exchange_rate' => $document->exchange_rate,
            ]);

            return true;
        }

        if ($thresholdActive && $baseAmount >= $threshold) {
            return true;
        }

        foreach ($rules as $rule) {
            if ($this->ruleRequiresApproval($rule, $document, $baseAmount)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Approval threshold for a document: its per-type override when set,
     * otherwise the tenant-wide `sales.approval_threshold_amount` (#649).
     *
     * Without a document the tenant-wide setting is returned, preserving the
     * pre-#649 behaviour for legacy callers.
     */
    public function threshold(?Model $document = null): ?float
    {
        if ($document !== null) {
            $type = self::documentTypeFor($document);

            if ($type !== null) {
                $perType = $this->numericSetting(self::DOCUMENT_THRESHOLD_SETTING_KEYS[$type] ?? null);

                if ($perType !== null && $perType > 0.0) {
                    return $perType;
                }
            }
        }

        return $this->numericSetting(self::SETTING_KEY);
    }

    /**
     * Document type used by thresholds and rules, or null for a document the
     * gate does not know (nothing per-type can be resolved for it).
     */
    public static function documentTypeFor(Model|string $document): ?string
    {
        $class = is_string($document) ? $document : $document::class;

        return self::DOCUMENT_CLASS_TYPES[$class] ?? null;
    }

    /**
     * Document total converted to the document's base currency using its own
     * exchange-rate snapshot (#649): base = amount × exchange_rate.
     *
     * Same-currency documents are returned untouched. Returns null when the
     * document is foreign-currency but carries no usable rate — the caller
     * decides how to fail safe.
     */
    public function baseCurrencyAmount(Model $document, ?float $amount = null): ?float
    {
        $amount ??= (float) ($document->total ?? $document->amount ?? 0);

        $currency = $document->currency_code;
        $base = $document->base_currency_code;

        if ($currency === null || $base === null
            || strtoupper((string) $currency) === strtoupper((string) $base)) {
            return $amount;
        }

        $rate = $document->exchange_rate;

        if ($rate === null || (float) $rate <= 0.0) {
            return null;
        }

        return round($amount * (float) $rate, 2);
    }

    private function numericSetting(?string $key): ?float
    {
        if ($key === null) {
            return null;
        }

        try {
            $raw = Setting::getValue($key);
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: failed to read the approval threshold setting', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($raw === null || $raw === '' || ! is_numeric($raw) || (float) $raw <= 0.0) {
            return null;
        }

        return (float) $raw;
    }

    /**
     * Active rules that apply to a document, including wildcard (`*`) rules.
     * A missing rule table (a tenant whose migration has not run) yields no
     * rules rather than breaking the gate.
     *
     * @return \Illuminate\Support\Collection<int, SalesApprovalRule>
     */
    public function activeRulesFor(Model $document): \Illuminate\Support\Collection
    {
        $type = self::documentTypeFor($document);

        if ($type === null) {
            return collect();
        }

        try {
            return SalesApprovalRule::query()
                ->where('active', true)
                ->whereIn('document_type', [$type, SalesApprovalRule::DOCUMENT_TYPE_ANY])
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: failed to read approval rules', [
                'document_class' => $document::class,
                'document_type' => $type,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Evaluate one rule against the document.
     *
     * Fail safe (#649): when the rule's metric cannot be resolved — an unknown
     * condition kind, a document type that does not carry the needed columns,
     * a missing product cost, or an unresolvable customer — the rule is
     * treated as MATCHED (approval required) and the reason is logged, rather
     * than letting an unassessable document bypass the gate.
     */
    private function ruleRequiresApproval(SalesApprovalRule $rule, Model $document, float $baseAmount): bool
    {
        $metric = match ($rule->condition_kind) {
            SalesApprovalRule::CONDITION_AMOUNT => $baseAmount,
            SalesApprovalRule::CONDITION_DISCOUNT_PCT => $this->discountPercentage($document),
            SalesApprovalRule::CONDITION_MARGIN => $this->marginPercentage($document),
            SalesApprovalRule::CONDITION_CREDIT_EXPOSURE => $this->creditExposure($document, $baseAmount),
            default => null,
        };

        if ($metric === null) {
            Log::warning('SalesApprovalGate: approval rule cannot be evaluated; requiring approval', [
                'rule_id' => $rule->id,
                'condition_kind' => $rule->condition_kind,
                'document_class' => $document::class,
                'document_id' => $document->getKey(),
            ]);

            return true;
        }

        return $rule->matches($metric);
    }

    /**
     * Document discount as a percentage of its pre-discount subtotal.
     * Null when the document type carries no discount/subtotal columns
     * (e.g. delivery orders) — the gate then fails safe.
     */
    private function discountPercentage(Model $document): ?float
    {
        if (! array_key_exists('discount_amount', $document->getAttributes())
            || ! array_key_exists('subtotal', $document->getAttributes())) {
            return null;
        }

        $discount = (float) ($document->discount_amount ?? 0);
        $subtotal = (float) ($document->subtotal ?? 0);

        if ($subtotal <= 0.0) {
            return $discount > 0.0 ? null : 0.0;
        }

        return round($discount / $subtotal * 100, 4);
    }

    /**
     * Document margin as a percentage of net revenue (subtotal less header
     * discount), using the product's recorded cost per line.
     *
     * There is no historical cost snapshot on sales lines, so the current
     * product cost is authoritative here — the same source the pricing engine
     * treats as cost. Null (fail safe) when revenue is unknown, the document
     * has no product lines, or any product line's cost cannot be resolved.
     */
    private function marginPercentage(Model $document): ?float
    {
        if (! method_exists($document, 'lines')) {
            return null;
        }

        $lines = $document->lines()->get();

        if ($lines->isEmpty()) {
            return null;
        }

        $revenue = $this->netRevenue($document);

        if ($revenue === null || $revenue <= 0.0) {
            return null;
        }

        $productIds = $lines
            ->map(fn ($line) => $line->product_id !== null ? (int) $line->product_id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            return null;
        }

        $costs = Product::query()->whereIn('id', $productIds)->pluck('cost', 'id');

        $cost = 0.0;

        foreach ($lines as $line) {
            if (($line->line_type ?? 'product') !== 'product') {
                continue;
            }

            $productId = $line->product_id !== null ? (int) $line->product_id : null;

            if ($productId === null) {
                continue;
            }

            $productCost = $costs[$productId] ?? null;

            if ($productCost === null) {
                return null;
            }

            $cost += (float) ($line->quantity ?? 0) * (float) $productCost;
        }

        return round(($revenue - $cost) / $revenue * 100, 4);
    }

    /**
     * Revenue basis for the margin metric: subtotal less header discount when
     * the document carries both, else its total.
     */
    private function netRevenue(Model $document): ?float
    {
        $subtotal = $document->subtotal ?? null;

        if ($subtotal !== null) {
            return (float) $subtotal - (float) ($document->discount_amount ?? 0);
        }

        return $document->total !== null ? (float) $document->total : null;
    }

    /**
     * Prospective customer credit exposure in base currency: outstanding AR
     * plus this document's base amount. Null when the document has no customer
     * or the receivables context cannot be resolved — the gate fails safe.
     */
    private function creditExposure(Model $document, float $baseAmount): ?float
    {
        $customerId = $document->customer_id ?? null;

        if ($customerId === null) {
            return null;
        }

        try {
            $context = app(AgedReceivablesService::class)->getCustomerContext((int) $customerId);
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: failed to resolve customer credit exposure', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return round((float) ($context['outstanding'] ?? 0) + $baseAmount, 2);
    }

    /**
     * Request lifetime in days, from the tenant's Sales Settings. Falls back
     * to the previous 7-day default when unset/invalid; a configured value
     * below 1 day is ignored (a request must stay actionable at least a day).
     */
    public function expiryDays(): int
    {
        try {
            // #539: one typed reader for the key (same default, same >= 1 rule).
            return app(SalesSettings::class)->approvalExpiryDays();
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: failed to read the approval expiry setting', [
                'key' => self::EXPIRY_SETTING_KEY,
                'error' => $e->getMessage(),
            ]);

            return self::DEFAULT_EXPIRY_DAYS;
        }
    }

    /**
     * Whether this document already has an approved request of the given type
     * that still describes the document's current approval-relevant state.
     * A downstream action (invoice/DO from a quotation) may proceed once the
     * underlying document has been approved — but only while the document
     * still matches what was approved (issue #646). A document that grew past
     * its approved total, changed its discount/currency/customer or its lines
     * needs a fresh approval, even though an `approved` row exists.
     */
    public function hasApprovedRequest(Model $document, string $requestType): bool
    {
        $approved = ApprovalRequest::query()
            ->where('subject_type', $document::class)
            ->where('subject_id', $document->id)
            ->where('request_type', $requestType)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->get();

        if ($approved->isEmpty()) {
            return false;
        }

        $current = $this->snapshotFor($document);

        return $approved->contains(fn (ApprovalRequest $request) => $this->requestMatchesSnapshot($request, $current));
    }

    /**
     * Approval-relevant fingerprint of a document at request/approval time.
     *
     * Issue #646: an approval is only as good as the document it approved.
     * The snapshot is stored on the request's `metadata` (the existing JSON
     * payload column — no schema change) and re-checked against the live
     * document before the gate trusts an approved request.
     *
     * @return array{total: float, discount: float, currency_code: ?string, customer_id: ?int, line_hash: string, document_class: string, document_id: int}
     */
    public function snapshotFor(Model $document): array
    {
        return [
            'total' => (float) ($document->total ?? $document->amount ?? 0),
            'discount' => (float) ($document->discount_amount ?? 0),
            'currency_code' => $document->currency_code !== null
                ? strtoupper((string) $document->currency_code)
                : null,
            'customer_id' => $document->customer_id !== null ? (int) $document->customer_id : null,
            'line_hash' => $this->lineHash($document),
            'document_class' => $document::class,
            'document_id' => (int) $document->getKey(),
        ];
    }

    /**
     * Whether an approval request still describes the document it covers.
     * Requests without a snapshot (pre-#646 or raised by a non-gate flow)
     * cannot be verified and are treated conservatively as not matching.
     */
    public function requestMatchesDocument(ApprovalRequest $request, Model $document): bool
    {
        return $this->requestMatchesSnapshot($request, $this->snapshotFor($document));
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function requestMatchesSnapshot(ApprovalRequest $request, array $current): bool
    {
        $metadata = $request->metadata;
        $snapshot = is_array($metadata) ? ($metadata['subject_snapshot'] ?? null) : null;

        if (! is_array($snapshot) || ! array_key_exists('line_hash', $snapshot)) {
            return false;
        }

        if (($current['line_hash'] ?? null) !== ($snapshot['line_hash'] ?? null)) {
            return false;
        }

        // The header discount is an approval-relevant term.
        if (abs(((float) ($current['discount'] ?? 0)) - ((float) ($snapshot['discount'] ?? 0))) > 0.005) {
            return false;
        }

        // Currency/FX and customer change what was actually approved.
        if (($current['currency_code'] ?? null) !== ($snapshot['currency_code'] ?? null)) {
            return false;
        }

        if (($current['customer_id'] ?? null) !== ($snapshot['customer_id'] ?? null)) {
            return false;
        }

        // The document may only shrink in value: at or below the approved
        // total with identical approval-relevant content stays covered.
        return ((float) ($current['total'] ?? 0)) <= ((float) ($snapshot['total'] ?? 0)) + 0.005;
    }

    /**
     * Line columns excluded from {@see lineHash()}: row identity, parent
     * foreign keys and timestamps that do not change what was approved.
     *
     * @var string[]
     */
    private const LINE_HASH_IGNORED_COLUMNS = [
        'id',
        'quotation_id',
        'sales_order_id',
        'invoice_id',
        'delivery_order_id',
        'purchase_order_id',
        'credit_note_id',
        'debit_note_id',
        'tenant_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Content hash of a document's lines. Line order and row identity are
     * irrelevant — what matters is the approved content (product, quantity,
     * price, tax, discount, totals). Structural columns (the row id, its
     * parent FK, timestamps) are dropped so a delete/recreate of the same
     * lines hashes identically.
     */
    private function lineHash(Model $document): string
    {
        if (! method_exists($document, 'lines')) {
            return hash('sha256', '[]');
        }

        $lines = $document->lines()->get()->map(function ($line) {
            $attributes = [];
            foreach ($line->getAttributes() as $key => $value) {
                if (in_array($key, self::LINE_HASH_IGNORED_COLUMNS, true)) {
                    continue;
                }
                $attributes[$key] = $this->normalizeHashValue($value);
            }
            ksort($attributes);

            return $attributes;
        })->all();

        // Order carries no approval meaning; sort by content so reordering
        // lines does not invalidate an approval.
        usort($lines, fn (array $a, array $b) => strcmp(
            (string) json_encode($a),
            (string) json_encode($b),
        ));

        return hash('sha256', (string) json_encode($lines));
    }

    private function normalizeHashValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            // 100 vs 100.00 (in-memory int vs DB decimal) must hash alike.
            $formatted = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

            return ($formatted === '' || $formatted === '-') ? '0' : $formatted;
        }

        return (string) $value;
    }

    /**
     * Invalidate pending/approved requests whose snapshot no longer matches
     * the document, logging a chatter entry on the document for each one.
     *
     * Issue #646 chose auto-invalidation over locking the document: a gated
     * quotation/sales order stays `draft` (that is how the gate works), its
     * edit route only accepts drafts, and there is no "request changes /
     * unlock" action to reopen a locked one. Locking would make an approved
     * draft permanently uneditable; invalidating instead keeps the normal edit
     * flow and forces a fresh approval at the next gated action.
     *
     * @return int number of requests invalidated
     */
    public function invalidateStaleApprovals(
        Model $document,
        string $requestType,
        ?User $actor = null,
        ?string $reason = null,
    ): int {
        $requests = ApprovalRequest::query()
            ->where('subject_type', $document::class)
            ->where('subject_id', $document->id)
            ->where('request_type', $requestType)
            ->whereIn('status', ['pending', 'approved'])
            ->get();

        if ($requests->isEmpty()) {
            return 0;
        }

        $current = $this->snapshotFor($document);
        $invalidated = 0;

        foreach ($requests as $request) {
            if ($this->requestMatchesSnapshot($request, $current)) {
                continue;
            }

            $previousStatus = $request->status;
            $metadata = $request->metadata ?? [];
            $snapshot = is_array($metadata['subject_snapshot'] ?? null) ? $metadata['subject_snapshot'] : [];
            $metadata['invalidated'] = [
                'at' => now()->toIso8601String(),
                'by' => $actor?->id,
                'reason' => $reason ?? 'The document changed after this approval request was raised.',
                'previous_status' => $previousStatus,
                'approved_total' => (float) ($snapshot['total'] ?? $request->amount ?? 0),
                'current_total' => $current['total'],
            ];

            $request->update([
                'status' => 'withdrawn',
                'metadata' => $metadata,
            ]);

            $this->logApprovalInvalidated($request, $document, $previousStatus, $current, $actor, $reason);

            $invalidated++;
        }

        return $invalidated;
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function logApprovalInvalidated(
        ApprovalRequest $request,
        Model $document,
        string $previousStatus,
        array $current,
        ?User $actor,
        ?string $reason,
    ): void {
        try {
            $metadata = $request->metadata;
            $snapshot = is_array($metadata) ? ($metadata['subject_snapshot'] ?? []) : [];

            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                $document::class,
                (int) $document->getKey(),
                'approval_invalidated',
                "Approval request {$request->request_number} invalidated: the document changed from "
                    .number_format((float) ($snapshot['total'] ?? 0), 2)
                    .' to '.number_format((float) ($current['total'] ?? 0), 2).'.',
                [
                    'approval_request_id' => $request->id,
                    'approval_request_number' => $request->request_number,
                    'previous_status' => $previousStatus,
                    'approved_total' => (float) ($snapshot['total'] ?? 0),
                    'current_total' => (float) ($current['total'] ?? 0),
                    'reason' => $reason,
                ],
                $actor?->id,
                $actor !== null ? $actor::class : null,
            );
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: failed to log approval invalidation', [
                'approval_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Users who may approve the given sales request type.
     *
     * This is the ONE reviewer resolver for every sales request type — shared
     * by ApprovalService::userCanApprove(), InvoiceApprovalService and
     * DeliveryOrderService, so the inbox, the notifications and the list
     * "to approve" badges can never drift apart again (issue #645).
     *
     * Resolution order:
     *   1. the type's own `{prefix}.reviewer_user_ids` / `reviewer_roles`;
     *   2. the legacy `sales.invoice.*` pool, when nothing type-specific is
     *      configured (existing tenants only ever configured that pool);
     *   3. `invoices.approve` holders for the invoice request types.
     *
     * The submitter is always excluded (segregation of duties).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function resolveReviewers(string $requestType, ?User $submitter = null): \Illuminate\Support\Collection
    {
        $prefix = self::REVIEWER_SETTING_PREFIXES[$requestType] ?? null;

        if ($prefix === null) {
            return collect();
        }

        if ($this->hasConfiguredReviewers($prefix)) {
            $users = $this->reviewersFromPrefix($prefix);
        } elseif ($prefix !== 'sales.invoice' && $this->hasConfiguredReviewers('sales.invoice')) {
            $users = $this->reviewersFromPrefix('sales.invoice');
        } else {
            $users = collect();
        }

        $users = $users
            ->reject(fn (User $user) => $submitter !== null && (int) $user->id === (int) $submitter->id)
            ->unique('id')
            ->values();

        if ($users->isNotEmpty()) {
            return $users;
        }

        if ($this->isInvoiceRequestType($requestType)) {
            return $this->reviewersWithPermission(self::INVOICE_PERMISSION_FALLBACK, $submitter);
        }

        return $users;
    }

    private function hasConfiguredReviewers(string $prefix): bool
    {
        return $this->configuredList($prefix.'.reviewer_user_ids') !== []
            || $this->configuredList($prefix.'.reviewer_roles') !== [];
    }

    /**
     * @return array<int, mixed>
     */
    private function configuredList(string $key): array
    {
        try {
            $value = Setting::getValue($key, []);
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: failed to read reviewer setting', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($v) => $v !== null && $v !== ''));
    }

    private function reviewersFromPrefix(string $prefix): \Illuminate\Support\Collection
    {
        $users = collect();

        // Explicit people.
        try {
            $configuredIds = $this->configuredList($prefix.'.reviewer_user_ids');

            if ($configuredIds !== []) {
                $users = $users->merge(
                    User::whereIn('id', array_map('intval', $configuredIds))->get()
                );
            }
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: failed to resolve explicit reviewers', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
        }

        // Everyone holding one of the configured roles. Settings store
        // lowercase role slugs but the Spatie role names are Title Case, so
        // map through UserRole (the same mapping InvoiceApprovalService used).
        try {
            $configuredRoles = $this->configuredList($prefix.'.reviewer_roles');

            if ($configuredRoles !== []) {
                $spatieNames = collect($configuredRoles)
                    ->map(fn ($role) => UserRole::tryFrom(strtolower((string) $role))?->spatieName())
                    ->filter()
                    ->values()
                    ->all();

                if ($spatieNames !== []) {
                    $users = $users->merge(User::role($spatieNames)->get());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: failed to resolve role reviewers', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
        }

        return $users->unique('id')->values();
    }

    private function reviewersWithPermission(string $permission, ?User $submitter): \Illuminate\Support\Collection
    {
        try {
            $query = User::permission($permission);

            if ($submitter !== null) {
                $query->where('id', '!=', $submitter->id);
            }

            return $query->get();
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: reviewer permission fallback failed', [
                'permission' => $permission,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    private function isInvoiceRequestType(string $requestType): bool
    {
        return in_array($requestType, [self::REQUEST_TYPE_INVOICE, InvoiceApprovalService::REQUEST_TYPE], true);
    }

    /**
     * Gate a downstream action on a document.
     *
     * Returns null when the action may proceed — either the gate is disabled
     * or the document already carries an approved request that still matches
     * the document. Otherwise returns the pending `ApprovalRequest` (an
     * existing pending request is reused, never duplicated) and the caller
     * must stop and return 202. A pending request raised against an older
     * document state is invalidated first (issue #646).
     */
    public function requestIfNeeded(Model $document, string $requestType, float $amount, User $user): ?ApprovalRequest
    {
        if (! $this->requiresApproval($document::class, $amount, $document)) {
            return null;
        }

        if ($this->hasApprovedRequest($document, $requestType)) {
            return null;
        }

        $pending = ApprovalRequest::query()
            ->where('subject_type', $document::class)
            ->where('subject_id', $document->id)
            ->where('request_type', $requestType)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($pending !== null && ! $this->requestMatchesDocument($pending, $document)) {
            // The document changed after this request was raised through a
            // path that did not call invalidateStaleApprovals() (e.g. a direct
            // model write). Reusing it would have the reviewer approve a
            // document state that no longer exists — withdraw it and raise a
            // fresh request for the current state.
            $this->invalidateStaleApprovals($document, $requestType, $user);
            $pending = null;
        }

        return $pending ?? $this->createRequest($document, $user, $requestType);
    }

    /**
     * Persist an `ApprovalRequest` and return it. Caller is responsible for
     * translating the return value into the controller's response (e.g. 202).
     *
     * @param  SalesOrder|Quotation|\App\Models\Invoice  $document
     */
    public function createRequest(Model $document, User $user, string $requestType, ?string $reason = null): ApprovalRequest
    {
        $request = DB::transaction(function () use ($document, $user, $requestType, $reason) {
            $amount = (float) ($document->total ?? $document->amount ?? 0);
            $threshold = $this->threshold($document);
            $baseAmount = $this->baseCurrencyAmount($document, $amount);
            $metadata = [
                'document_total' => $amount,
                // #649: the decision is made on the base-currency equivalent
                // (document total × the document's own rate snapshot), so both
                // amounts and the threshold that applied are recorded.
                'base_amount' => $baseAmount,
                'document_currency' => $document->currency_code,
                'base_currency' => $document->base_currency_code,
                'exchange_rate' => $document->exchange_rate !== null ? (float) $document->exchange_rate : null,
                'threshold' => $threshold,
                'document_class' => $document::class,
                'document_id' => $document->id,
                // Issue #646: the document state this request approves. Stored
                // on the existing metadata JSON so no schema change is needed;
                // a request without it (legacy) is treated conservatively.
                'subject_snapshot' => $this->snapshotFor($document),
            ];

            if ($document instanceof SalesOrder) {
                $metadata['order_number'] = $document->sales_order_number ?? null;
                $metadata['customer_id'] = $document->customer_id;
            } elseif ($document instanceof Quotation) {
                $metadata['quotation_number'] = $document->quotation_number ?? null;
                $metadata['customer_id'] = $document->customer_id;
            } elseif ($document instanceof \App\Models\Invoice) {
                $metadata['invoice_number'] = $document->invoice_number ?? null;
                $metadata['customer_id'] = $document->customer_id;
            } elseif ($document instanceof \Modules\Sales\Models\DeliveryOrder) {
                $metadata['delivery_order_number'] = $document->delivery_order_number ?? null;
                $metadata['customer_id'] = $document->customer_id;
            }

            $request = ApprovalRequest::create([
                'request_type' => $requestType,
                'subject_type' => $document::class,
                'subject_id' => $document->id,
                'amount' => $amount,
                'reason' => $reason ?? ($baseAmount !== null
                    ? "Document base amount {$baseAmount} meets or exceeds the tenant approval threshold or an active approval rule."
                    : "Document total {$amount} cannot be converted to the base currency; approval is required."),
                'metadata' => $metadata,
                'requested_by' => $user->id,
                'requested_at' => now(),
                'status' => 'pending',
                'expires_at' => now()->addDays($this->expiryDays()),
            ]);

            Log::info('SalesApprovalGate: approval request created', [
                'request_id' => $request->id,
                'request_type' => $requestType,
                'subject_type' => $document::class,
                'subject_id' => $document->id,
                'amount' => $amount,
                'base_amount' => $baseAmount,
                'threshold' => $threshold,
                'expires_at' => $request->expires_at?->toIso8601String(),
            ]);

            return $request;
        });

        // Issue #650: a request nobody is told about sits invisible until
        // someone happens to open the approvals inbox. Notify the configured
        // reviewers on the same channel the invoice/DO flows use (in-app row
        // plus email). Deliberately outside the transaction: a notification
        // hiccup must never roll back the request itself.
        $this->notifyApprovers($request, $document, $user);

        return $request;
    }

    /**
     * Tell every configured reviewer that a sales document is waiting on them.
     * Mirrors InvoiceApprovalService::notifyApprovers and
     * DeliveryOrderService::notifyApprovers.
     */
    private function notifyApprovers(ApprovalRequest $request, Model $document, User $submitter): void
    {
        try {
            $reviewers = $this->resolveReviewers($request->request_type, $submitter);

            if ($reviewers->isEmpty()) {
                Log::warning('SalesApprovalGate: no reviewers configured to notify', [
                    'request_id' => $request->id,
                    'request_type' => $request->request_type,
                ]);

                return;
            }

            $label = $this->documentLabel($document);
            $number = $this->documentNumber($document);
            $amount = number_format((float) ($document->total ?? $document->amount ?? 0), 2);
            $customer = $document->customer?->name;

            $payload = array_merge([
                'subject' => trim("{$label} {$number} requires approval"),
                'body' => trim("{$label} {$number}")
                    .($customer !== null ? " for {$customer}" : '')
                    ." totalling {$amount} requires your approval.",
                'type' => $request->request_type,
                'amount' => (float) ($document->total ?? $document->amount ?? 0),
                'reason' => $request->reason,
                'approval_request_id' => $request->id,
                'approval_request_number' => $request->request_number,
                'requested_by' => $submitter->name,
            ], NotificationReferences::forDocument($document));

            foreach ($reviewers as $reviewer) {
                app(NotificationService::class)->dispatch('approval.requested', $reviewer, $payload);
            }
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalGate: failed to notify approvers of approval request', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function documentLabel(Model $document): string
    {
        return match (true) {
            $document instanceof Quotation => 'Quotation',
            $document instanceof SalesOrder => 'Sales order',
            $document instanceof \App\Models\Invoice => 'Invoice',
            $document instanceof \Modules\Sales\Models\DeliveryOrder => 'Delivery order',
            default => class_basename($document),
        };
    }

    private function documentNumber(Model $document): string
    {
        return (string) ($document->quotation_number
            ?? $document->sales_order_number
            ?? $document->invoice_number
            ?? $document->delivery_order_number
            ?? ('#'.$document->getKey()));
    }
}
