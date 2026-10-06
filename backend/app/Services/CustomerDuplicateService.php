<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactDuplicateCandidate;

/**
 * Issue #435 (CU7): tiered customer duplicate detection.
 *
 * Replaces the all-or-nothing identity guard that used to live in
 * CustomerController::ensureUniqueCustomerIdentity(): it rejected any shared
 * email/phone (blocking legitimate branches under a parent) while never
 * warning on shared names, tax numbers or registration numbers.
 *
 * Tiers:
 *  - strong: same tax_number or company_registration_number — blocked unless
 *    the caller passes `allow_duplicate`.
 *  - medium: shared email/phone across different parent groups, or same name
 *    in the same city — blocked unless the caller passes a
 *    `duplicate_acknowledgement_reason` (or the stronger override).
 *  - ignored: shared email/phone inside the same parent group (branches under
 *    the same parent, including the parent itself).
 *
 * Accepted medium/strong writes are recorded as ContactDuplicateCandidate rows
 * (dismissed + reviewed_at) so the review queue sees what a human allowed.
 *
 * #427 (identifiers by scheme) is NOT a dependency: identifier equality here is
 * direct, case-insensitive matching on tax_number /
 * company_registration_number. byStrongIdentifier() is the single seam to route
 * through a scheme registry once #427 lands.
 */
class CustomerDuplicateService
{
    private const MATCH_COLUMNS = [
        'id', 'name', 'email', 'phone', 'mobile', 'parent_id',
        'tax_number', 'company_registration_number', 'core_contact_uuid',
    ];

    public function __construct(
        private readonly CustomerContactSyncService $contacts,
    ) {}

    public function inspect(array $validated, ?Customer $ignoreCustomer = null): CustomerDuplicateInspection
    {
        /** @var array<int, array<string, mixed>> $matches */
        $matches = [];

        $email = $this->normalizeString($validated['email'] ?? null);
        if ($email !== null) {
            foreach ($this->byEmail($email, $ignoreCustomer) as $candidate) {
                if ($this->sameParentGroup($this->incomingParentId($validated, $ignoreCustomer), $candidate)) {
                    continue;
                }

                $this->addMatch($matches, $candidate, 'exact_email', CustomerDuplicateInspection::TIER_MEDIUM, 80);
            }
        }

        foreach (['phone', 'mobile'] as $field) {
            $phone = $validated[$field] ?? null;
            if (! is_string($phone) || $phone === '') {
                continue;
            }

            foreach ($this->byPhone($phone, $ignoreCustomer) as $candidate) {
                if ($this->sameParentGroup($this->incomingParentId($validated, $ignoreCustomer), $candidate)) {
                    continue;
                }

                $this->addMatch($matches, $candidate, 'exact_phone', CustomerDuplicateInspection::TIER_MEDIUM, 75);
            }
        }

        foreach ([
            'tax_number' => 'exact_tax_number',
            'company_registration_number' => 'exact_registration_number',
        ] as $field => $matchType) {
            $value = $this->normalizeString($validated[$field] ?? null);
            if ($value === null) {
                continue;
            }

            foreach ($this->byStrongIdentifier($field, $value, $ignoreCustomer) as $candidate) {
                $this->addMatch($matches, $candidate, $matchType, CustomerDuplicateInspection::TIER_STRONG, 100);
            }
        }

        $name = $this->normalizeString($validated['name'] ?? null);
        $cities = $this->incomingCities($validated);
        if ($name !== null && $cities !== []) {
            foreach ($this->byName($name, $ignoreCustomer) as $candidate) {
                if ($this->candidateInCities($candidate, $cities)) {
                    $this->addMatch($matches, $candidate, 'exact_name_city', CustomerDuplicateInspection::TIER_MEDIUM, 70);
                }
            }
        }

        return new CustomerDuplicateInspection(
            array_values($matches),
            (bool) ($validated['allow_duplicate'] ?? false),
            isset($validated['duplicate_acknowledgement_reason'])
                ? (string) $validated['duplicate_acknowledgement_reason']
                : null,
        );
    }

