<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * Issue #553 (SALES-ODOO): creation defaults for new sales documents.
 *
 * "Default salesperson = current user" and "default team per user" are tenant
 * settings; this service is the one place the creation paths read them from,
 * so a quotation, a sales order (from any channel) and a converted document
 * all resolve the same defaults. Explicit caller values always win.
 */
class SalesDefaultsService
{
    public const MODE_CURRENT_USER = 'current_user';

    public const MODE_NONE = 'none';

    public const SETTING_SALESPERSON_MODE = 'sales.defaults.salesperson_mode';

    public const SETTING_TEAM_BY_USER = 'sales.defaults.team_by_user';

    public function salespersonMode(): string
    {
        $mode = Setting::getValue(self::SETTING_SALESPERSON_MODE, self::MODE_NONE);

        return in_array($mode, [self::MODE_CURRENT_USER, self::MODE_NONE], true)
            ? (string) $mode
            : self::MODE_NONE;
    }

    /**
     * The salesperson a new document should get for this user: the user's name
     * when the tenant enabled the current-user default, otherwise null.
     */
    public function defaultSalesperson(?int $userId): ?string
    {
        if ($userId === null || $this->salespersonMode() !== self::MODE_CURRENT_USER) {
            return null;
        }

        $name = User::query()->whereKey($userId)->value('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * The team configured for this user (per-tenant Setting map, keyed by
     * user id), or null when none is set.
     */
    public function defaultTeam(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $map = Setting::getValue(self::SETTING_TEAM_BY_USER, []);

        if (! is_array($map)) {
            return null;
        }

        $team = $map[(string) $userId] ?? $map[$userId] ?? null;

        return is_string($team) && trim($team) !== '' ? trim($team) : null;
    }

    /**
     * Fill the salesperson/team (and, #324, the customer's default payment
     * term; #346, when a document type is given, the customer's remembered
     * choices) on a document creation payload when the caller
     * did not supply them. Values already present (including explicit
     * conversions carrying the parent document's values) are never replaced.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function applyCreationDefaults(array $data, ?int $userId, ?string $documentType = null): array
    {
        // Issue #346 (D9): per-customer remembered choices fill only the
        // fields the caller left out of the payload.
        if ($documentType !== null) {
            $data = $this->applyRememberedDefaults($data, $documentType);
        }

        if (empty($data['salesperson'])) {
            $data['salesperson'] = $this->defaultSalesperson($userId);
        }

        if (empty($data['sales_team'])) {
            $data['sales_team'] = $this->defaultTeam($userId);
        }

        // Issue #324: the customer's default master payment term flows onto
        // the new document (quotation / sales order) unless one was picked.
        if (empty($data['payment_term_id']) && ! empty($data['customer_id'])) {
            $customerTerm = \App\Models\Customer::query()->whereKey($data['customer_id'])->value('payment_term_id');
            if ($customerTerm !== null) {
                $data['payment_term_id'] = (int) $customerTerm;
            }
        }

        return $data;
    }

    /**
     * Issue #346 (D9): the header fields each document type remembers per
     * customer. Only fields the document itself stores are listed, and only
     * the same document type is the source (a quotation's notes are internal,
     * an order's are printed). References / PO numbers are deliberately not
     * remembered — they identify one purchase (and drive the #327 duplicate
     * check). The price list needs no entry here: the pricing engine already
     * resolves the customer's own list (FR-2.3).
     */
    public const REMEMBERED_FIELDS = [
        'quotation' => ['billing_contact_id', 'shipping_contact_id', 'shipping_method', 'incoterm_code', 'incoterm_place', 'notes'],
        'sales_order' => ['warehouse_id', 'incoterm_code', 'incoterm_place', 'notes'],
        'invoice' => ['warehouse_id', 'billing_contact_id', 'shipping_contact_id', 'notes'],
    ];

    private const CONTACT_FIELDS = ['billing_contact_id', 'shipping_contact_id'];

    /**
     * Fill the customer's remembered choices into a creation payload. A field
     * the caller sent — even as null (an explicit clear) — is never touched.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function applyRememberedDefaults(array $data, string $documentType): array
    {
        if (empty($data['customer_id']) || ! isset(self::REMEMBERED_FIELDS[$documentType])) {
            return $data;
        }

        $missing = array_values(array_filter(
            self::REMEMBERED_FIELDS[$documentType],
            static fn (string $field): bool => ! array_key_exists($field, $data),
        ));
        if ($missing === []) {
            return $data;
        }

        $legalEntityId = array_key_exists('legal_entity_id', $data) && $data['legal_entity_id'] !== null
            ? (int) $data['legal_entity_id']
            : null;
        $remembered = $this->rememberedDefaults((int) $data['customer_id'], $documentType, $legalEntityId);

        foreach ($missing as $field) {
            if (array_key_exists($field, $remembered['values'])) {
                $data[$field] = $remembered['values'][$field];
            }
        }

        return $data;
    }

    /**
     * The customer's remembered choices for a new document of this type,
     * derived from the customer's most recent live document of the same type
     * in the same legal entity. Values that are no longer valid (an inactive
     * warehouse, a deleted/inactive contact, a contact of another legal
     * entity) are dropped.
     *
     * @return array{document: string, source: array{type: string, id: int, number: ?string, date: ?string}|null, values: array<string,mixed>}
     */
    public function rememberedDefaults(int $customerId, string $documentType, ?int $legalEntityId = null): array
    {
        $empty = ['document' => $documentType, 'source' => null, 'values' => []];

        if (! isset(self::REMEMBERED_FIELDS[$documentType])) {
            return $empty;
        }

        $customerEntity = \App\Models\Customer::query()->whereKey($customerId)->value('legal_entity_id');
        $entityId = $legalEntityId ?? ($customerEntity !== null ? (int) $customerEntity : null);

        $row = $this->lastDocument($customerId, $documentType, $entityId);
        if ($row === null) {
            return $empty;
        }

        $values = [];
        foreach (self::REMEMBERED_FIELDS[$documentType] as $field) {
            $value = $row->{$field} ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            if ($field === 'warehouse_id') {
                if (! \App\Models\Warehouse::query()->whereKey($value)->where('is_active', true)->exists()) {
                    continue;
                }
                $value = (int) $value;
            }
            if (in_array($field, self::CONTACT_FIELDS, true) && ! $this->contactUsableIn((string) $value, $entityId)) {
                continue;
            }
            $values[$field] = $value;
        }

        return [
            'document' => $documentType,
            'source' => [
                'type' => $documentType,
                'id' => (int) $row->id,
                'number' => $row->number !== null ? (string) $row->number : null,
                'date' => $row->date !== null ? substr((string) $row->date, 0, 10) : null,
            ],
            'values' => $values,
        ];
    }

    /**
     * The customer's most recent non-cancelled document of the type. Orders
     * and invoices carry a legal entity and must match the new document's
     * (null matches null); quotations carry none and are customer-scoped,
     * their contacts are entity-checked individually.
     */
    private function lastDocument(int $customerId, string $documentType, ?int $entityId): ?object
    {
        $sameEntity = static function ($query, string|\Illuminate\Contracts\Database\Query\Expression $column) use ($entityId): void {
            $entityId === null ? $query->whereNull($column) : $query->where($column, $entityId);
        };

        return match ($documentType) {
            'quotation' => \Illuminate\Support\Facades\DB::table('quotations')
                ->where('customer_id', $customerId)
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['cancelled', 'void'])
                ->where(fn ($q) => $q->whereNull('is_active_version')->orWhere('is_active_version', true))
                ->orderByDesc('id')
                ->first(['id', 'quotation_number as number', 'quotation_date as date', ...self::REMEMBERED_FIELDS['quotation']]),
            'sales_order' => \Illuminate\Support\Facades\DB::table('sales_orders')
                ->where('customer_id', $customerId)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')
                ->where(fn ($q) => $sameEntity($q, 'legal_entity_id'))
                ->orderByDesc('id')
                ->first(['id', 'sales_order_number as number', 'order_date as date', ...self::REMEMBERED_FIELDS['sales_order']]),
            'invoice' => \Illuminate\Support\Facades\DB::table('invoices')
                ->leftJoin('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
                ->where('invoices.customer_id', $customerId)
                ->whereNull('invoices.deleted_at')
                ->whereNotIn('invoices.status', ['void', 'voided', 'cancelled'])
                ->where(fn ($q) => $q->whereNull('invoices.is_active_version')->orWhere('invoices.is_active_version', true))
                ->where(fn ($q) => $sameEntity($q, \Illuminate\Support\Facades\DB::raw('COALESCE(invoices.legal_entity_id, sales_orders.legal_entity_id)')))
                ->orderByDesc('invoices.id')
                ->first([
                    'invoices.id', 'invoices.invoice_number as number', 'invoices.invoice_date as date',
                    'sales_orders.warehouse_id', 'invoices.billing_contact_id', 'invoices.shipping_contact_id', 'invoices.notes',
                ]),
            default => null,
        };
    }

    /** A remembered contact must still exist, be active and not belong to another legal entity. */
    private function contactUsableIn(string $contactId, ?int $entityId): bool
    {
        return \Modules\Contact\src\Models\CoreContact::query()
            ->whereKey($contactId)
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->where(function ($q) use ($entityId) {
                $q->whereNull('legal_entity_id');
                if ($entityId !== null) {
                    $q->orWhere('legal_entity_id', $entityId);
                }
            })
            ->exists();
    }
}
