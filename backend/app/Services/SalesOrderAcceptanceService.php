<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\OrderLifecycleService;

/**
 * Issue #149: online quotation acceptance / eSignature portal.
 *
 * Provides the public, token-authenticated flow that lets a customer open an
 * acceptance link and accept it with a typed-name signature (no login).
 *
 * Issue #334 (Q5): the portal now serves BOTH subjects — the original
 * SalesOrder path (kept verbatim for #149) and the Quotation the send endpoint
 * issues links for. Quotation acceptance runs the same approval gate and the
 * same release path as the internal Confirm action
 * ({@see QuotationConfirmationService}) instead of forking a parallel flow.
 *
 * Tokens are signed random strings (`Str::random(48)`) with a configurable
 * 14-day expiry; an accepted document is one-shot — re-opening the same token
 * returns the recorded acceptance payload without re-running confirm.
 */
class SalesOrderAcceptanceService
{
    public const TOKEN_TTL_DAYS = 14;

    public const SOURCE_PORTAL = 'customer_portal';

    public function __construct(private OrderLifecycleService $lifecycle) {}

    /**
     * Mint a fresh acceptance token for a draft SalesOrder. Idempotent: if
     * the order already has an un-expired, un-accepted token, that token is
     * returned.
     */
    public function issueToken(SalesOrder $order): string
    {
        return $this->mintToken($order, ['draft'], 'orders');
    }

    /**
     * Issue #334: mint (or reuse) the acceptance token for a quotation about
     * to be sent. Draft and sent quotations can be (re)sent so an operator can
     * re-issue an expired link; a confirmed/cancelled quotation cannot.
     */
    public function issueQuotationToken(\App\Models\Quotation $quotation): string
    {
        return $this->mintToken($quotation, ['draft', 'sent'], 'quotations');
    }

    public function findByToken(string $token): ?SalesOrder
    {
        return SalesOrder::where('acceptance_token', $token)->first();
    }

    /**
     * Issue #334: resolve the quotation a public acceptance link belongs to.
     */
    public function findQuotationByToken(string $token): ?\App\Models\Quotation
    {
        return \App\Models\Quotation::where('acceptance_token', $token)->first();
    }

    /**
     * Render-safe snapshot for the public portal page (SalesOrder).
     */
    public function snapshot(SalesOrder $order): array
    {
        return [
            'order_number' => $order->sales_order_number,
            'status' => $order->status,
            'customer_name' => optional($order->customer)->name,
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'total' => (float) $order->total,
            'currency_code' => $order->currency_code,
            'order_date' => optional($order->order_date)->toDateString(),
            'required_date' => optional($order->required_date)->toDateString(),
            'notes' => $order->notes,
            'lines' => $order->lines->map(fn ($line) => [
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ])->all(),
            'already_accepted' => $order->accepted_at !== null,
            'signer_name' => $order->signer_name,
            'signer_email' => $order->signer_email,
            'accepted_at' => optional($order->accepted_at)->toIso8601String(),
        ];
    }

    /**
     * Issue #334: the Quotation projection of the same snapshot. The public
     * page keys on `order_number`, so the quotation number is served under
     * that shared key (plus its own `quotation_number`).
     */
    public function quotationSnapshot(\App\Models\Quotation $quotation): array
    {
        return [
            'order_number' => $quotation->quotation_number,
            'quotation_number' => $quotation->quotation_number,
            'status' => $quotation->status,
            'customer_name' => optional($quotation->customer)->name,
            'subtotal' => (float) $quotation->subtotal,
            'tax_amount' => (float) $quotation->tax_amount,
            'total' => (float) $quotation->total,
            'currency_code' => $quotation->currency_code,
            'order_date' => optional($quotation->quotation_date)->toDateString(),
            'required_date' => optional($quotation->commitment_date ?? $quotation->delivery_date)->toDateString(),
            'notes' => $quotation->notes,
            // Issue #338: optional products are listed separately (with the
            // id the customer selects them by) and are not in the totals.
            'lines' => $quotation->lines->reject(fn ($line) => (bool) $line->is_optional)->map(fn ($line) => [
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ])->values()->all(),
            'optional_lines' => $quotation->lines->filter(fn ($line) => (bool) $line->is_optional)->map(fn ($line) => [
                'id' => (int) $line->id,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'tax_amount' => (float) $line->tax_amount,
                'line_total' => (float) $line->line_total,
            ])->values()->all(),
            'already_accepted' => $quotation->accepted_at !== null,
            'signer_name' => $quotation->signer_name,
            'signer_email' => $quotation->signer_email,
            'accepted_at' => optional($quotation->accepted_at)->toIso8601String(),
            // Issue #550: what the portal must collect before accepting.
            ...$this->quotationRequirements($quotation),
        ];
    }

