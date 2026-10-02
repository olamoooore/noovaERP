<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reusable Odoo-style "wide" search for document lists (quotations, sales
 * orders, delivery orders, invoices, bills, purchase orders, credit/debit
 * notes, payments, …).
 *
 * A single term matches the document's own identity/header references, its
 * related party (customer OR supplier) through that party's full surface
 * (name, contacts, addresses, parent, tags), the document's own header
 * contacts, its line contents, upstream document numbers and its tags — and
 * then annotates each result row with WHICH related record matched so the
 * list can show "Contact: … / Tag: … / Address: … / Line: …" hints.
 *
 * Config shape (all keys optional except at least one matchable surface):
 *   [
 *     'columns'        => ['quotation_number', 'customer_reference', ...],
 *     'party'          => [
 *         'relation'  => 'customer',                 // belongsTo relation name
 *         'service'   => CustomerWideSearchService::class,
 *         'contacts'  => 'customerContacts',         // HasMany of contact people (name col)
 *         'addresses' => 'addresses',                // HasMany of addresses
 *     ],
 *     'headerContacts' => ['billingContact', 'shippingContact'],  // CoreContact belongsTo (display_name)
 *     'lines'          => ['relation' => 'lines', 'columns' => ['description', 'model_number']],
 *     'related'        => ['salesOrder' => 'sales_order_number'],  // belongsTo doc => its number column
 *     'tagAlias'       => 'quotation',
 *   ]
 */
class DocumentWideSearchService
{
    /**
     * Constrain a document query to rows matching the wide search term.
     */
    public function apply(Builder $query, string $search, array $config): void
    {
        $like = '%'.$search.'%';

        $query->where(function (Builder $q) use ($search, $like, $config) {
            foreach ($config['columns'] ?? [] as $column) {
                $q->orWhere($column, 'ilike', $like);
            }

            // Related party (customer / supplier) full surface.
            if (! empty($config['party']['relation']) && ! empty($config['party']['service'])) {
                $service = app($config['party']['service']);
                $q->orWhereHas(
                    $config['party']['relation'],
                    fn ($party) => $service->apply($party, $search)
                );
            }

            // Document's own header contacts (CoreContact / display_name).
            foreach ($config['headerContacts'] ?? [] as $relation) {
                $q->orWhereHas($relation, function ($contact) use ($like) {
                    $contact->where('display_name', 'ilike', $like)
                        ->orWhere('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhere('company_name', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like)
                        ->orWhere('phone', 'ilike', $like)
                        ->orWhere('mobile', 'ilike', $like);
                });
            }

            // Line contents.
            if (! empty($config['lines']['relation']) && ! empty($config['lines']['columns'])) {
                $q->orWhereHas($config['lines']['relation'], function ($line) use ($like, $config) {
                    $line->where(function ($inner) use ($like, $config) {
                        foreach ($config['lines']['columns'] as $i => $column) {
                            $i === 0
                                ? $inner->where($column, 'ilike', $like)
                                : $inner->orWhere($column, 'ilike', $like);
                        }
                    });
                });
            }

            // Upstream / related document numbers.
            foreach ($config['related'] ?? [] as $relation => $numberColumn) {
                $q->orWhereHas($relation, fn ($doc) => $doc->where($numberColumn, 'ilike', $like));
            }

            // Tags on the document itself.
            if (! empty($config['tagAlias'])) {
                $taggedIds = app(TagService::class)->subjectIdsForTagNameSearch($search, $config['tagAlias']);
                if ($taggedIds !== []) {
                    $q->orWhereIn('id', $taggedIds);
                }
            }
        });
    }

    /**
     * Eager-load relations needed so annotate() can explain the match without
     * N+1 queries. Merge the returned list into the query's existing with().
     */
    public function eagerLoads(array $config): array
    {
        $with = [];

        if (! empty($config['party']['relation'])) {
            $relation = $config['party']['relation'];
            if (! empty($config['party']['contacts'])) {
                $with[] = $relation.'.'.$config['party']['contacts'];
            }
            if (! empty($config['party']['addresses'])) {
                $with[] = $relation.'.'.$config['party']['addresses'];
            }
        }

        foreach ($config['headerContacts'] ?? [] as $relation) {
            $with[] = $relation;
        }

        return $with;
    }

    /**
     * Annotate each paginated row with matched_contacts / matched_tags /
     * matched_addresses / matched_lines, mirroring the customer picker hints.
     */
    public function annotate(Paginator $rows, string $search, array $config): void
    {
        $needle = mb_strtolower($search);
        $contains = fn (?string $value) => $value !== null && $value !== ''
            && str_contains(mb_strtolower($value), $needle);

        $tagMatches = [];
        if (! empty($config['tagAlias'])) {
            $tagMatches = app(TagService::class)->matchingTagNames(
                collect($rows->items())->pluck('id')->map('intval')->all(),
                $config['tagAlias'],
                $search,
            );
        }

        $partyRelation = $config['party']['relation'] ?? null;
        $contactsRelation = $config['party']['contacts'] ?? null;
        $addressesRelation = $config['party']['addresses'] ?? null;

        foreach ($rows as $row) {
            $contactNames = new Collection;

            $party = $partyRelation && $row->relationLoaded($partyRelation) ? $row->getRelation($partyRelation) : null;

            if ($party && $contactsRelation && $party->relationLoaded($contactsRelation)) {
                $contactNames = $contactNames->merge(
                    $party->getRelation($contactsRelation)
                        ->filter(fn ($contact) => $contains($contact->name))
                        ->pluck('name')
                );
                $party->unsetRelation($contactsRelation);
            }

            foreach ($config['headerContacts'] ?? [] as $relation) {
                $contact = $row->relationLoaded($relation) ? $row->getRelation($relation) : null;
                if (! $contact) {
                    continue;
                }
                $label = $contact->display_name
                    ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''))
                    ?: $contact->company_name;
                if ($contains($label)) {
                    $contactNames->push($label);
                }
                $row->unsetRelation($relation);
            }

            $row->matched_contacts = $contactNames->filter()->unique()->take(3)->values();

            if ($party && $addressesRelation && $party->relationLoaded($addressesRelation)) {
                $row->matched_addresses = $party->getRelation($addressesRelation)
                    ->map(function ($address) {
                        $parts = [$address->label, $address->street, $address->street2, $address->city, $address->state, $address->zip, $address->country];

                        return collect($parts)->filter()->implode(', ');
                    })
                    ->filter(fn ($text) => $contains($text))
                    ->take(2)
                    ->values();
                $party->unsetRelation($addressesRelation);
            } else {
                $row->matched_addresses = new Collection;
            }

            if (! empty($config['lines']['relation']) && $row->relationLoaded($config['lines']['relation'])) {
                $row->matched_lines = $row->getRelation($config['lines']['relation'])
                    ->map(function ($line) use ($contains, $config) {
                        foreach ($config['lines']['columns'] as $field) {
                            if ($contains($line->{$field} ?? null)) {
                                return $line->{$field};
                            }
                        }

                        return null;
                    })
                    ->filter()
                    ->unique()
                    ->take(2)
                    ->values();
            } else {
                $row->matched_lines = new Collection;
            }

            $row->matched_tags = $tagMatches[$row->id] ?? [];
        }
    }
}
