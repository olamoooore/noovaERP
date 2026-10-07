<?php

namespace App\Services;

use App\Models\InvoiceDelivery;
use App\Models\Setting;

/**
 * Issue #539 (ST2): typed read access to the tenant's Sales Settings.
 *
 * The Sales Settings screen persists each value as a `Setting` row
 * (CompanySettingsController::updateSalesSettings). Every live reader goes
 * through this class so each key has ONE default and ONE coercion rule,
 * instead of scattered `Setting::getValue()` calls that repeat (and drift
 * from) the defaults the settings screen echoes back.
 *
 * Defaults are the values GET /settings/sales has always reported for a
 * tenant that never saved the key, so wiring a reader does not change what
 * the screen already told the tenant.
 *
 * This is a thin accessor over the existing `Setting` store, not a new
 * settings registry (that platform work is tracked in #921).
 */
class SalesSettings
{
    public const ALLOW_CUSTOM_NUMBER = 'sales.invoice.allow_custom_number';

    public const APPROVAL_EXPIRY_DAYS = 'sales.invoice.approval_expiry_days';

    public const AUTO_SEND_ON_POST = 'sales.invoice.auto_send_to_customer_on_post';

    public const ALLOW_PARTIAL_PAYMENTS = 'sales.invoice.allow_partial_payments';

    public const DEFAULT_PAYMENT_TERMS_DAYS = 'sales.invoice.default_payment_terms_days';

    public const DEFAULT_NOTES = 'sales.invoice.default_notes';

    public const DEFAULT_TERMS = 'sales.invoice.default_terms';

    public const ACCEPTANCE_ENABLED = 'sales.portal.acceptance_enabled';

    public const REQUIRE_SIGNATURE = 'sales.portal.require_signature';

    public const ACCEPTANCE_LINK_EXPIRY_DAYS = 'sales.portal.acceptance_link_expiry_days';

    public const PAYMENT_LINK_EXPIRY_DAYS = 'sales.portal.payment_link_expiry_days';

    public const ATTACH_PDF = 'sales.delivery.attach_pdf';

    public const CC_INTERNAL_EMAIL = 'sales.delivery.cc_internal_email';

    public const DOCUMENT_TYPE_ON_SEND = 'sales.delivery.document_type_on_send';

    // Issue #545 (SET-UX1): Quotation and Sales Order sections.
    public const QUOTATION_DEFAULT_VALIDITY_DAYS = 'sales.quotation.default_validity_days';

    public const QUOTATION_DEFAULT_TERMS = 'sales.quotation.default_terms';

    // Issue #550: what the customer must do to accept a quotation online.
    public const QUOTATION_REQUIRE_SIGNATURE = 'sales.quotation.require_signature';

    public const QUOTATION_REQUIRE_PAYMENT = 'sales.quotation.require_payment';

    public const QUOTATION_PREPAYMENT_PERCENT = 'sales.quotation.prepayment_percent';

    public const DEFAULT_QUOTATION_PREPAYMENT_PERCENT = 100.0;

    /** Same key SalesOrderService has read since #407. */
    public const AUTO_LOCK_ON_CONFIRM = 'sales.orders.auto_lock_on_confirm';

    public const DEFAULT_INVOICING_POLICY = 'sales.sales_order.default_invoicing_policy';

    /** Issue #326: what a failed credit check does on invoice create / post. */
    public const CREDIT_LIMIT_POLICY = 'sales.invoice.credit_limit_policy';

    public const CREDIT_LIMIT_POLICY_BLOCK = 'block';

    public const CREDIT_LIMIT_POLICY_WARN = 'warn';

    public const CREDIT_LIMIT_POLICIES = [self::CREDIT_LIMIT_POLICY_BLOCK, self::CREDIT_LIMIT_POLICY_WARN];

    public const INVOICING_POLICIES = ['ordered_qty', 'delivered_qty'];

    /**
     * The quotation form has always pre-filled "valid until" 30 days out
     * (DEFAULT_QUOTE_VALIDITY_DAYS in the frontend), so that stays the
     * default; 0 means new quotations start without an expiry date.
     */
    public const DEFAULT_QUOTATION_VALIDITY_DAYS = 30;

    public const DEFAULT_APPROVAL_EXPIRY_DAYS = 7;

    public const DEFAULT_PAYMENT_TERMS = 30;

    public const DEFAULT_TERMS_TEXT = 'Payment due within 30 days.';

    public const DEFAULT_ACCEPTANCE_LINK_EXPIRY_DAYS = 30;