    /**
     * Issue #338: the ids the customer selected must all be optional lines of
     * this quotation. Returns the normalised id list.
     *
     * @param  array<int, mixed>  $lineIds
     * @return array<int, int>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateOptionalSelection(\App\Models\Quotation $quotation, array $lineIds): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));

        if ($lineIds === []) {
            return [];
        }

        $optionalIds = $quotation->lines()
            ->where('is_optional', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $unknown = array_diff($lineIds, $optionalIds);

        if ($unknown !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'optional_line_ids' => 'Only the optional products offered on this quotation can be added.',
            ]);
        }

        return $lineIds;
    }

    /**
     * Issue #338: turn the selected optional lines into ordinary quotation
     * lines and recompute the totals through the model's single totals path.
     *
     * @param  array<int, int>  $lineIds
     */
    private function selectOptionalLines(\App\Models\Quotation $quotation, array $lineIds): void
    {
        $lineIds = $this->validateOptionalSelection($quotation, $lineIds);

        if ($lineIds === []) {
            return;
        }

        $quotation->lines()->whereIn('id', $lineIds)->update(['is_optional' => false]);

        $quotation->unsetRelation('lines');
        $quotation->load('lines');
        $quotation->calculateTotals();

        // Issue #646 rule, as on an internal edit: an approval granted for
        // the previous total no longer covers the enlarged quotation.
        app(SalesApprovalGate::class)->invalidateStaleApprovals(
            $quotation,
            SalesApprovalGate::REQUEST_TYPE_QUOTATION,
        );

        $this->logChatter(
            $quotation,
            'optional_products_selected',
            'Customer added '.count($lineIds).' optional product(s) to quotation '.$quotation->quotation_number.' via the portal',
            ['line_ids' => $lineIds, 'total' => (float) $quotation->total],
        );
    }

    /**
     * Issue #550: the quotation's online-acceptance requirements. Each value
     * is the one stamped on the quotation at creation (or edited on it); a
     * quotation created before #550 (NULL) falls back to the tenant's Sales
     * Settings → Quotations value.
     *
     * @return array{require_signature: bool, require_payment: bool, prepayment_percent: float, prepayment_amount: float}
     */
    public function quotationRequirements(\App\Models\Quotation $quotation): array
    {
        $settings = app(SalesSettings::class);

        $requireSignature = $quotation->require_signature ?? $settings->quotationRequireSignature();
        $requirePayment = $quotation->require_payment ?? $settings->quotationRequirePayment();
        $percent = $quotation->prepayment_percent !== null
            ? (float) $quotation->prepayment_percent
            : $settings->quotationPrepaymentPercent();

        return [
            'require_signature' => (bool) $requireSignature,
            'require_payment' => (bool) $requirePayment,
            'prepayment_percent' => $percent,
            'prepayment_amount' => $requirePayment ? round((float) $quotation->total * $percent / 100, 2) : 0.0,
        ];
    }

