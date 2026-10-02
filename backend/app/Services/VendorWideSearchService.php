<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Odoo-style wide vendor search, shared by the vendor picker and any document
 * list that must find records through their vendor (e.g. vendor bills).
 *
 * Matches the vendor's own identity (name, code, email, phone, company,
 * tax/registration numbers, website, contact person), its flat address fields
 * and its linked ADDRESSES (building/label, street, city, state, ZIP, country)
 * plus its TAGS.
 */
class VendorWideSearchService
{
    /**
     * Constrain a Vendor query to rows matching the wide search term.
     */
    public function apply(Builder $query, string $search): void
    {
        $like = '%'.$search.'%';

        $query->where(function (Builder $q) use ($search, $like) {
            $q->where('name', 'ilike', $like)
                ->orWhere('code', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhere('phone', 'ilike', $like)
                ->orWhere('company', 'ilike', $like)
                ->orWhere('contact_person', 'ilike', $like)
                ->orWhere('website', 'ilike', $like)
                ->orWhere('tax_number', 'ilike', $like)
                ->orWhere('company_registration_number', 'ilike', $like)
                ->orWhere('address', 'ilike', $like)
                ->orWhere('billing_address', 'ilike', $like)
                ->orWhere('shipping_address', 'ilike', $like)
                // Address book — includes the building/label name.
                ->orWhereHas('addresses', function ($address) use ($like) {
                    $address->where('label', 'ilike', $like)
                        ->orWhere('street', 'ilike', $like)
                        ->orWhere('street2', 'ilike', $like)
                        ->orWhere('city', 'ilike', $like)
                        ->orWhere('state', 'ilike', $like)
                        ->orWhere('zip', 'ilike', $like)
                        ->orWhere('country', 'ilike', $like);
                });

            $taggedIds = app(TagService::class)->subjectIdsForTagNameSearch($search, 'vendor');
            if ($taggedIds !== []) {
                $q->orWhereIn('id', $taggedIds);
            }
        });
    }
}
