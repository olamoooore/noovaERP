<?php

namespace App\Accounting;

/**
 * Issue #815 (EP4) — which buyer type a party resolves to, decided in ONE
 * place.
 *
 * The MyInvois mapper used to own this precedence inline (explicit buyer_type
 * → type → is_individual → default individual), and the new B2C-consolidation
 * gate (#815 AC1) needs the exact same answer to decide whether a document
 * without a buyer TIN is a B2C receipt to hold for the monthly consolidated
 * e-invoice or a business buyer that must still fail #664's no-TIN check.
 * Two copies of this precedence would drift — the mapper delegates here.
 */
final class BuyerTypeResolver
{
    public const BUSINESS = 'business';

    public const INDIVIDUAL = 'individual';

    public const GOVERNMENT = 'government';

    public const FOREIGN = 'foreign';

    /** @var string[] */
    public const TYPES = [
        self::BUSINESS,
        self::INDIVIDUAL,
        self::GOVERNMENT,
        self::FOREIGN,
    ];

    /**
     * Precedence: an explicit buyer_type wins, then the party type, then the
     * is_individual flag, then — deliberately — individual: an untyped party
     * is the walk-in/consumer case, which is what B2C consolidation is for.
     */
    public static function resolve(object $party): string
    {
        $explicit = strtolower(trim((string) ($party->buyer_type ?? '')));
        if (in_array($explicit, self::TYPES, true)) {
            return $explicit;
        }

        $type = strtolower(trim((string) ($party->type ?? '')));
        if ($type === 'company') {
            return self::BUSINESS;
        }
        if ($type === 'individual') {
            return self::INDIVIDUAL;
        }
        if (in_array($type, self::TYPES, true)) {
            return $type;
        }

        if (($party->is_individual ?? null) === false) {
            return self::BUSINESS;
        }

        return self::INDIVIDUAL;
    }
}