    public const DEFAULT_PAYMENT_LINK_EXPIRY_DAYS = 14;

    // Issue #549: defaults of the scalar keys the settings screen reads
    // inline (no typed accessor yet), kept here so GET /settings/sales and
    // its read-only `default_values` block share one literal per key.
    public const DEFAULT_FIELD_PROFILE = 'medical';

    public const DEFAULT_SALESPERSON_MODE = 'none';

    public const DEFAULT_PRICELIST_DISPLAY = 'show_discount';

    /** Document types the "document type on send" setting may select. */
    public const SENDABLE_DOCUMENT_TYPES = [
        InvoiceDelivery::DOCUMENT_FINAL_INVOICE,
        InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY,
    ];

    /**
     * Issue #549 (SET-UX5): the value each scalar Sales Settings field has
     * for a tenant that never saved it, shaped like the GET /settings/sales
     * payload (group => field => default). The settings screen uses it for
     * its "changed from default" markers and per-setting reset; nothing here
     * is writable. Lists (reviewers, team map), the tax block (owned by the
     * tax engine's canonical setting) and the rounding account have no
     * meaningful default and are left out.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            'cash_rounding' => [
                'enabled' => CashRoundingService::DEFAULTS['enabled'],
                'method' => CashRoundingService::DEFAULTS['method'],
                'precision' => CashRoundingService::DEFAULTS['precision'],
            ],
            'invoice' => [
                'allow_custom_number' => true,
                'field_profile' => self::DEFAULT_FIELD_PROFILE,
                'default_requires_approval' => false,
                'approval_expiry_days' => self::DEFAULT_APPROVAL_EXPIRY_DAYS,
                'auto_send_to_customer_on_post' => true,
                'allow_partial_payments' => true,
                'default_payment_terms_days' => self::DEFAULT_PAYMENT_TERMS,
                'default_notes' => '',
                'default_terms' => self::DEFAULT_TERMS_TEXT,
            ],
            'portal' => [
                'acceptance_enabled' => true,
                'require_signature' => true,
                'acceptance_link_expiry_days' => self::DEFAULT_ACCEPTANCE_LINK_EXPIRY_DAYS,
                'payment_link_expiry_days' => self::DEFAULT_PAYMENT_LINK_EXPIRY_DAYS,
            ],
            'delivery' => [
                'attach_pdf' => true,
                'cc_internal_email' => '',
                'document_type_on_send' => InvoiceDelivery::DOCUMENT_FINAL_INVOICE,
            ],
            'delivery_order' => [
                'default_requires_approval' => false,
            ],
            'defaults' => [
                'salesperson_mode' => self::DEFAULT_SALESPERSON_MODE,
            ],
            'pricelist' => [
                'display' => self::DEFAULT_PRICELIST_DISPLAY,
            ],
        ];
    }

    public function allowCustomInvoiceNumber(): bool
    {
        return $this->bool(self::ALLOW_CUSTOM_NUMBER, true);
    }

    /** Lifetime of an invoice approval request, in days (at least 1). */
    public function approvalExpiryDays(): int
    {
        return $this->positiveInt(self::APPROVAL_EXPIRY_DAYS, self::DEFAULT_APPROVAL_EXPIRY_DAYS);
    }

    public function autoSendToCustomerOnPost(): bool
    {
        return $this->bool(self::AUTO_SEND_ON_POST, true);
    }

    public function allowPartialPayments(): bool
    {
        return $this->bool(self::ALLOW_PARTIAL_PAYMENTS, true);
    }

    /**
     * Tenant fallback credit terms, in days (0 = due on receipt). Used only
     * when the customer has no payment terms of its own.
     */
    public function defaultPaymentTermsDays(): int
    {
        $raw = Setting::getValue(self::DEFAULT_PAYMENT_TERMS_DAYS);

        if (! is_numeric($raw) || (int) $raw < 0) {
            return self::DEFAULT_PAYMENT_TERMS;
        }

        return (int) $raw;
    }

    /** Default invoice notes; null when the tenant has none. */
    public function defaultNotes(): ?string
    {
        return $this->text(self::DEFAULT_NOTES, null);
    }

    /** Default invoice terms; null when the tenant cleared them. */
    public function defaultTerms(): ?string
    {
        return $this->text(self::DEFAULT_TERMS, self::DEFAULT_TERMS_TEXT);
    }

