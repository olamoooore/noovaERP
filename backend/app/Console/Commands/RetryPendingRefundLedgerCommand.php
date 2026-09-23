<?php

namespace App\Console\Commands;

use App\Services\PaymentRefundService;
use Illuminate\Console\Command;

/**
 * Issue #110: retry the ledger side of gateway-accepted refunds whose GL
 * posting failed. The gateway refund already succeeded, so this command only
 * posts the GL reversal / invoice updates and marks the refund completed —
 * it never calls the gateway again.
 */
class RetryPendingRefundLedgerCommand extends Command
{
    protected $signature = 'payments:retry-pending-refund-gl';

    protected $description = 'Post the ledger side for refunds the gateway accepted but whose GL posting failed';

    public function handle(PaymentRefundService $refunds): int
    {
        $result = $refunds->retryPendingRefundLedger();

        if ($result['repaired'] === [] && $result['failed'] === []) {
            $this->info('No gateway-accepted refunds are pending ledger posting.');

            return self::SUCCESS;
        }

        if ($result['repaired'] !== []) {
            $this->info('Repaired refund(s): '.implode(', ', $result['repaired']));
        }

        foreach ($result['failed'] as $failure) {
            $this->error("Refund #{$failure['refund_id']} still failing: {$failure['error']}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
