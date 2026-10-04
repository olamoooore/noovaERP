<?php

namespace App\Support;

use App\Models\Customer;
use Modules\Contact\src\Models\ContactTaxProfile;

/**
 * #309 (X9): the single consultation point for customer tax profiles.
 *
 * Mirrors VendorTaxProfileResolver on the sales side: customer exemption
 * (within its validity window) and its reason are resolved here and shared by
 * invoice creation and tax determination — never duplicated.
 */
class CustomerTaxProfileResolver
{
    /** @var array<int, ContactTaxProfile|null> */
    private static array $cache = [];

    public static function forCustomer(?int $customerId): ?ContactTaxProfile
    {
        if (! $customerId) {
            return null;
        }

        if (array_key_exists($customerId, self::$cache)) {
            return self::$cache[$customerId];
        }

        $customer = Customer::withTrashed()->find($customerId);

        if (! $customer || empty($customer->core_contact_uuid)) {
            return self::$cache[$customerId] = null;
        }

        return self::$cache[$customerId] = ContactTaxProfile::query()
            ->where('contact_id', $customer->core_contact_uuid)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * #309 (X9): an exemption applies only inside its validity window — a
     * profile whose exemption_expiry is before the document date is no longer
     * exempt. A null expiry means the exemption does not expire.
     */
    public static function isExempt(?int $customerId, \DateTimeInterface|string|null $date = null): bool
    {
        $profile = self::forCustomer($customerId);

        if (! $profile || ! $profile->is_tax_exempt) {
            return false;
        }

        if ($profile->exemption_expiry === null) {
            return true;
        }

        return $profile->exemption_expiry->toDateString() >= self::dateString($date);
    }

    /**
     * The exemption reason recorded on the profile, or null when the customer
     * is not exempt (or the exemption has expired). A profile flagged exempt
     * without a reason still suppresses tax — it just has no reason text.
     */
    public static function exemptionReason(?int $customerId, \DateTimeInterface|string|null $date = null): ?string
    {
        if (! self::isExempt($customerId, $date)) {
            return null;
        }

        $reason = self::forCustomer($customerId)?->exemption_reason;

        return filled($reason) ? (string) $reason : null;
    }

    private static function dateString(\DateTimeInterface|string|null $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return ($date !== null && $date !== '') ? (string) $date : now()->toDateString();
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