    /**
     * Issue #545: days from the quotation date to the pre-filled "valid
     * until" on a new quotation; 0 = no default expiry date.
     */
    public function quotationDefaultValidityDays(): int
    {
        $raw = Setting::getValue(self::QUOTATION_DEFAULT_VALIDITY_DAYS);

        if (! is_numeric($raw) || (int) $raw < 0 || (int) $raw > 365) {
            return self::DEFAULT_QUOTATION_VALIDITY_DAYS;
        }

        return (int) $raw;
    }

    /** Issue #545: default quotation terms; null when the tenant has none. */
    public function quotationDefaultTerms(): ?string
    {
        return $this->text(self::QUOTATION_DEFAULT_TERMS, null);
    }

    /**
     * Issue #550: a drawn signature is required to accept a quotation in the
     * customer portal. Off by default: the quotation portal has always
     * accepted without one (unlike the invoice portal's own setting).
     */
    public function quotationRequireSignature(): bool
    {
        return $this->bool(self::QUOTATION_REQUIRE_SIGNATURE, false);
    }

    /**
     * Issue #550: portal acceptance records the customer's agreement but does
     * not confirm the quotation until the prepayment has been received.
     */
    public function quotationRequirePayment(): bool
    {
        return $this->bool(self::QUOTATION_REQUIRE_PAYMENT, false);
    }

    /** Issue #550: share of the quotation total due up front (0 < % <= 100). */
    public function quotationPrepaymentPercent(): float
    {
        $raw = Setting::getValue(self::QUOTATION_PREPAYMENT_PERCENT);

        if (! is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 100) {
            return self::DEFAULT_QUOTATION_PREPAYMENT_PERCENT;
        }

        return round((float) $raw, 2);
    }

    /** Issue #545 / #407: lock a sales order as part of confirming it. */
    public function autoLockOnConfirm(): bool
    {
        return $this->bool(self::AUTO_LOCK_ON_CONFIRM, false);
    }

    /**
     * Issue #545: invoicing policy for a new sales order line that does not
     * name one (ordered_qty bills what was ordered, delivered_qty bills what
     * was delivered).
     */
    public function defaultInvoicingPolicy(): string
    {
        $value = Setting::getValue(self::DEFAULT_INVOICING_POLICY);

        return is_string($value) && in_array($value, self::INVOICING_POLICIES, true) ? $value : 'ordered_qty';
    }

    /**
     * Issue #326: `block` (default) refuses an invoice create/post that fails
     * the credit check; `warn` lets it through with the verdict attached.
     * Block is the default because direct invoices were already refused by
     * their backing sales order's credit check before this setting existed.
     */
    public function invoiceCreditLimitPolicy(): string
    {
        $value = Setting::getValue(self::CREDIT_LIMIT_POLICY);

        return is_string($value) && in_array($value, self::CREDIT_LIMIT_POLICIES, true) ? $value : self::CREDIT_LIMIT_POLICY_BLOCK;
    }

    public function acceptanceEnabled(): bool
    {
        return $this->bool(self::ACCEPTANCE_ENABLED, true);
    }

    public function requireSignature(): bool
    {
        return $this->bool(self::REQUIRE_SIGNATURE, true);
    }

    public function acceptanceLinkExpiryDays(): int
    {
        return $this->positiveInt(self::ACCEPTANCE_LINK_EXPIRY_DAYS, self::DEFAULT_ACCEPTANCE_LINK_EXPIRY_DAYS);
    }

    public function paymentLinkExpiryDays(): int
    {
        return $this->positiveInt(self::PAYMENT_LINK_EXPIRY_DAYS, self::DEFAULT_PAYMENT_LINK_EXPIRY_DAYS);
    }

    public function attachPdf(): bool
    {
        return $this->bool(self::ATTACH_PDF, true);
    }

    /** Internal address copied on customer invoice emails; null when unset. */
    public function ccInternalEmail(): ?string
    {
        $email = $this->text(self::CC_INTERNAL_EMAIL, null);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * The document type the tenant chose for sends, or null when it never
     * chose one (callers then apply their own status-based rule, #617).
     */
    public function documentTypeOnSend(): ?string
    {
        $value = Setting::getValue(self::DOCUMENT_TYPE_ON_SEND);

        return is_string($value) && in_array($value, self::SENDABLE_DOCUMENT_TYPES, true) ? $value : null;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = Setting::getValue($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    private function positiveInt(string $key, int $default): int
    {
        $raw = Setting::getValue($key);

        if (! is_numeric($raw) || (int) $raw < 1) {
            return $default;
        }

        return (int) $raw;
    }

    private function text(string $key, ?string $default): ?string
    {
        $value = Setting::getValue($key);

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
