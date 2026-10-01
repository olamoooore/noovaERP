<?php

namespace App\Services;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\JournalEntry;
use Modules\AccountingCountry\Services\TaxReportingService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * #270: WHT remittance loop — books the payment leg (DR wht_payable /
 * CR cash-bank) for an authority + period, guarded against remitting more
 * than the open withheld balance (so the payable cannot silently age out).
 */
class WhtRemittanceService
{
    public function __construct(
        private readonly TaxReportingService $reporting,
    ) {}

    public function openBalance(string $authority, string $period): float
    {
        $schedule = $this->reporting->withholdingSchedule($authority, $period);

        return round(array_sum(array_column($schedule, 'open')), 2);
    }

    public function remit(
        string $authority,
        string $period,
        float $amount,
        string $reference,
        ?string $paymentDate = null,
        ?int $userId = null,
    ): JournalEntry {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Remittance amount must be positive.');
        }

        $open = $this->openBalance($authority, $period);

        if ($amount > $open + 0.01) {
            throw new \RuntimeException(
                'Remittance exceeds the open withholding balance: '.number_format($open, 2, '.', '')." open for {$authority} {$period}."
            );
        }

        $result = app(\App\Accounting\Contracts\PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'wht_remittance',
            documentId: null,
            postingDate: $paymentDate ?? now()->toDateString(),
            description: "WHT remittance {$authority} {$period} ({$reference})",
            reference: $reference,
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'wht_payable',
                    accountId: null,
                    debit: $amount,
                    credit: 0.0,
                    description: "WHT remitted — {$authority} {$period}",
                ),
                new PostingRequestLine(
                    accountMappingKey: 'cash',
                    accountId: null,
                    debit: 0.0,
                    credit: $amount,
                    description: "WHT remittance payment — {$reference}",
                ),
            ],
            userId: $userId ?? TenantUserHelper::requireTenantUserId(),
            metadata: [
                'required_permission' => 'accounting.post',
                'authority' => $authority,
                'period' => $period,
                'receipt_reference' => $reference,
            ],
        ));

        if (! $result->success) {
            throw new \RuntimeException('WHT remittance posting failed: '.$result->error);
        }

        return $result->journalEntry;
    }
}
