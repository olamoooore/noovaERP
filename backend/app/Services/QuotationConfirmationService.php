<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Quotation;
use App\Models\User;

/**
 * Issue #334 (Q5): the single quotation confirmation path.
 *
 * Extracted from QuotationController::confirm() so the internal Confirm action
 * and the public customer-portal acceptance run the SAME approval gate and the
 * same release path (QuotationReleaseService): when approval is required the
 * standard ApprovalRequest flow runs and the quotation stays in its current
 * status; otherwise the quotation is released into its confirmed Sales Order.
 */
class QuotationConfirmationService
{
    public function __construct(
        private SalesApprovalGate $approvalGate,
        private QuotationReleaseService $release,
        private QuotationExpiryGuard $expiryGuard,
    ) {}

    /**
     * @return array{requires_approval: bool, approval_request: ?ApprovalRequest, quotation: Quotation}
     *
     * @throws \App\Exceptions\QuotationExpiredException when the quotation's
     *                                                   validity date has passed
     */
    public function confirm(Quotation $quotation, User $actor): array
    {
        // Issue #335 (Q6): both the internal Confirm action and the public
        // customer-portal acceptance run this method, so the validity guard
        // lives here once. It runs before the approval gate: an expired
        // quotation must not raise an approval request.
        $this->expiryGuard->assertNotExpired($quotation);

        $approval = $this->approvalGate->requestIfNeeded(
            $quotation,
            SalesApprovalGate::REQUEST_TYPE_QUOTATION,
            (float) $quotation->total,
            $actor,
        );

        if ($approval !== null) {
            return [
                'requires_approval' => true,
                'approval_request' => $approval,
                'quotation' => $quotation->fresh(['customer', 'lines.product']),
            ];
        }

        return [
            'requires_approval' => false,
            'approval_request' => null,
            'quotation' => $this->release->release($quotation),
        ];
    }
}
