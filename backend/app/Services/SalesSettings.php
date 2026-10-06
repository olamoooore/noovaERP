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

    public const DEFAULT_APPROVAL_EXPIRY_DAYS = 7;

    public const DEFAULT_PAYMENT_TERMS = 30;

    public const DEFAULT_TERMS_TEXT = 'Payment due within 30 days.';

    public const DEFAULT_ACCEPTANCE_LINK_EXPIRY_DAYS = 30;

    public const DEFAULT_PAYMENT_LINK_EXPIRY_DAYS = 14;

    /** Document types the "document type on send" setting may select. */
    public const SENDABLE_DOCUMENT_TYPES = [
        InvoiceDelivery::DOCUMENT_FINAL_INVOICE,
        InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY,
    ];

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
