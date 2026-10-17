<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/** Adds reviewer state and human-readable match hints to invoice list rows. */
class InvoiceListAnnotationService
{
    public function __construct(private InvoiceApprovalService $approvalService) {}

    /** Mark pending invoices as requiring review or awaiting another reviewer. */
    public function annotateApprovalView(LengthAwarePaginator $invoices, ?User $user): void
    {
        if (! $user) {
            return;
        }

        $currentUserId = (int) $user->id;
        $reviewerIds = $this->approvalService->resolveReviewers(null)->pluck('id');

        foreach ($invoices as $invoice) {
            if ($invoice->approval_status !== Invoice::APPROVAL_PENDING) {
                continue;
            }

            $requestedBy = $invoice->approvalRequest?->requested_by;
            $isSubmitter = $requestedBy !== null && (int) $requestedBy === $currentUserId;
            $isReviewer = $reviewerIds->contains($currentUserId);
            $invoice->setAttribute('approval_view', $isReviewer && ! $isSubmitter ? 'to_approve' : 'submitted');
        }
    }

    /** Add related values explaining why an invoice matched a search term. */
    public function annotateSearchMatches(LengthAwarePaginator $invoices, string $search): void
    {
        $needle = mb_strtolower($search);
        $tagMatches = app(TagService::class)->matchingTagNames(
            collect($invoices->items())->pluck('id')->map('intval')->all(),
            'invoice',
            $search,
        );
        $contains = fn (?string $value) => $value !== null && $value !== ''
            && str_contains(mb_strtolower($value), $needle);

        foreach ($invoices as $invoice) {
            $contactNames = collect();
            $customer = $invoice->relationLoaded('customer') ? $invoice->customer : null;
            if ($customer && $customer->relationLoaded('customerContacts')) {
                $contactNames = $contactNames->merge(
                    $customer->customerContacts
                        ->filter(fn ($contact) => $contains($contact->name))
                        ->pluck('name')
                );
                $customer->unsetRelation('customerContacts');
            }

            foreach (['doctor', 'requestor', 'buyer', 'billingContact', 'shippingContact'] as $relation) {
                $contact = $invoice->relationLoaded($relation) ? $invoice->getRelation($relation) : null;
                if (! $contact) {
                    continue;
                }
                $label = $contact->display_name
                    ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''))
                    ?: $contact->company_name;
                if ($contains($label)) {
                    $contactNames->push($label);
                }
                $invoice->unsetRelation($relation);
            }
            $invoice->matched_contacts = $contactNames->filter()->unique()->take(3)->values();

            if ($customer && $customer->relationLoaded('addresses')) {
                $invoice->matched_addresses = $customer->addresses
                    ->map(function ($address) {
                        $parts = [$address->label, $address->street, $address->street2, $address->city, $address->state, $address->zip, $address->country];

                        return collect($parts)->filter()->implode(', ');
                    })
                    ->filter(fn ($text) => $contains($text))
                    ->take(2)
                    ->values();
                $customer->unsetRelation('addresses');
            } else {
                $invoice->matched_addresses = collect();
            }

            $invoice->matched_lines = $invoice->relationLoaded('lines')
                ? $invoice->lines->map(function ($line) use ($contains) {
                    foreach (['description', 'model_number', 'lot_number', 'serial_number', 'product_notes'] as $field) {
                        if ($contains($line->{$field} ?? null)) {
                            return $line->{$field};
                        }
                    }

                    return null;
                })->filter()->unique()->take(2)->values()
                : collect();
            $invoice->matched_tags = $tagMatches[$invoice->id] ?? [];
        }
    }
}
