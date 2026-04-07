<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\Subscription;
use App\SuperAdmin\Domain\SubscriptionInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    private const PAYABLE_STATUSES = ['open', 'draft'];
    private const VOIDABLE_STATUSES = ['open', 'draft'];

    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
    ) {}

    public function generateInvoice(
        Subscription $subscription,
        string $billingInterval,
    ): SubscriptionInvoice {
        $planVersion = $subscription->planVersion()->with('prices')->first();

        $price = $planVersion?->prices
            ->where('billing_interval', $billingInterval)
            ->first();

        $subtotal = $price?->unit_amount ?? 0;

        $invoice = SubscriptionInvoice::create([
            'subscription_id'  => $subscription->id,
            'tenant_id'        => $subscription->tenant_id,
            'invoice_number'   => $this->generateInvoiceNumber(),
            'billing_interval' => $billingInterval,
            'currency'         => $price?->currency ?? 'USD',
            'subtotal'         => $subtotal,
            'tax'              => 0,
            'total'            => $subtotal,
            'status'           => 'open',
            'due_date'         => now()->addDays(14)->toDateString(),
            'period_start'     => $subscription->current_period_start,
            'period_end'       => $subscription->current_period_end,
        ]);

        $this->auditLogger->log(
            action: 'invoice.generated',
            target: $invoice,
            before: [],
            after: $invoice->toAuditSnapshot(),
        );

        return $invoice;
    }

    public function markPaid(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if (!in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot mark a [{$invoice->status}] invoice as paid. Only open or draft invoices are payable.",
            ]);
        }

        $before = $invoice->toAuditSnapshot();

        $invoice->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        // Heal past_due or paused subscription back to active when invoice is settled
        $subscription = $invoice->subscription;
        if ($subscription && in_array($subscription->status, ['past_due', 'paused'], true)) {
            $subscription->update(['status' => 'active']);
        }

        $this->auditLogger->log(
            action: 'invoice.marked_paid',
            target: $invoice->fresh(),
            before: $before,
            after: $invoice->fresh()->toAuditSnapshot(),
        );

        return $invoice->fresh();
    }

    public function voidInvoice(
        SubscriptionInvoice $invoice,
        ?string $reason = null,
    ): SubscriptionInvoice {
        if (!in_array($invoice->status, self::VOIDABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot void a [{$invoice->status}] invoice.",
            ]);
        }

        $before = $invoice->toAuditSnapshot();

        $invoice->update([
            'status' => 'void',
            'notes'  => $reason ?? $invoice->notes,
        ]);

        $this->auditLogger->log(
            action: 'invoice.voided',
            target: $invoice->fresh(),
            before: $before,
            after: $invoice->fresh()->toAuditSnapshot(),
            reason: $reason,
        );

        return $invoice->fresh();
    }

    /**
     * Atomic invoice number using a PostgreSQL sequence.
     * Eliminates the MAX(id)+1 race condition under concurrent requests.
     */
    public function generateInvoiceNumber(): string
    {
        $year     = now()->year;
        $nextVal  = DB::selectOne("SELECT nextval('invoice_number_seq') AS val")->val;
        $sequence = str_pad((string) $nextVal, 5, '0', STR_PAD_LEFT);

        return "INV-{$year}-{$sequence}";
    }
}
