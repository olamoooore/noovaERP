<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ApprovalRequest;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Config-driven approval gate for Sales documents (Quotation, SalesOrder, Invoice).
 *
 * Issue #180: there was no config-driven approval gate on the Quotation/SO/Invoice
 * confirm paths. With this gate in place, a tenant can set a threshold amount; any
 * document whose total meets or exceeds it triggers an `ApprovalRequest` (in addition
 * to, and independent of, the existing user-toggled `Invoice.requires_approval`).
 *
 * Behaviour:
 *   - Setting `sales.approval_threshold_amount` is read per-tenant; null/0 disables.
 *   - When the gate fires, the caller's controller returns HTTP 202 with the
 *     `ApprovalRequest` payload so the caller knows approval is pending.
 *   - The document's own status is NOT advanced (SO/Quotation stay `draft`);
 *     an operator (or follow-up workflow job) confirms the order after approval.
 */
class SalesApprovalGate
{
    public const SETTING_KEY = 'sales.approval_threshold_amount';

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

    public function requiresApproval(string $subjectClass, float $amount): bool
    {
        $threshold = $this->threshold();
        if ($threshold === null || $threshold <= 0.0) {
            return false;
        }

        return $amount >= $threshold;
    }

    public function threshold(): ?float
    {
        $raw = Setting::getValue(self::SETTING_KEY);
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }

        return (float) $raw;
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
        if (! $this->requiresApproval($document::class, $amount)) {
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
        return DB::transaction(function () use ($document, $user, $requestType, $reason) {
            $amount = (float) ($document->total ?? $document->amount ?? 0);
            $metadata = [
                'document_total' => $amount,
                'threshold' => $this->threshold(),
                'document_class' => $document::class,
                'document_id' => $document->id,
                // Issue #646: the document state this request approves. Stored
                // on the existing metadata JSON so no schema change is needed;
                // a request without it (legacy) is treated conservatively.
                'subject_snapshot' => $this->snapshotFor($document),
            ];

            if ($document instanceof SalesOrder) {
                $metadata['order_number'] = $document->order_number ?? null;
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
                'reason' => $reason ?? "Document total {$amount} meets or exceeds the tenant approval threshold.",
                'metadata' => $metadata,
                'requested_by' => $user->id,
                'requested_at' => now(),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            Log::info('SalesApprovalGate: approval request created', [
                'request_id' => $request->id,
                'request_type' => $requestType,
                'subject_type' => $document::class,
                'subject_id' => $document->id,
                'amount' => $amount,
                'threshold' => $this->threshold(),
            ]);

            return $request;
        });
    }
}
