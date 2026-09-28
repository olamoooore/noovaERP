<?php

namespace App\Services;

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
