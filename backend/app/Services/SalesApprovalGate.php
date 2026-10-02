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
     * Whether this document already has an approved request of the given type.
     * A downstream action (invoice/DO from a quotation) may proceed once the
     * underlying document has been approved.
     */
    public function hasApprovedRequest(Model $document, string $requestType): bool
    {
        return ApprovalRequest::query()
            ->where('subject_type', $document::class)
            ->where('subject_id', $document->id)
            ->where('request_type', $requestType)
            ->where('status', 'approved')
            ->exists();
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
     * or the document already carries an approved request. Otherwise returns
     * the pending `ApprovalRequest` (an existing pending request is reused,
     * never duplicated) and the caller must stop and return 202.
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
