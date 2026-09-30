<?php

declare(strict_types=1);

namespace Modules\EventManagement\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Event booking ledger posting (P-04e): per-source posting service exactly
 * matching the platform precedent for other sales channels (POS
 * PosPostingService, web WebOrderPostingService, storefront
 * StorefrontPostingService) — this is the sanctioned pattern, not a fork.
 *
 * Shape (money-in, revenue recognition on payment confirmation):
 *   DR gateway_receivable   (when the tenant assigned one — the P-04a
 *                            clearing convention; gateway money is a
 *                            receivable until settled)
 *   CR sales_revenue         otherwise falls back to DR cash (documented
 *                            zero-config behavior, same as storefront)
 *
 * DISCLOSED LIMITATION for finance sign-off: the EventManagement domain
 * carries NO tax data on bookings/events, so no tax split is booked —
 * ticket revenue posts gross to sales_revenue. If tickets must be taxed,
 * the tax belongs in the booking calculation first; inventing a split from
 * a gross amount would corrupt statutory reporting.
 */
final class EventBookingPostingService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    public function postPaidBooking(int $bookingId, float $amount, string $currency, string $reference): ?JournalEntry
    {
        if ($amount <= 0) {
            return null; // zero-value bookings carry no GL
        }

        $existing = JournalEntryLink::query()
            ->where('source_document_type', 'event_booking')
            ->where('source_document_id', $bookingId)
            ->where('relation_type', 'post')
            ->first();

        if ($existing) {
            $entry = JournalEntry::query()->find($existing->journal_entry_id);
            if ($entry !== null) {
                return $entry;
            }
        }

        return DB::transaction(function () use ($bookingId, $amount, $reference): JournalEntry {
            $receiptKey = 'cash';
            try {
                app(\App\Accounting\Services\AccountRoleResolver::class)->resolve('gateway_receivable');
                $receiptKey = 'gateway_receivable';
            } catch (\Throwable) {
                Log::info('Event booking receipt posts to cash: Gateway Receivable not configured for this tenant.');
            }

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'event_booking',
                documentId: $bookingId,
                postingDate: now()->toDateString(),
                description: "Event booking payment ({$reference})",
                lines: [
                    new PostingRequestLine(
                        accountMappingKey: $receiptKey,
                        accountId: null,
                        debit: $amount,
                        credit: 0,
                        description: 'Event booking payment received',
                        reference: $reference,
                    ),
                    new PostingRequestLine(
                        accountMappingKey: 'sales_revenue',
                        accountId: null,
                        debit: 0,
                        credit: $amount,
                        description: 'Event ticket revenue',
                        reference: $reference,
                    ),
                ],
                reference: $reference,
                userId: TenantUserHelper::resolveTenantUserId(),
                legalEntityId: request()?->attributes->get('legal_entity_id'),
                entityBookId: request()?->attributes->get('entity_book_id'),
            ));

            if (! $result->success) {
                throw new \RuntimeException("Event booking GL posting failed for {$reference}: {$result->error}");
            }

            return $result->journalEntry ?? JournalEntry::query()->find($result->journalEntryId);
        });
    }
}
