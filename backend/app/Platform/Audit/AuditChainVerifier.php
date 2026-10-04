<?php

namespace App\Platform\Audit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issue #912: walk the per-tenant audit hash chain and report every break.
 *
 * The verifier recomputes each stored entry's hash from its canonical fields,
 * checks that `previous_hash` matches the prior entry's `entry_hash` and that
 * `chain_sequence` is contiguous, compares the last entry with the tracked
 * head, and re-verifies every stored anchor's HMAC signature. Rows with a
 * null `entry_hash` are legacy (written before the chain existed) and are
 * counted separately instead of breaking the walk.
 */
class AuditChainVerifier
{
    public function verify(): AuditChainVerificationResult
    {
        $failures = [];

        $entries = $this->loadEntries();
        $anchors = $this->loadAnchors($failures, $anchorsChecked);

        $legacySkipped = DB::table('platform_system_logs')->whereNull('entry_hash')->count()
            + DB::table('audit_logs')->whereNull('entry_hash')->count();

        $previous = null;

        foreach ($entries as $entry) {
            if (! hash_equals($this->recomputeHash($entry), (string) $entry['entry_hash'])) {
                $failures[] = $this->failure(
                    'hash_mismatch',
                    $entry,
                    'Stored entry_hash does not match the canonical hash of the entry fields.'
                );
            }

            if ($previous === null) {
                if ($entry['previous_hash'] !== null) {
                    $anchor = $this->anchorForSequence($anchors, $entry['sequence'] - 1);

                    if ($anchor === null || ! hash_equals((string) $anchor->head_hash, (string) $entry['previous_hash'])) {
                        $failures[] = $this->failure(
                            'chain_start_unanchored',
                            $entry,
                            'The first available entry points at a previous hash outside the chain and no valid anchor covers it (rows were deleted?).'
                        );
                    }
                }
            } else {
                if ($entry['sequence'] !== $previous['sequence'] + 1) {
                    $failures[] = $this->failure(
                        'sequence_gap',
                        $entry,
                        "Expected chain_sequence {$previous['sequence']} + 1 but found {$entry['sequence']}."
                    );
                }

                if (! hash_equals((string) ($previous['entry_hash'] ?? ''), (string) ($entry['previous_hash'] ?? ''))) {
                    $failures[] = $this->failure(
                        'link_mismatch',
                        $entry,
                        "previous_hash does not match the prior entry's entry_hash."
                    );
                }
            }

            $previous = $entry;
        }

        $entriesBySequence = [];
        foreach ($entries as $entry) {
            $entriesBySequence[$entry['sequence']][] = $entry;
        }

        foreach ($anchors as $anchor) {
            foreach ($entriesBySequence[(int) $anchor->chain_sequence] ?? [] as $entry) {
                if (! hash_equals((string) $anchor->head_hash, (string) $entry['entry_hash'])) {
                    $failures[] = $this->failure(
                        'anchor_hash_mismatch',
                        $entry,
                        'A signed anchor exists for this sequence but the stored entry hash differs.'
                    );
                }
            }
        }

        $head = AuditHashChain::head();

        if ($entries !== []) {
            if ($head === null || $head->head_hash === null) {
                $failures[] = $this->failure(
                    'head_missing',
                    $previous,
                    'Entries exist but the tracked chain head is empty.'
                );
            } elseif (! hash_equals((string) $head->head_hash, (string) $previous['entry_hash'])
                || (int) $head->chain_sequence !== $previous['sequence']) {
                $failures[] = $this->failure(
                    'head_mismatch',
                    $previous,
                    'The last entry does not match the tracked chain head (rows were deleted or altered).'
                );
            }
        }

        return new AuditChainVerificationResult(
            ok: $failures === [],
            checked: count($entries),
            legacySkipped: $legacySkipped,
            failures: $failures,
            headSequence: $head !== null ? (int) $head->chain_sequence : null,
            anchorsChecked: $anchorsChecked,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadEntries(): array
    {
        $entries = [];

        foreach (DB::table('platform_system_logs')->whereNotNull('entry_hash')->get() as $row) {
            $entries[] = [
                'source' => 'platform_system_logs',
                'entry_id' => $row->id,
                'sequence' => (int) $row->chain_sequence,
                'previous_hash' => $row->previous_hash,
                'entry_hash' => $row->entry_hash,
                'tenant_id' => $row->tenant_id,
                'actor_id' => $row->user_id,
                'operation' => $row->category,
                'subject' => (string) $row->message,
                'payload' => $row->context !== null ? json_decode($row->context, true) : null,
                'created_at' => Carbon::parse($row->created_at),
            ];
        }

        foreach (DB::table('audit_logs')->whereNotNull('entry_hash')->get() as $row) {
            $entries[] = [
                'source' => 'audit_logs',
                'entry_id' => $row->id,
                'sequence' => (int) $row->chain_sequence,
                'previous_hash' => $row->previous_hash,
                'entry_hash' => $row->entry_hash,
                'tenant_id' => tenant()?->id,
                'actor_id' => $row->actor_id,
                'operation' => $row->operation,
                'subject' => $row->reference_type.'#'.$row->reference_id,
                'payload' => [
                    'before_state' => $row->before_state !== null ? json_decode($row->before_state, true) : null,
                    'after_state' => $row->after_state !== null ? json_decode($row->after_state, true) : null,
                    'metadata' => $row->metadata !== null ? json_decode($row->metadata, true) : null,
                    'source_route' => $row->source_route,
                    'idempotency_key' => $row->idempotency_key,
                    'terminal_id' => $row->terminal_id,
                    'session_id' => $row->session_id,
                    'ip_address' => $row->ip_address,
                    'user_agent' => $row->user_agent,
                ],
                'created_at' => Carbon::parse($row->created_at),
            ];
        }

        usort($entries, function (array $a, array $b): int {
            return [$a['sequence'], $a['source'], (string) $a['entry_id']]
                <=> [$b['sequence'], $b['source'], (string) $b['entry_id']];
        });

        return $entries;
    }

    private function recomputeHash(array $entry): string
    {
        return AuditHashChain::hashEntry(
            tenantId: $entry['tenant_id'],
            previousHash: $entry['previous_hash'],
            actorId: $entry['actor_id'],
            operation: $entry['operation'],
            subject: $entry['subject'],
            payload: $entry['payload'],
            createdAt: $entry['created_at'],
        );
    }

    /**
     * @param  list<array{type: string, source: string, sequence: int|null, entry_id: string|int|null, message: string}>  $failures
     * @return list<object>
     */
    private function loadAnchors(array &$failures, ?int &$anchorsChecked): array
    {
        $anchorsChecked = 0;
        $anchors = [];

        foreach (DB::table(AuditAnchorService::ANCHORS_TABLE)->get() as $row) {
            $anchoredAt = Carbon::parse($row->anchored_at);
            $expected = AuditAnchorService::digest(
                $row->tenant_id,
                (int) $row->chain_sequence,
                (string) $row->head_hash,
                (string) $row->period,
                $anchoredAt,
            );

            if (! hash_equals($expected, (string) $row->digest)) {
                $failures[] = [
                    'type' => 'anchor_signature_invalid',
                    'source' => AuditAnchorService::ANCHORS_TABLE,
                    'sequence' => (int) $row->chain_sequence,
                    'entry_id' => $row->id,
                    'message' => 'The anchor digest does not verify against the app key.',
                ];

                continue;
            }

            $anchorsChecked++;
            $anchors[] = $row;
        }

        return $anchors;
    }

    /**
     * @param  list<object>  $anchors
     */
    private function anchorForSequence(array $anchors, int $sequence): ?object
    {
        foreach ($anchors as $anchor) {
            if ((int) $anchor->chain_sequence === $sequence) {
                return $anchor;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, source: string, sequence: int|null, entry_id: string|int|null, message: string}
     */
    private function failure(string $type, array $entry, string $message): array
    {
        return [
            'type' => $type,
            'source' => $entry['source'],
            'sequence' => $entry['sequence'],
            'entry_id' => $entry['entry_id'],
            'message' => $message,
        ];
    }
}
