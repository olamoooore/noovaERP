<?php

namespace App\Services;

use App\Models\InboundEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Services\CommunicationEngine;

/**
 * Issue #85 — Incoming email bridge (IMAP integration).
 *
 * Ingests a parsed RFC-822 message (the actual mailbox polling is an
 * ops concern, handled by \`php artisan inbound-email:poll\`), matches it
 * to an existing record via layered strategies, and posts a
 * \`CommunicationMessage\` so the reply shows up in chatter alongside
 * outbound history.
 *
 * Match strategies (first hit wins):
 *
 *   1. Subject token — \`[Invoice-123]\`, \`[SalesOrder-42]\`, etc. The
 *      outbound \`InvoiceDeliveryService::getEmailSubject\` already
 *      stamps the invoice number in the subject; replies commonly
 *      preserve the prefix.
 *   2. In-Reply-To header — correlated to a previously stored outbound
 *      \`message_id\` in the message's headers metadata (when the
 *      Communication outbox starts stamping Message-IDs, this becomes
 *      the canonical match).
 *   3. No-match — stored with \`matched_model = null\` for manual
 *      triage; a comment is still written to the Communication outbox
 *      as a system note so the operator can see the email landed.
 *
 * The service is the single integration seam — every other piece
 * (IMAP poller, SendGrid Inbound Parse webhook, Microsoft Graph
 * subscription) just needs to produce the same payload shape.
 */
class InboundEmailService
{
    public function __construct(private CommunicationEngine $communication) {}

    /**
     * Ingest a parsed email payload. The caller (IMAP poller, webhook
     * handler, manual upload) supplies the already-decoded headers +
     * bodies; we do not parse RFC-822 here so the seam stays simple.
     */
    public function ingest(array $payload): InboundEmail
    {
        $messageId = $payload['message_id'] ?? null;
        $inReplyTo = $payload['in_reply_to'] ?? null;
        $from = $payload['from'] ?? [];
        $fromAddress = $from['address'] ?? ($payload['from_address'] ?? null);
        $fromName = $from['name'] ?? ($payload['from_name'] ?? null);
        $subject = $payload['subject'] ?? '';
        $bodyPlain = $payload['body_plain'] ?? ($payload['text'] ?? '');
        $bodyHtml = $payload['body_html'] ?? ($payload['html'] ?? null);
        $headers = $payload['headers'] ?? [];
        $tenantId = $payload['tenant_id'] ?? null;

        [$model, $recordId, $strategy, $parentId] = $this->resolveTarget($subject, $inReplyTo, $tenantId);

        return DB::transaction(function () use (
            $messageId, $inReplyTo, $fromAddress, $fromName, $subject, $bodyPlain, $bodyHtml,
            $headers, $tenantId, $model, $recordId, $strategy, $parentId
        ) {
            $inbound = InboundEmail::create([
                'tenant_id' => $tenantId,
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo,
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'subject' => $subject,
                'body_plain' => $bodyPlain,
                'body_html' => $bodyHtml,
                'headers' => $headers,
                'matched_model' => $model,
                'matched_record_id' => $recordId,
                'match_strategy' => $strategy,
                'received_at' => now(),
            ]);

            // If we matched a record, post a comment on that record so the
            // reply shows up in its chatter. Unmatched emails are stored
            // with `matched_model = null` for manual triage; they do not
            // appear in any chatter until an operator resolves them.
            $message = null;
            if ($model !== null && $recordId !== null) {
                $message = $this->communication->postComment(
                    model: $model,
                    recordId: $recordId,
                    body: $bodyPlain !== '' ? $bodyPlain : $subject,
                    authorId: 0,
                    tenantId: $tenantId,
                    // Issue #636: thread the reply under the outbound message
                    // it answers and mark it as customer-originated so the
                    // chatter files it under "Customer", not internal notes.
                    parentId: $parentId,
                    metadata: array_filter([
                        'direction' => 'inbound',
                        'channel' => 'email',
                        'from_address' => $fromAddress,
                        'from_name' => $fromName,
                    ], fn ($value) => $value !== null && $value !== ''),
                );
                $inbound->update(['communication_message_id' => $message->id]);
            }

            Log::info('InboundEmail: ingested', [
                'inbound_id' => $inbound->id,
                'matched' => $model !== null,
                'strategy' => $strategy,
                'from' => $fromAddress,
                'subject' => $subject,
            ]);

            return $inbound->fresh();
        });
    }

    /**
     * Issue #636: the 4th element is the message the reply threads under —
     * the exact outbound message for an In-Reply-To match, otherwise the
     * record's latest outbound customer message (if any).
     *
     * @return array{0: ?string, 1: ?int, 2: ?string, 3: ?int} [model, record_id, strategy, parent_id]
     */
    private function resolveTarget(string $subject, ?string $inReplyTo, ?string $tenantId): array
    {
        // Strategy 1: subject token, e.g. "[Invoice-123] ...".
        if (preg_match('/\[(Invoice|SalesOrder|Quotation|DeliveryOrder|Customer|Vendor)-(\d+)\]/i', $subject, $m)) {
            $model = $this->mapModelAlias($m[1]);
            if ($model !== null) {
                return [
                    $model,
                    (int) $m[2],
                    InboundEmail::STRATEGY_SUBJECT_TOKEN,
                    $this->latestOutboundMessageId($model, (int) $m[2], $tenantId),
                ];
            }
        }

        // Strategy 2: In-Reply-To correlation (when the outbox is wired
        // to stamp Message-IDs). Look up the outbound message via the
        // CommunicationMessage.metadata.message_id field.
        if ($inReplyTo !== null) {
            $reply = CommunicationMessage::query()
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('metadata->message_id', $inReplyTo)
                ->first();
            if ($reply !== null) {
                return [$reply->model, (int) $reply->record_id, InboundEmail::STRATEGY_IN_REPLY_TO, (int) $reply->id];
            }
        }

        return [null, null, null, null];
    }

    private function latestOutboundMessageId(string $model, int $recordId, ?string $tenantId): ?int
    {
        $id = CommunicationMessage::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('model', $model)
            ->where('record_id', $recordId)
            ->where('metadata->direction', 'outbound')
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function mapModelAlias(string $alias): ?string
    {
        return match (strtolower($alias)) {
            'invoice' => \App\Models\Invoice::class,
            'salesorder' => \Modules\Sales\Models\SalesOrder::class,
            'quotation' => \App\Models\Quotation::class,
            'deliveryorder' => \Modules\Sales\Models\DeliveryOrder::class,
            'customer' => \App\Models\Customer::class,
            'vendor' => \App\Models\Vendor::class,
            default => null,
        };
    }
}
