<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Vendor;
use App\Models\VendorAddress;

/**
 * ADR-003B W6 step 2 (tax) — REPORT ONLY, input for the tax agent.
 *
 * Today TaxDeterminationService::jurisdictionForCustomer() takes the
 * country of the customer's DEFAULT billing address in `customer_addresses`
 * and passes it through TaxPackRegistry::jurisdictionForCountry(), which
 * returns unrecognised text unchanged ("Malaysia" → "MALAYSIA"). Documents
 * use CustomerAddressResolver instead (default billing → any billing →
 * older text → primary contact).
 *
 * For every customer this lists the jurisdiction tax uses today next to the
 * one the shared address rule would give, and why they differ.
 *
 * Vendors (step 4 of the tax hostile review): jurisdictionForVendor() takes
 * the country of the vendor's default address of ANY type, else its first
 * address, through the same function — so bills, vendor credit/debit notes
 * record input tax under that text (TaxBillVendorJurisdictionTest). Each
 * vendor whose country is not a 2-letter code, or comes from a non-default
 * address, is listed. It changes
 * nothing; the rule to adopt is a statutory decision (engineering guardrails: tax agent,
 * two approvals).
 */
class CustomerTaxJurisdictionPlanner
{
    public function __construct(
        private readonly TaxPackRegistry $packs,
        private readonly CustomerAddressResolver $addresses,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(string $tenantId): array
    {
        $rows = [];
        $unrecognised = [];

        Customer::withTrashed()->orderBy('id')->chunkById(500, function ($customers) use (&$rows, &$unrecognised) {
            $defaults = CustomerAddress::whereIn('customer_id', $customers->pluck('id'))
                ->where('type', 'billing')
                ->where('is_default', true)
                ->orderBy('id')
                ->get()
                ->groupBy('customer_id');

            foreach ($customers as $customer) {
                $currentCountry = $this->clean(($defaults[$customer->id] ?? collect())->first()?->country);
                $resolverCountry = $this->clean($this->addresses->billing($customer)['country'] ?? null);

                $current = $currentCountry !== null ? $this->packs->jurisdictionForCountry($currentCountry) : null;
                $resolver = $resolverCountry !== null && $this->isCode($resolverCountry)
                    ? $this->packs->jurisdictionForCountry($resolverCountry)
                    : null;

                $flags = [];
                if ($currentCountry !== null && ! $this->isCode($currentCountry)) {
                    // Today this text BECOMES the jurisdiction (export path on
                    // invoices, the posting jurisdiction on POS/web orders).
                    $flags[] = 'unrecognised_country_text';
                    $unrecognised[$currentCountry] = ($unrecognised[$currentCountry] ?? 0) + 1;
                }
                if ($currentCountry === null && $resolverCountry !== null) {
                    $flags[] = 'country_only_outside_default_billing';
                }
                if ($resolverCountry !== null && ! $this->isCode($resolverCountry) && $resolverCountry !== $currentCountry) {
                    $flags[] = 'resolver_country_unrecognised';
                }
                if ($current !== $resolver) {
                    $flags[] = 'jurisdiction_would_change';
                }

                if ($flags === []) {
                    continue;
                }

                $rows[] = [
                    'customer_id' => $customer->id,
                    'customer_code' => $customer->code,
                    'customer_deleted' => $customer->trashed(),
                    'current_country' => $currentCountry,
                    'current_jurisdiction' => $current,
                    'resolver_country' => $resolverCountry,
                    'resolver_jurisdiction' => $resolver,
                    'flags' => $flags,
                ];
            }
        });

        arsort($unrecognised);
        $count = fn (string $flag) => count(array_filter($rows, fn ($row) => in_array($flag, $row['flags'], true)));
        [$vendorRows, $vendorUnrecognised] = $this->vendors();
        $vendorCount = fn (string $flag) => count(array_filter($vendorRows, fn ($row) => in_array($flag, $row['flags'], true)));

        return [
            'tenant_id' => $tenantId,
            'generated_at' => now()->toIso8601String(),
            'mode' => 'report_only',
            'summary' => [
                'customers_flagged' => count($rows),
                'unrecognised_country_text' => $count('unrecognised_country_text'),
                'country_only_outside_default_billing' => $count('country_only_outside_default_billing'),
                'resolver_country_unrecognised' => $count('resolver_country_unrecognised'),
                'jurisdiction_would_change' => $count('jurisdiction_would_change'),
                'unrecognised_values' => $unrecognised,
            ],
            'customers' => $rows,
            'vendor_summary' => [
                'vendors_flagged' => count($vendorRows),
                'unrecognised_country_text' => $vendorCount('unrecognised_country_text'),
                'country_from_non_default_address' => $vendorCount('country_from_non_default_address'),
                'unrecognised_values' => $vendorUnrecognised,
            ],
            'vendors' => $vendorRows,
        ];
    }

    /**
     * The vendor rule as jurisdictionForVendor() applies it today.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    private function vendors(): array
    {
        $rows = [];
        $unrecognised = [];

        Vendor::withTrashed()->orderBy('id')->chunkById(500, function ($vendors) use (&$rows, &$unrecognised) {
            $addresses = VendorAddress::whereIn('vendor_id', $vendors->pluck('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('vendor_id');

            foreach ($vendors as $vendor) {
                $list = $addresses[$vendor->id] ?? collect();
                $default = $list->firstWhere('is_default', true);
                $used = $default ?? $list->first();
                $country = $this->clean($used?->country);

                if ($country === null) {
                    continue;
                }

                $flags = [];
                if (! $this->isCode($country)) {
                    $flags[] = 'unrecognised_country_text';
                    $unrecognised[$country] = ($unrecognised[$country] ?? 0) + 1;
                }
                if ($default === null) {
                    $flags[] = 'country_from_non_default_address';
                }

                if ($flags === []) {
                    continue;
                }

                $rows[] = [
                    'vendor_id' => $vendor->id,
                    'vendor_code' => $vendor->code,
                    'vendor_deleted' => $vendor->trashed(),
                    'address_type' => $used->type,
                    'current_country' => $country,
                    'current_jurisdiction' => $this->packs->jurisdictionForCountry($country),
                    'flags' => $flags,
                ];
            }
        });

        arsort($unrecognised);

        return [$rows, $unrecognised];
    }

    private function clean(?string $country): ?string
    {
        $country = trim((string) $country);

        return $country === '' ? null : $country;
    }

    /** A 2-letter code is what jurisdictionForCountry() is built for. */
    private function isCode(string $country): bool
    {
        return preg_match('/^[A-Za-z]{2}$/', $country) === 1;
    }
}
