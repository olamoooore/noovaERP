<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;

/**
 * Issue #435 (CU7): result of the tiered duplicate detection for a single
 * customer write.
 *
 * `matches` lists every colliding customer once, with the strongest match
 * type/tier found for it. The overall tier is strong when any match is
 * strong, otherwise medium.
 */
final class CustomerDuplicateInspection
{
    public const TIER_STRONG = 'strong';

    public const TIER_MEDIUM = 'medium';

    /**
     * @param  list<array{customer_id:int, customer_name:?string, match_type:string, match_types:list<string>, tier:string, score:float}>  $matches
     */
    public function __construct(
        private readonly array $matches,
        private readonly bool $allowDuplicate = false,
        private readonly ?string $acknowledgementReason = null,
        private readonly ?int $subjectCustomerId = null,
    ) {}

    public function hasMatches(): bool
    {
        return $this->matches !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function matches(): array
    {
        return $this->matches;
    }

    public function tier(): ?string
    {
        foreach ($this->matches as $match) {
            if ($match['tier'] === self::TIER_STRONG) {
                return self::TIER_STRONG;
            }
        }

        return $this->matches === [] ? null : self::TIER_MEDIUM;
    }

    /**
     * The 409 the controller must return for this write, or null when the
     * write may proceed. A strong collision needs the explicit
     * `allow_duplicate` override; a medium collision needs a
     * `duplicate_acknowledgement_reason` (the override also satisfies it).
     */
    public function blockedResponse(): ?JsonResponse
    {
        if (! $this->hasMatches() || $this->allowDuplicate) {
            return null;
        }

        if ($this->tier() === self::TIER_STRONG) {
            return response()->json([
                'message' => 'A customer with the same legal identifier already exists.',
                'tier' => self::TIER_STRONG,
                'requires' => 'duplicate_override',
                'matches' => $this->matchesWithMergeEndpoints(),
                'merge_action' => $this->mergeAction(),
            ], 409);
        }

        $reason = $this->acknowledgementReason === null ? '' : trim($this->acknowledgementReason);

        if ($reason === '') {
            return response()->json([
                'message' => 'A possible duplicate customer was found. Confirm with a reason to create it anyway.',
                'tier' => self::TIER_MEDIUM,
                'requires' => 'duplicate_acknowledgement_reason',
                'matches' => $this->matchesWithMergeEndpoints(),
                'merge_action' => $this->mergeAction(),
            ], 409);
        }

        return null;
    }

    /**
     * Issue #441: each match carries the merge endpoint that keeps it as the
     * survivor (POST, gated by customers.write like any merge).
     *
     * @return list<array<string, mixed>>
     */
    private function matchesWithMergeEndpoints(): array
    {
        return array_map(
            fn (array $match) => $match + [
                'merge_endpoint' => '/api/v1/customers/'.(int) $match['customer_id'].'/merge',
            ],
            $this->matches,
        );
    }

    /**
     * Issue #441: the "merge into existing" hint. On update the record being
     * edited is the one merged away into the chosen match; on create there is
     * no record yet, so the client creates it (with the acknowledgement or
     * override above) and then merges the created id into the match.
     *
     * @return array<string, mixed>
     */
    private function mergeAction(): array
    {
        return [
            'action' => 'merge_into_existing',
            'method' => 'POST',
            'endpoint_template' => '/api/v1/customers/{customer_id}/merge',
            'merged_customer_id' => $this->subjectCustomerId,
            'requires_create_first' => $this->subjectCustomerId === null,
        ];
    }
}
