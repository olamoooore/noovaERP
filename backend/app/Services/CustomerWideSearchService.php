<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Odoo-style wide customer search, shared by the customer picker and by any
 * document list that must find records through their customer.
 *
 * Mirrors res.partner name_search behaviour: the record's own identity (name,
 * email, phone/mobile, code, website, tax/registration numbers), its company
 * field, its linked CONTACT PEOPLE (name, job title, direct details), its
 * ADDRESSES (building/label, street, city, state, ZIP, country), its
 * parent/company hierarchy, and its TAGS (categories).
 */
class CustomerWideSearchService
{
    /**
     * Constrain a Customer query to rows matching the wide search term.
     */
    public function apply(Builder $query, string $search): void
    {
        $like = "%{$search}%";

        $query->where(function (Builder $q) use ($search, $like) {
            $q->where('name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhere('phone', 'ilike', $like)
                ->orWhere('mobile', 'ilike', $like)
                ->orWhere('company', 'ilike', $like)
                ->orWhere('code', 'ilike', $like)
                ->orWhere('website', 'ilike', $like)
                ->orWhere('tax_number', 'ilike', $like)
                ->orWhere('company_registration_number', 'ilike', $like)
                // Contact people.
                ->orWhereHas('customerContacts', function ($contact) use ($like) {
                    $contact->where('name', 'ilike', $like)
                        ->orWhere('job_title', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like)
                        ->orWhere('phone', 'ilike', $like)
                        ->orWhere('mobile', 'ilike', $like);
                })
                // Addresses — includes the building/label name.
                ->orWhereHas('addresses', function ($address) use ($like) {
                    $address->where('label', 'ilike', $like)
                        ->orWhere('street', 'ilike', $like)
                        ->orWhere('street2', 'ilike', $like)
                        ->orWhere('city', 'ilike', $like)
                        ->orWhere('state', 'ilike', $like)
                        ->orWhere('zip', 'ilike', $like)
                        ->orWhere('country', 'ilike', $like);
                })
                // Child accounts match their parent company too (Odoo's
                // complete_name behaviour).
                ->orWhereHas('parent', fn ($parent) => $parent->where('name', 'ilike', $like)->orWhere('company', 'ilike', $like));

            $taggedIds = app(TagService::class)->subjectIdsForTagNameSearch($search, 'customer');
            if ($taggedIds !== []) {
                $q->orWhereIn('id', $taggedIds);
            }
        });
    }
}
