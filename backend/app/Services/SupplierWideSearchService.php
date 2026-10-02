<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Odoo-style wide supplier search, shared by the supplier picker and any
 * document list that must find records through their supplier (e.g. purchase
 * orders).
 *
 * Suppliers are flat records (no child contacts/addresses): match the
 * identity (name, code, contact person, email, phone, website), the address
 * fields and the tax/registration numbers.
 */
class SupplierWideSearchService
{
    /**
     * Constrain a Supplier query to rows matching the wide search term.
     */
    public function apply(Builder $query, string $search): void
    {
        $like = '%'.$search.'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'ilike', $like)
                ->orWhere('code', 'ilike', $like)
                ->orWhere('contact_name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhere('phone', 'ilike', $like)
                ->orWhere('website', 'ilike', $like)
                ->orWhere('address_line_1', 'ilike', $like)
                ->orWhere('address_line_2', 'ilike', $like)
                ->orWhere('city', 'ilike', $like)
                ->orWhere('state', 'ilike', $like)
                ->orWhere('postal_code', 'ilike', $like)
                ->orWhere('country', 'ilike', $like)
                ->orWhere('tax_id', 'ilike', $like);
        });
    }
}
