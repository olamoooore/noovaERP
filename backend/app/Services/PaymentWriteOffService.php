<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\Payment;

/**
 * Issue #494: payment difference handling — Odoo's "keep open" vs "mark as
 * fully paid". A short payment marked fully paid writes the difference
 * (invoice balance − amount − cash discount) off to the expense account of a
 * tenant-configured write-off reason, capped by the tenant's tolerance.
 *
 * The write-off travels on the payment (like the #478 cash discount): it is
 * posted as an extra line of the payment's own journal entry by
 * PaymentPostingService (PostingEngine), settles the invoice together with the
 * cash, and is undone by the same payment corrections.
 */
class PaymentWriteOffService
{
    public const KEEP_OPEN = 'keep_open';

    public const WRITE_OFF = 'write_off';

    public const MODES = [self::KEEP_OPEN, self::WRITE_OFF];

    public function __construct(private SalesSettings $settings) {}

    /**
     * The write-off attributes a new payment carries. Keep open (the default)
     * carries none; write off resolves the reason's account and checks the
     * difference against the tolerance.
     *
     * @return array{write_off_amount: ?float, write_off_account_id: ?int, write_off_reason: ?string, write_off_label: ?string}
     *
     * @throws \InvalidArgumentException when the write-off is not allowed
     */
    public function resolveForPayment(Invoice $invoice, float $amount, float $discount, ?string $mode, ?string $reasonCode, ?string $label): array
    {
        $none = [
            'write_off_amount' => null,
            'write_off_account_id' => null,
            'write_off_reason' => null,
            'write_off_label' => null,
        ];

        if (($mode ?? self::KEEP_OPEN) !== self::WRITE_OFF) {
            return $none;
        }

        $difference = round((float) $invoice->balance - $amount - $discount, 2);
        if ($difference <= 0) {
            throw new \InvalidArgumentException('There is no payment difference to write off: the payment already settles the invoice balance.');
        }

        $this->assertWithinTolerance($difference);

        $reason = $reasonCode !== null ? $this->settings->paymentWriteOffReason($reasonCode) : null;
        if ($reason === null) {
            throw new \InvalidArgumentException('Choose a configured payment write-off reason.');
        }

        $account = $this->writeOffAccount($reason['account_id']);
        $label = $label !== null ? trim($label) : '';

        return [
            'write_off_amount' => $difference,
            'write_off_account_id' => (int) $account->id,
            'write_off_reason' => $reason['code'],
            'write_off_label' => $label !== '' ? $label : $reason['label'],
        ];
    }

    /**
     * Posting-time re-check (the draft may have waited): the write-off must
     * still be within the tolerance, its account still an active expense
     * account, and cash + discount + write-off must still settle exactly the
     * invoice's open balance — otherwise the difference is stale and the
     * payment must be re-recorded rather than over- or under-settling.
     *
     * @throws \RuntimeException when the write-off can no longer be posted
     */
    public function assertPostable(Payment $payment, ?Invoice $invoice): void
    {
        $writeOff = round((float) ($payment->write_off_amount ?? 0), 2);
        if ($writeOff <= 0) {
            return;
        }

        if ($invoice === null) {
            throw new \RuntimeException("Payment {$payment->payment_number} carries a write-off but no invoice to settle.");
        }

        try {
            $this->assertWithinTolerance($writeOff);
            $this->writeOffAccount((int) $payment->write_off_account_id);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $settles = round((float) $payment->amount + (float) ($payment->discount_amount ?? 0) + $writeOff, 2);
        $balance = round((float) $invoice->balance, 2);

        if (abs($settles - $balance) >= 0.005) {
            throw new \RuntimeException(
                "Payment {$payment->payment_number} writes off {$writeOff} to settle invoice {$invoice->invoice_number} in full, ".
                "but the invoice's open balance is now {$balance}. Record the payment again."
            );
        }
    }

    private function assertWithinTolerance(float $difference): void
    {
        $tolerance = $this->settings->paymentWriteOffTolerance();

        if ($tolerance <= 0) {
            throw new \InvalidArgumentException('Payment differences cannot be written off: no payment write-off tolerance is configured in Sales Settings.');
        }

        if ($difference - $tolerance > 0.00001) {
            throw new \InvalidArgumentException(
                'The payment difference ('.number_format($difference, 2, '.', '').') exceeds the write-off tolerance ('.
                number_format($tolerance, 2, '.', '').'). Keep the invoice open instead.'
            );
        }
    }

    private function writeOffAccount(int $accountId): ChartOfAccount
    {
        $account = ChartOfAccount::query()->whereKey($accountId)->where('is_active', true)->first();

        if (! $account) {
            throw new \InvalidArgumentException('The write-off reason account is not an active chart-of-accounts account.');
        }

        if ($account->type !== 'expense') {
            throw new \InvalidArgumentException('The write-off reason account must be an expense account.');
        }

        return $account;
    }
}