    /**
     * Record a customer-portal acceptance for a SalesOrder: persist signer
     * metadata, transition the order to `confirmed`, and write an audit log
     * entry. Idempotent on already-accepted tokens.
     */
    public function accept(SalesOrder $order, string $signerName, string $signerEmail, ?string $signatureDataUrl = null, ?string $ip = null, ?string $userAgent = null): SalesOrder
    {
        if ($order->accepted_at !== null) {
            return $order;
        }

        if ($order->acceptance_expires_at && $order->acceptance_expires_at->isPast()) {
            throw new \RuntimeException('Acceptance link has expired.');
        }

        return DB::transaction(function () use ($order, $signerName, $signerEmail, $signatureDataUrl, $ip) {
            $order->update([
                'accepted_at' => now(),
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'signature_data' => $signatureDataUrl,
                'acceptance_source' => self::SOURCE_PORTAL,
            ]);

            // Drive the existing state machine so status_history records the
            // transition with the customer-portal source.
            $this->lifecycle->confirm(
                $order->fresh(),
                "Customer portal acceptance by {$signerName} ({$signerEmail})",
                self::SOURCE_PORTAL,
            );

            Log::info('SalesOrderAcceptance: order accepted via customer portal', [
                'sales_order_id' => $order->id,
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'ip' => $ip,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Issue #334: record a customer-portal acceptance for a quotation.
     *
     * Persist signer metadata (name/email/signature/IP attribution) and then
     * run the SAME confirmation path as the internal Confirm action: the
     * approval gate first (raising the standard ApprovalRequest when the
     * tenant threshold/rules require it), then the shared release into the
     * confirmed Sales Order.
     *
     * @return array{quotation: \App\Models\Quotation, requires_approval: bool, approval_request: ?\App\Models\ApprovalRequest, already_accepted: bool}
     */
    public function acceptQuotation(
        \App\Models\Quotation $quotation,
        string $signerName,
        string $signerEmail,
        ?string $signatureDataUrl = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $optionalLineIds = [],
    ): array {
        if ($quotation->accepted_at !== null) {
            return [
                'quotation' => $quotation,
                'requires_approval' => false,
                'approval_request' => null,
                'already_accepted' => true,
            ];
        }

        if ($quotation->acceptance_expires_at && $quotation->acceptance_expires_at->isPast()) {
            throw new \RuntimeException('Acceptance link has expired.');
        }

        // Issue #335 (Q6): the acceptance token may outlive the quotation's
        // own validity date. Check the shared validity guard BEFORE writing
        // signer metadata, so a refused acceptance leaves no accepted_at /
        // signer trace behind.
        app(QuotationExpiryGuard::class)->assertNotExpired($quotation);

        if (! in_array((string) $quotation->status, ['draft', 'sent'], true)) {
            throw new \RuntimeException('This quotation is no longer open for acceptance.');
        }

        $fromStatus = (string) $quotation->status;

        // Issue #338: the optional products the customer selected join the
        // quotation (and its totals) in the same unit of work as the recorded
        // acceptance — before the prepayment amount, the approval gate and
        // the release read the total.
        DB::transaction(function () use ($quotation, $optionalLineIds, $signerName, $signerEmail, $signatureDataUrl) {
            $this->selectOptionalLines($quotation, $optionalLineIds);

            $quotation->update([
                'accepted_at' => now(),
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'signature_data' => $signatureDataUrl,
                'acceptance_source' => self::SOURCE_PORTAL,
            ]);
        });

        $requirements = $this->quotationRequirements($quotation->fresh());

        $accepted = $quotation->fresh();

        // Issue #550: a quotation that requires payment is NOT confirmed by
        // the customer's acceptance alone. The agreement is recorded and the
        // quotation stays open until the prepayment is received and the
        // salesperson confirms it (online collection is not wired yet).
        if ($requirements['require_payment']) {
            $this->logChatter(
                $accepted,
                'customer_accepted',
                "Customer accepted quotation {$accepted->quotation_number} via the portal — awaiting prepayment of "
                    .number_format($requirements['prepayment_amount'], 2).' '.$accepted->currency_code,
                [
                    'signer_name' => $signerName,
                    'signer_email' => $signerEmail,
                    'ip' => $ip,
                    'acceptance_source' => self::SOURCE_PORTAL,
                    'requires_payment' => true,
                    'prepayment_percent' => $requirements['prepayment_percent'],
                    'prepayment_amount' => $requirements['prepayment_amount'],
                ],
            );

            return [
                'quotation' => $accepted,
                'requires_approval' => false,
                'requires_payment' => true,
                'prepayment_percent' => $requirements['prepayment_percent'],
                'prepayment_amount' => $requirements['prepayment_amount'],
                'approval_request' => null,
                'already_accepted' => false,
            ];
        }

        $actor = $this->resolveApprovalActor($accepted);

        $result = app(QuotationConfirmationService::class)->confirm($accepted, $actor);

        $this->logChatter(
            $accepted,
            'customer_accepted',
            "Customer accepted quotation {$accepted->quotation_number} via the portal",
            [
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'ip' => $ip,
                'acceptance_source' => self::SOURCE_PORTAL,
            ],
        );

        if (! $result['requires_approval']) {
            $confirmed = $result['quotation'];

            $this->logChatter(
                $confirmed,
                'status_changed',
                "Confirmed quotation {$confirmed->quotation_number}"
                    .($confirmed->salesOrder ? " — created sales order {$confirmed->salesOrder->sales_order_number}" : '')
                    .' from the customer portal',
                [
                    'from_state' => $fromStatus,
                    'to_state' => 'confirmed',
                    'acceptance_source' => self::SOURCE_PORTAL,
                    'sales_order_id' => $confirmed->salesOrder?->id,
                    'sales_order_number' => $confirmed->salesOrder?->sales_order_number,
                ],
            );
        }

        Log::info('SalesOrderAcceptance: quotation accepted via customer portal', [
            'quotation_id' => $accepted->id,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'ip' => $ip,
            'requires_approval' => $result['requires_approval'],
        ]);

        return [
            'quotation' => $result['quotation'],
            'requires_approval' => $result['requires_approval'],
            'requires_payment' => false,
            'approval_request' => $result['approval_request'],
            'already_accepted' => false,
        ];
    }

    /**
     * Shared token minting: one token per pending acceptance, reused while
     * valid, and reset whenever a fresh one is minted.
     *
     * @param  string[]  $allowedStatuses
     */
    private function mintToken(Model $subject, array $allowedStatuses, string $label): string
    {
        if (! in_array((string) $subject->status, $allowedStatuses, true)) {
            throw new \InvalidArgumentException(
                'Only '.implode('/', $allowedStatuses)." {$label} can issue an acceptance token (current status: {$subject->status})."
            );
        }

        if ($subject->acceptance_token
            && $subject->accepted_at === null
            && ($subject->acceptance_expires_at === null || $subject->acceptance_expires_at->isFuture())) {
            return $subject->acceptance_token;
        }

        $token = Str::random(48);
        $expiresAt = now()->addDays(self::TOKEN_TTL_DAYS);

        $subject->update([
            'acceptance_token' => $token,
            'acceptance_expires_at' => $expiresAt,
            'accepted_at' => null,
            'signer_name' => null,
            'signer_email' => null,
            'signature_data' => null,
            'acceptance_source' => null,
        ]);

        // Issue #466 (DO14): public acceptance links carry no tenant in the
        // host, so the token → tenant mapping is registered centrally at
        // issuance for the one indexed lookup on the public route.
        app(DocumentTokenRegistry::class)->register(
            $token,
            tenant('id'),
            $subject instanceof \App\Models\Quotation
                ? DocumentTokenRegistry::TYPE_QUOTATION
                : DocumentTokenRegistry::TYPE_SALES_ORDER,
            $subject->getKey(),
            $expiresAt,
        );

        return $token;
    }

    /**
     * Who raised the approval request a public acceptance triggers. The
     * document's creator is the natural submitter; a tenant with no creator
     * recorded (historical/imported quotes) falls back to the tenant's first
     * user. Without ANY user the gate cannot run — fail loudly instead of
     * silently skipping the approval.
     */
    private function resolveApprovalActor(\App\Models\Quotation $quotation): \App\Models\User
    {
        $creator = $quotation->createdBy;

        if ($creator !== null) {
            return $creator;
        }

        $fallback = \App\Models\User::query()->orderBy('id')->first();

        if ($fallback === null) {
            throw new \RuntimeException(
                'The quotation requires approval but no internal user is available to raise the approval request.'
            );
        }

        return $fallback;
    }

    /**
     * Mirror a portal event into chatter. Never throws — logging must not
     * break the acceptance flow.
     */
    private function logChatter(Model $subject, string $event, string $description, array $properties = []): void
    {
        try {
            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                $subject::class,
                (int) $subject->getKey(),
                $event,
                $description,
                $properties,
                null,
                null,
            );
        } catch (\Throwable $e) {
            Log::warning('SalesOrderAcceptance: chatter mirror failed', [
                'subject' => $subject::class,
                'subject_id' => $subject->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