    /**
     * Record the collisions a human explicitly accepted (medium reason or
     * strong override) as dismissed duplicate candidates. No-op when the write
     * was clean. Idempotent.
     */
    public function recordAcknowledgedMatches(
        Customer $created,
        CustomerDuplicateInspection $inspection,
        bool $allowDuplicate,
        ?string $acknowledgementReason,
    ): void {
        if (! $inspection->hasMatches()) {
            return;
        }

        $tenantId = tenant()->id;

        if (! $created->core_contact_uuid) {
            $this->contacts->ensureOrganisationContact($created);
            $created->refresh();
        }

        $createdContact = $created->core_contact_uuid;
        if (! $createdContact) {
            return;
        }

        foreach ($inspection->matches() as $match) {
            $other = Customer::query()->find($match['customer_id']);
            if (! $other) {
                continue;
            }

            if (! $other->core_contact_uuid) {
                $this->contacts->ensureOrganisationContact($other);
                $other->refresh();
            }

            if (! $other->core_contact_uuid) {
                continue;
            }

            [$first, $second] = $createdContact <= $other->core_contact_uuid
                ? [$createdContact, $other->core_contact_uuid]
                : [$other->core_contact_uuid, $createdContact];

            ContactDuplicateCandidate::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'contact_id_1' => $first,
                    'contact_id_2' => $second,
                    'match_type' => $match['match_type'],
                    'status' => 'dismissed',
                ],
                [
                    'id' => (string) Str::uuid(),
                    'match_score' => min(100, max(0, (float) $match['score'])),
                    'match_details' => [
                        'source' => 'customer_write',
                        'tier' => $match['tier'],
                        'match_types' => $match['match_types'],
                        'customer_ids' => [(string) $created->id, (string) $other->id],
                        'acknowledgement_reason' => $acknowledgementReason,
                        'allowed_by_override' => $allowDuplicate,
                    ],
                    'reviewed_at' => now(),
                ]
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     */
    private function addMatch(array &$matches, Customer $candidate, string $matchType, string $tier, float $score): void
    {
        $id = (int) $candidate->id;

        if (isset($matches[$id])) {
            $rank = [
                CustomerDuplicateInspection::TIER_MEDIUM => 1,
                CustomerDuplicateInspection::TIER_STRONG => 2,
            ];

            if ($rank[$tier] > $rank[$matches[$id]['tier']]) {
                $matches[$id]['tier'] = $tier;
                $matches[$id]['match_type'] = $matchType;
                $matches[$id]['score'] = $score;
            }

            if (! in_array($matchType, $matches[$id]['match_types'], true)) {
                $matches[$id]['match_types'][] = $matchType;
            }

            return;
        }

        $matches[$id] = [
            'customer_id' => $id,
            'customer_name' => $candidate->name,
            'match_type' => $matchType,
            'match_types' => [$matchType],
            'tier' => $tier,
            'score' => $score,
        ];
    }

    /**
     * @return Collection<int, Customer>
     */
    private function byEmail(string $email, ?Customer $ignoreCustomer): Collection
    {
        return Customer::query()
            ->select(self::MATCH_COLUMNS)
            ->when($ignoreCustomer, fn ($query) => $query->whereKeyNot($ignoreCustomer->id))
            ->whereNotNull('email')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->get();
    }

    /**
     * Phone values are compared on digits only, matching the historical
     * normalisation (stored values are not guaranteed to be normalised yet).
     *
     * @return Collection<int, Customer>
     */
    private function byPhone(string $phone, ?Customer $ignoreCustomer): Collection
    {
        return Customer::query()
            ->select(self::MATCH_COLUMNS)
            ->when($ignoreCustomer, fn ($query) => $query->whereKeyNot($ignoreCustomer->id))
            ->where(function ($query) use ($phone) {
                $query->whereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') = ?", [$phone])
                    ->orWhereRaw("regexp_replace(coalesce(mobile, ''), '[^0-9]', '', 'g') = ?", [$phone]);
            })
            ->get();
    }

    /**
     * @return Collection<int, Customer>
     */
    private function byStrongIdentifier(string $field, string $value, ?Customer $ignoreCustomer): Collection
    {
        return Customer::query()
            ->select(self::MATCH_COLUMNS)
            ->when($ignoreCustomer, fn ($query) => $query->whereKeyNot($ignoreCustomer->id))
            ->whereNotNull($field)
            ->whereRaw("LOWER(TRIM({$field})) = ?", [$value])
            ->get();
    }

    /**
     * @return Collection<int, Customer>
     */
    private function byName(string $name, ?Customer $ignoreCustomer): Collection
    {
        return Customer::query()
            ->select(self::MATCH_COLUMNS)
            ->when($ignoreCustomer, fn ($query) => $query->whereKeyNot($ignoreCustomer->id))
            ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
            ->get();
    }

    /**
     * @param  list<string>  $cities
     */
    private function candidateInCities(Customer $candidate, array $cities): bool
    {
        return CustomerAddress::query()
            ->where('customer_id', $candidate->id)
            ->where(function ($query) use ($cities) {
                foreach ($cities as $city) {
                    $query->orWhereRaw('LOWER(TRIM(city)) = ?', [$city]);
                }
            })
            ->exists();
    }

    /**
     * The parent group root for the incoming payload: branches under the same
     * parent (and the parent itself) are one group.
     */
    private function incomingParentId(array $validated, ?Customer $ignoreCustomer): ?int
    {
        if (array_key_exists('parent_id', $validated)) {
            return ($validated['parent_id'] === null || $validated['parent_id'] === '')
                ? null
                : (int) $validated['parent_id'];
        }

        if ($ignoreCustomer && $ignoreCustomer->parent_id) {
            return (int) $ignoreCustomer->parent_id;
        }

        return null;
    }

    private function sameParentGroup(?int $incomingParentId, Customer $candidate): bool
    {
        if ($incomingParentId === null || $candidate->parent_id === null) {
            return false;
        }

        $incomingRoot = $this->rootCustomerId($incomingParentId);
        $candidateRoot = $this->rootCustomerId((int) $candidate->parent_id);

        return $incomingRoot !== null && $incomingRoot === $candidateRoot;
    }

    /**
     * Walk to the top of the parent chain (soft-deleted parents included).
     * Returns the top-most id, or null when it cannot be resolved.
     */
    private function rootCustomerId(int $customerId): ?int
    {
        $visited = [];
        $current = $customerId;

        while (! isset($visited[$current])) {
            $visited[$current] = true;
            $parent = Customer::withTrashed()->whereKey($current)->value('parent_id');

            if ($parent === null) {
                return $current;
            }

            $current = (int) $parent;
        }

        return $current;
    }

    /**
     * @return list<string>
     */
    private function incomingCities(array $validated): array
    {
        $cities = [];

        if (isset($validated['city'])) {
            $cities[] = (string) $validated['city'];
        }

        foreach (($validated['addresses'] ?? []) as $address) {
            if (is_array($address) && isset($address['city'])) {
                $cities[] = (string) $address['city'];
            }
        }

        return array_values(array_unique(array_filter(
            array_map(fn (string $city) => mb_strtolower(trim($city)), $cities),
            fn (string $city) => $city !== '',
        )));
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return $normalized !== '' ? $normalized : null;
    }
}
