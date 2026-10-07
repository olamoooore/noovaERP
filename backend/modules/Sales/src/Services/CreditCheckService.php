<?php

namespace Modules\Sales\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\AgedReceivablesService;
use App\Services\ExchangeRateService;
use App\Services\SalesApprovalGate;
use App\Services\SalesSettings;

class CreditCheckService
{
    /**
     * @param  float  $committedOrdersTotal  Issue #240: sum of the customer's
     *                                       other confirmed-but-not-yet-invoiced orders. Those orders do not touch
     *                                       total_outstanding until invoiced, so without them several individually
     *                                       within-limit confirmations could cumulatively blow through the limit.
     * @param  float|null  $currentBalance  Issue #326: the customer's open AR in base currency. When null the
     *                                      stored `total_outstanding` is used (the sales-order confirm basis).
     */
    public function checkCreditLimit(Customer $customer, float $orderTotal, float $committedOrdersTotal = 0.0, ?float $currentBalance = null): CreditCheckResult
    {
        // Issue #913: a customer put on hold by an automation rule cannot
        // confirm new orders. The hold is checked before the NULL /
        // unlimited-credit early return so "unlimited" cannot bypass it.
        if ($customer->on_hold) {
            $reason = trim((string) $customer->hold_reason);

            return new CreditCheckResult(
                approved: false,
                reason: 'Customer is on hold'.($reason !== '' ? ": {$reason}" : ''),
                creditLimit: (float) ($customer->credit_limit ?? 0),
                currentBalance: $currentBalance ?? (float) ($customer->total_outstanding ?? 0),
                availableCredit: 0.0,
                orderTotal: $orderTotal,
            );
        }

        $creditLimit = $customer->credit_limit;
        $currentBalance ??= (float) ($customer->total_outstanding ?? 0);
        $exposure = $currentBalance + max(0.0, $committedOrdersTotal);

        // Issue #144: NULL means no credit control configured (explicit
        // unlimited); 0 means exactly zero credit and must block. Previously
        // both were treated as unlimited, which gave every customer created
        // without an explicit limit unlimited credit by default.
        if ($creditLimit === null) {
            return new CreditCheckResult(
                approved: true,
                reason: 'No credit limit configured (unlimited)',
                creditLimit: 0.0,
                currentBalance: $currentBalance,
                availableCredit: null,
                orderTotal: $orderTotal,
            );
        }

        $creditLimit = (float) $creditLimit;
        $availableCredit = $creditLimit - $exposure;

        if ($creditLimit <= 0.0) {
            return new CreditCheckResult(
                approved: false,
                reason: 'Credit limit is zero — prepayment is required before this order can be confirmed',
                creditLimit: 0.0,
                currentBalance: $currentBalance,
                availableCredit: min(0.0, $availableCredit),
                orderTotal: $orderTotal,
            );
        }

        $approved = ($exposure + $orderTotal) <= $creditLimit;

        return new CreditCheckResult(
            approved: $approved,
            reason: $approved
                ? 'Within credit limit'
                : "Order total {$orderTotal} would exceed available credit {$availableCredit}",
            creditLimit: $creditLimit,
            currentBalance: $currentBalance,
            availableCredit: $availableCredit,
            orderTotal: $orderTotal,
        );
    }

    /**
     * Issue #326: the credit verdict for an invoice, on the same rule as
     * sales-order confirm (checkCreditLimit) with every amount in base
     * currency:
     *   open AR (base equivalent, the D2 context-strip figure)
     * + other confirmed orders' uninvoiced remainder (#240/#384 basis; the
     *   invoice's own order is left out so it is not counted twice)
     * + this invoice's base total while it is a draft (once posted it is
     *   already in open AR).
     *
     * Returns null when the invoice has no customer. With $lockCustomer the
     * customer row is locked (call inside a transaction) so two concurrent
     * creations cannot both pass, as on sales-order confirm.
     */
    public function checkInvoice(Invoice $invoice, bool $lockCustomer = false): ?CreditCheckResult
    {
        if (! $invoice->customer_id) {
            return null;
        }

        $query = Customer::withTrashed()->whereKey($invoice->customer_id);
        $customer = $lockCustomer ? $query->lockForUpdate()->first() : $query->first();

        if (! $customer) {
            return null;
        }

        $documentTotal = $invoice->status === 'draft' ? $this->invoiceBaseTotal($invoice) : 0.0;

        return $this->checkCustomer(
            $customer,
            $documentTotal,
            $invoice->sales_order_id !== null ? (int) $invoice->sales_order_id : null,
        );
    }

    /**
     * Issue #326: the customer's credit standing in base currency, optionally
     * with a new document of $documentTotalBase on top. With a zero amount
     * this is the customer context strip's verdict (on hold, zero limit, or
     * already over the limit).
     */
    public function checkCustomer(Customer $customer, float $documentTotalBase = 0.0, ?int $exceptOrderId = null, ?float $openArBase = null): CreditCheckResult
    {
        $openArBase ??= (float) app(AgedReceivablesService::class)->getCustomerContext((int) $customer->id)['outstanding'];

        $committed = app(SalesOrderService::class)->committedOrdersTotal((int) $customer->id, $exceptOrderId);

        return $this->checkCreditLimit($customer, $documentTotalBase, $committed, $openArBase);
    }

    /**
     * Issue #326: the tenant policy for a failed invoice credit check —
     * `block` refuses create/post, `warn` lets them through with the verdict
     * attached — combined with the verdict, as the invoice API reports it.
     *
     * @return array<string, mixed>
     */
    public function invoiceVerdict(CreditCheckResult $result): array
    {
        $policy = app(SalesSettings::class)->invoiceCreditLimitPolicy();

        return $result->toArray() + [
            'policy' => $policy,
            'blocked' => ! $result->approved && $policy === SalesSettings::CREDIT_LIMIT_POLICY_BLOCK,
        ];
    }

    /**
     * The invoice total in base currency through its own rate snapshot (the
     * SalesApprovalGate convention, #649); without a usable snapshot it is
     * converted by ExchangeRateService at the invoice date, which throws when
     * no rate exists rather than guessing.
     */
    private function invoiceBaseTotal(Invoice $invoice): float
    {
        $base = app(SalesApprovalGate::class)->baseCurrencyAmount($invoice, (float) $invoice->total);

        if ($base !== null) {
            return $base;
        }

        return (float) app(ExchangeRateService::class)->convert(
            (float) $invoice->total,
            strtoupper((string) $invoice->currency_code),
            strtoupper((string) $invoice->base_currency_code),
            $invoice->invoice_date?->toDateString(),
        )['converted_amount'];
    }
}
