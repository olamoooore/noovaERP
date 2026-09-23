<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\OrderLifecycleService;

/**
 * Issue #149: online quotation acceptance / eSignature portal.
 *
 * Provides the public, token-authenticated flow that lets a customer open a
 * quotation link and accept it with a typed-name signature (no login). The
 * acceptance transitions the SalesOrder from `draft` to `confirmed` via
 * \`OrderLifecycleService::confirm\` with \`source = 'customer_portal'\` so
 * the existing status_history records the distinction from an internal
 * staff confirmation.
 *
 * Tokens are signed random strings (\`Str::random(48)\`) with a configurable
 * 14-day expiry; an accepted order is one-shot — re-opening the same token
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
        if ($order->status !== 'draft') {
            throw new \InvalidArgumentException("Only draft orders can issue an acceptance token (current status: {$order->status}).");
        }

        if ($order->acceptance_token
            && $order->accepted_at === null
            && ($order->acceptance_expires_at === null || $order->acceptance_expires_at->isFuture())) {
            return $order->acceptance_token;
        }

        $token = Str::random(48);
        $order->update([
            'acceptance_token' => $token,
            'acceptance_expires_at' => now()->addDays(self::TOKEN_TTL_DAYS),
            'accepted_at' => null,
            'signer_name' => null,
            'signer_email' => null,
            'signature_data' => null,
            'acceptance_source' => null,
        ]);

        return $token;
    }

    public function findByToken(string $token): ?SalesOrder
    {
        return SalesOrder::where('acceptance_token', $token)->first();
    }

    /**
     * Render-safe snapshot for the public portal page.
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
     * Record a customer-portal acceptance: persist signer metadata, transition
     * the order to `confirmed`, and write an audit log entry. Idempotent on
     * already-accepted tokens.
     */
    public function accept(SalesOrder $order, string $signerName, string $signerEmail, ?string $signatureDataUrl = null, ?string $ip = null, ?string $userAgent = null): SalesOrder
    {
        if ($order->accepted_at !== null) {
            return $order;
        }

        if ($order->acceptance_expires_at && $order->acceptance_expires_at->isPast()) {
            throw new \RuntimeException('Acceptance link has expired.');
        }

        return DB::transaction(function () use ($order, $signerName, $signerEmail, $signatureDataUrl, $ip, $userAgent) {
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
}
