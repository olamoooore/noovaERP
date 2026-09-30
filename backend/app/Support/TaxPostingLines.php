<?php

namespace App\Support;

use App\Accounting\DTOs\PostingRequestLine;

/**
 * MRD R4.2/R4.3/R4.5 + SOP §4 (shared-service-first): translate tax
 * determinations into GL lines. One branch table used by EVERY purchase-side
 * bill poster (VendorBillService, AccountingOriginBillService) so RC, blocked
 * and withholding semantics can never diverge between paths.
 *
 * @return array<int, PostingRequestLine>
 */
class TaxPostingLines
{
    /**
     * @param  array<int, array<string, mixed>>  $allocations  from TaxDeterminationService::allocateBookedTax()
     */
    public static function fromPurchases(array $allocations, string $documentRef): array
    {
        $lines = [];

        foreach ($allocations as $allocation) {
            if ($allocation['amount'] <= 0) {
                continue;
            }

            $suffix = $allocation['tax_code'] ? " [{$allocation['tax_code']}]" : '';
            $taxCode = $allocation['tax_code'];
            $taxId = $allocation['tax_id'] ?? null;

            if (($allocation['tax_type'] ?? null) === 'withholding') {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'wht_payable',
                    accountId: null,
                    debit: 0,
                    credit: $allocation['amount'],
                    description: "Withholding deducted - {$documentRef}{$suffix}",
                    taxCode: $taxCode,
                    taxId: $taxId,
                );

                continue;
            }

            if (($allocation['treatment'] ?? null) === 'reverse_charge') {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'input_tax',
                    accountId: null,
                    debit: $allocation['amount'],
                    credit: 0,
                    taxCode: $taxCode,
                    taxId: $taxId,
                    taxDirection: 'input',
                );
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_tax_payable',
                    accountId: null,
                    debit: 0,
                    credit: $allocation['amount'],
                    taxCode: $taxCode,
                    taxId: $taxId,
                    taxDirection: 'output',
                );

                continue;
            }

            if (($allocation['creditable'] ?? true) === false) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'input_tax_blocked',
                    accountId: $allocation['blocked_account_id'] ?? null,
                    debit: $allocation['amount'],
                    credit: 0,
                    taxCode: $taxCode,
                    taxId: $taxId,
                    description: "Non-creditable tax expensed - {$documentRef}{$suffix}",
                );

                continue;
            }

            $lines[] = new PostingRequestLine(
                accountMappingKey: 'input_tax',
                accountId: null,
                debit: $allocation['amount'],
                credit: 0,
                description: "Tax - {$documentRef}{$suffix}",
                taxCode: $taxCode,
                taxId: $taxId,
            );
        }

        return $lines;
    }
}
