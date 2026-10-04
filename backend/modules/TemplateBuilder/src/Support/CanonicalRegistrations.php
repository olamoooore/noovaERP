<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Support;

use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Platform\RuntimeMode;

/**
 * Canonical ADR-004 §4.1 template-type registrations for the current
 * delivery scope (Sales, CRM, Accounting).
 *
 * Each row is a stable, evidence-referenced registration contract (ADR-004 §7).
 * Deferred domains (Field Service, Contract, Project, Service Industry) are
 * NOT included here — reserving their contracts is a Wave 2/3 obligation.
 */
final class CanonicalRegistrations
{
    /**
     * Issue #615 (TP5) — the single canonical-to-data-source key map.
     *
     * The ADR-004 registry speaks canonical `template_type_code`s while the
     * render pipeline (data sources, render routes, immutable snapshots)
     * speaks runtime keys. This is the only place the correspondence is
     * declared; the resolver, snapshot hooks and completeness tests all read
     * it instead of hard-coding either form.
     *
     * @var array<string,string> canonical type code => runtime data-source key
     */
    private const DATA_SOURCE_KEY_BY_TYPE_CODE = [
        'ar_invoice' => 'invoice',
        'ar_credit_note' => 'credit_note',
        'ar_official_receipt' => 'receipt',
        'sales_quotation' => 'quotation',
        'sales_order' => 'sales_order',
        'sales_order_confirmation' => 'sales_order_confirmation',
        'sales_proforma_invoice' => 'sales_proforma_invoice',
        'customer_statement' => 'customer_statement',
    ];

    /**
     * Renderable data sources that predate the ADR-004 registry (no canonical
     * registration row). Listed here so the key map describes the complete
     * renderable set in one place.
     *
     * @var list<string>
     */
    private const LEGACY_DATA_SOURCE_KEYS = ['purchase_order', 'delivery_order'];

    /**
     * Issue #615 (TP5) — registered business-document types that deliberately
     * have no render data source on this branch. They are hidden from the
     * template editor until a source exists; the completeness test fails when
     * a registered type is neither here nor in DATA_SOURCE_KEY_BY_TYPE_CODE.
     *
     * @var array<string,string> canonical type code => why it is not renderable
     */
    private const UNSUPPORTED_BUSINESS_DOCUMENT_TYPES = [
        'sales_revised_quotation' => 'No runtime source/route; revised quotations are not distinct printable documents on this branch.',
        'sales_delivery_instruction' => 'No runtime source/route; delivery execution prints the delivery order source.',
        'sales_return' => 'No runtime source/route; returns are not a printable document on this branch.',
        'sales_price_list' => 'No runtime source/route; price lists are not a printable document on this branch.',
        'crm_lead_summary' => 'No runtime source/route; CRM business documents are not renderable on this branch.',
        'crm_customer_profile' => 'No runtime source/route; CRM business documents are not renderable on this branch.',
        'crm_account_review' => 'No runtime source/route; CRM business documents are not renderable on this branch.',
        'crm_sales_visit_report' => 'No runtime source/route; CRM business documents are not renderable on this branch.',
        'crm_pipeline_report' => 'No runtime source/route; CRM business documents are not renderable on this branch.',
        'ar_debit_note' => 'No runtime source/route; only the customer credit note has a render path on this branch.',
        'voucher' => 'No runtime source/route; vouchers are not renderable on this branch.',
        'supplier_statement' => 'No runtime source/route; only the customer statement is renderable on this branch.',
        'ageing_report' => 'No runtime source/route; reports are not renderable on this branch.',
        'gl_report' => 'No runtime source/route; reports are not renderable on this branch.',
        'tb_report' => 'No runtime source/route; reports are not renderable on this branch.',
        'pl_report' => 'No runtime source/route; reports are not renderable on this branch.',
        'bs_report' => 'No runtime source/route; reports are not renderable on this branch.',
        'cf_report' => 'No runtime source/route; reports are not renderable on this branch.',
        'tax_report' => 'No runtime source/route; reports are not renderable on this branch.',
        'close_report' => 'No runtime source/route; reports are not renderable on this branch.',
        'comm_email_template' => 'Authored for delivery by the Communication engine (bridge), not a document render source.',
        'comm_sms_template' => 'Authored for delivery by the Communication engine (bridge), not a document render source.',
        'comm_letter_template' => 'Authored for delivery by the Communication engine (bridge), not a document render source.',
        'comm_notice_template' => 'Authored for delivery by the Communication engine (bridge), not a document render source.',
    ];

    /**
     * The data-source key for a canonical ADR-004 type code (null when the
     * type is not renderable).
     */
    public static function dataSourceKeyFor(string $templateTypeCode): ?string
    {
        return self::DATA_SOURCE_KEY_BY_TYPE_CODE[$templateTypeCode] ?? null;
    }

    /**
     * The data-source key for a canonical type that MUST be renderable —
     * fails loudly instead of silently rendering with a null key.
     */
    public static function renderableKeyFor(string $templateTypeCode): string
    {
        return self::DATA_SOURCE_KEY_BY_TYPE_CODE[$templateTypeCode]
            ?? throw new \InvalidArgumentException(
                "Template type '{$templateTypeCode}' has no registered data source (#615)."
            );
    }

    /**
     * The canonical ADR-004 type code for a runtime data-source key (null for
     * legacy-only renderable sources).
     */
    public static function canonicalTypeCodeFor(string $dataSourceKey): ?string
    {
        $canonical = array_search($dataSourceKey, self::DATA_SOURCE_KEY_BY_TYPE_CODE, true);

        return $canonical === false ? null : $canonical;
    }

    /**
     * Every key equivalent to the given type code — canonical and
     * data-source forms. An unknown code resolves to itself so
     * tenant-registered types keep matching their own assignments.
     *
     * @return list<string>
     */
    public static function keysFor(string $typeCode): array
    {
        if (isset(self::DATA_SOURCE_KEY_BY_TYPE_CODE[$typeCode])) {
            return [$typeCode, self::DATA_SOURCE_KEY_BY_TYPE_CODE[$typeCode]];
        }

        $canonical = self::canonicalTypeCodeFor($typeCode);
        if ($canonical !== null) {
            return [$canonical, $typeCode];
        }

        return [$typeCode];
    }

    /**
     * Every renderable data-source key, in one place (canonical type codes
     * plus documented legacy renderable sources).
     *
     * @return list<string>
     */
    public static function dataSourceKeys(): array
    {
        return [...array_values(self::DATA_SOURCE_KEY_BY_TYPE_CODE), ...self::LEGACY_DATA_SOURCE_KEYS];
    }

    /**
     * Business-document types hidden from the template editor because no data
     * source exists for them yet (#615 hide-list).
     *
     * @return array<string,string>
     */
    public static function unsupportedBusinessDocumentTypes(): array
    {
        return self::UNSUPPORTED_BUSINESS_DOCUMENT_TYPES;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function definitions(): array
    {
        $pdfChannels = [TemplateChannelList::Html, TemplateChannelList::Pdf, TemplateChannelList::Print, TemplateChannelList::Email];
        $formChannels = [TemplateChannelList::Portal, TemplateChannelList::AuthenticatedForm];
        $surveyChannels = [TemplateChannelList::PublicLink, TemplateChannelList::Portal, TemplateChannelList::AuthenticatedForm];
        $sharedDocServices = ['#52', '#55', '#53'];
        $sharedFormServices = ['#52', '#170', '#171'];
        $sharedSurveyServices = ['#52', '#170', '#171', '#169'];

        return [
            // ───────────── SALES — Business Documents ─────────────
            self::doc('sales', 'sales_quotation', 'Sales Quotation', $pdfChannels, ['sales.write'], 'A-SAL-01'),
            self::doc('sales', 'sales_revised_quotation', 'Revised Quotation', $pdfChannels, ['sales.write']),
            self::doc('sales', 'sales_proforma_invoice', 'Pro Forma Invoice', $pdfChannels, ['sales.write']),
            self::doc('sales', 'sales_order', 'Sales Order', $pdfChannels, ['sales.write']),
            self::doc('sales', 'sales_order_confirmation', 'Order Confirmation', $pdfChannels, ['sales.write']),
            self::doc('sales', 'sales_delivery_instruction', 'Delivery Instruction', $pdfChannels, ['inventory.write']),
            self::doc('sales', 'sales_return', 'Sales Return', $pdfChannels, ['sales.write']),
            self::doc('sales', 'sales_price_list', 'Price List', $pdfChannels, ['sales.view']),

            // ───────────── SALES — Data-Entry Forms ─────────────
            self::form('sales', 'sales_quotation_request', 'Quotation Request', $formChannels, ['sales.write']),
            self::form('sales', 'sales_discount_approval', 'Discount Approval', $formChannels, ['sales.write']),
            self::form('sales', 'sales_special_price_approval', 'Special-Price Approval', $formChannels, ['sales.write']),
            self::form('sales', 'sales_order_amendment', 'Sales-Order Amendment', $formChannels, ['sales.write']),
            self::form('sales', 'sales_cancellation', 'Cancellation Request', $formChannels, ['sales.write']),
            self::form('sales', 'sales_return_request', 'Return Request', $formChannels, ['sales.write']),
            self::form('sales', 'sales_credit_limit_request', 'Credit-Limit Request', $formChannels, ['sales.write']),

            // ───────────── SALES — Surveys ─────────────
            self::survey('sales', 'sales_process_feedback', 'Sales-Process Feedback', $surveyChannels),
            self::survey('sales', 'sales_product_feedback', 'Product Feedback', $surveyChannels),
            self::survey('sales', 'sales_post_purchase', 'Post-Purchase Survey', $surveyChannels),
            self::survey('sales', 'sales_delivery_satisfaction', 'Delivery Satisfaction', $surveyChannels),

            // ───────────── CRM — Business Documents ─────────────
            self::doc('crm', 'crm_lead_summary', 'Lead/Opportunity Summary', $pdfChannels, ['crm.view']),
            self::doc('crm', 'crm_customer_profile', 'Customer Profile', $pdfChannels, ['crm.view']),
            self::doc('crm', 'crm_account_review', 'Account Review', $pdfChannels, ['crm.view']),
            self::doc('crm', 'crm_sales_visit_report', 'Sales-Visit Report', $pdfChannels, ['crm.view']),
            self::doc('crm', 'crm_pipeline_report', 'Pipeline/Campaign Report', $pdfChannels, ['crm.view']),

            // ───────────── CRM — Data-Entry Forms ─────────────
            self::form('crm', 'crm_lead_capture', 'Lead Capture', [TemplateChannelList::PublicLink, ...$formChannels], ['crm.write'], 'A-CRM-01'),
            self::form('crm', 'crm_customer_onboarding', 'Customer Registration/Onboarding', $formChannels, ['crm.write'], 'A-CRM-01'),
            self::form('crm', 'crm_contact_update', 'Contact Update', $formChannels, ['crm.write']),
            self::form('crm', 'crm_qualification', 'Qualification', $formChannels, ['crm.write']),
            self::form('crm', 'crm_consent_preference', 'Consent/Marketing Preference', [TemplateChannelList::PublicLink, ...$formChannels], ['crm.write']),
            self::form('crm', 'crm_lost_opportunity_reason', 'Lost-Opportunity Reason', $formChannels, ['crm.write']),

            // ───────────── CRM — Surveys ─────────────
            self::survey('crm', 'crm_customer_satisfaction', 'Customer Satisfaction', $surveyChannels, 'A-CRM-02'),
            self::survey('crm', 'crm_nps', 'NPS', $surveyChannels, 'A-CRM-02'),
            self::survey('crm', 'crm_product_interest', 'Product-Interest', $surveyChannels),
            self::survey('crm', 'crm_lost_sales', 'Lost-Sales', $surveyChannels),
            self::survey('crm', 'crm_campaign_feedback', 'Campaign Feedback', $surveyChannels),
            self::survey('crm', 'crm_needs_assessment', 'Needs Assessment', $surveyChannels),

            // ───────────── ACCOUNTING — Business Documents ─────────────
            self::doc('accounting', 'ar_invoice', 'Invoice', $pdfChannels, ['accounting.post'], 'A-ACC-01'),
            self::doc('accounting', 'ar_credit_note', 'Credit Note', $pdfChannels, ['accounting.post'], 'A-ACC-02'),
            self::doc('accounting', 'ar_debit_note', 'Debit Note', $pdfChannels, ['accounting.post']),
            self::doc('accounting', 'ar_official_receipt', 'Official/Payment Receipt', $pdfChannels, ['accounting.post'], 'A-ACC-02'),
            self::doc('accounting', 'voucher', 'Voucher', $pdfChannels, ['accounting.post']),
            self::doc('accounting', 'customer_statement', 'Customer Statement', $pdfChannels, ['accounting.view'], 'A-ACC-03'),
            self::doc('accounting', 'supplier_statement', 'Supplier Statement', $pdfChannels, ['accounting.view']),
            self::doc('accounting', 'ageing_report', 'Ageing Report', $pdfChannels, ['accounting.view']),
            self::doc('accounting', 'gl_report', 'General Ledger', $pdfChannels, ['accounting.view']),
            self::doc('accounting', 'tb_report', 'Trial Balance', $pdfChannels, ['accounting.view']),
            self::doc('accounting', 'pl_report', 'Profit & Loss', $pdfChannels, ['accounting.view']),
            self::doc('accounting', 'bs_report', 'Balance Sheet', $pdfChannels, ['accounting.view']),
            self::doc('accounting', 'cf_report', 'Cash Flow', $pdfChannels, ['accounting.view']),
            self::doc('accounting', 'tax_report', 'Tax Report', $pdfChannels, ['accounting.view']),
            self::doc('accounting', 'close_report', 'Close Report', $pdfChannels, ['accounting.view']),

            // ───────────── ACCOUNTING — Data-Entry Forms ─────────────
            self::form('accounting', 'acc_expense_request', 'Expense Request', $formChannels, ['accounting.post']),
            self::form('accounting', 'acc_payment_request', 'Payment Request', $formChannels, ['accounting.post']),
            self::form('accounting', 'acc_refund_request', 'Refund Request', $formChannels, ['accounting.post']),
            self::form('accounting', 'acc_journal_request', 'Journal Request', $formChannels, ['accounting.post']),
            self::form('accounting', 'acc_credit_note_request', 'Credit-Note Request', $formChannels, ['accounting.post']),
            self::form('accounting', 'acc_debit_note_request', 'Debit-Note Request', $formChannels, ['accounting.post']),
            self::form('accounting', 'acc_budget_transfer', 'Budget/Transfer', $formChannels, ['accounting.post']),
            self::form('accounting', 'acc_write_off', 'Write-Off', $formChannels, ['accounting.post']),
            self::form('accounting', 'acc_period_reopen', 'Period Reopen', $formChannels, ['accounting.post']),

            // ───────────── ACCOUNTING — Surveys ─────────────
            self::survey('accounting', 'acc_financial_control_questionnaire', 'Financial-Control Questionnaire', $surveyChannels),
            self::survey('accounting', 'acc_close_checklist', 'Close Checklist', $surveyChannels),
            self::survey('accounting', 'acc_audit_readiness', 'Audit Readiness', $surveyChannels),
            self::survey('accounting', 'acc_finance_process_feedback', 'Finance-Process Feedback', $surveyChannels),

            // ───────────── COMMUNICATION — Email / SMS / Letter (ADR-004 §5 #56-57) ─────────────
            // Folded into the Universal Template Platform as specialised document types.
            // Delivery remains owned by the Communication engine (#56-57); authoring,
            // versioning and assignment now flow through this platform.
            self::doc('communication', 'comm_email_template', 'Email Template', [TemplateChannelList::Email, TemplateChannelList::Portal], ['communication.messages.send']),
            self::doc('communication', 'comm_sms_template', 'SMS Template', [TemplateChannelList::Sms], ['communication.messages.send']),
            self::doc('communication', 'comm_letter_template', 'Letter Template', [TemplateChannelList::Letter, TemplateChannelList::Pdf, TemplateChannelList::Print], ['communication.messages.send']),
            self::doc('communication', 'comm_notice_template', 'Notice Template', [TemplateChannelList::Email, TemplateChannelList::Portal, TemplateChannelList::Letter], ['communication.messages.send']),
        ];
    }

    /**
     * @param  list<string>  $channels
     * @param  list<string>  $services
     * @param  list<string>  $entitlements
     * @return array<string,mixed>
     */
    private static function base(string $domain, string $typeCode, string $name, string $interaction, array $channels, array $services, array $entitlements, ?string $acceptance = null): array
    {
        return [
            'domain_module_code' => $domain,
            'template_type_code' => $typeCode,
            'interaction_type' => $interaction,
            'runtime_mode' => RuntimeMode::Coupled->value,
            'name' => $name,
            'description' => $name.' (ADR-004 canonical registration)',
            'required_platform_services' => $services,
            'entitlement_codes' => $entitlements,
            'component_profile' => self::componentProfile($interaction),
            'channels' => $channels,
            'scope_jurisdiction' => ['tenant' => true, 'entity' => true, 'branch' => true, 'language' => true, 'currency' => true],
            'security_retention' => self::retentionPolicy($domain, $interaction),
            'acceptance_reference' => $acceptance,
        ];
    }

    /** @param  list<string>  $channels */
    private static function doc(string $domain, string $typeCode, string $name, array $channels, array $entitlements, ?string $acceptance = null): array
    {
        return self::base($domain, $typeCode, $name, InteractionType::BusinessDocument->value, $channels, ['#52', '#55', '#53'], $entitlements, $acceptance);
    }

    /** @param  list<string>  $channels */
    private static function form(string $domain, string $typeCode, string $name, array $channels, array $entitlements, ?string $acceptance = null): array
    {
        return self::base($domain, $typeCode, $name, InteractionType::DataEntryForm->value, $channels, ['#52', '#170', '#171'], $entitlements, $acceptance);
    }

    /** @param  list<string>  $channels */
    private static function survey(string $domain, string $typeCode, string $name, array $channels, ?string $acceptance = null): array
    {
        return self::base($domain, $typeCode, $name, InteractionType::SurveyAssessment->value, $channels, ['#52', '#170', '#171', '#169'], [], $acceptance);
    }

    private static function componentProfile(string $interaction): array
    {
        return match ($interaction) {
            InteractionType::BusinessDocument->value => [
                'allowed_components' => ['text', 'image', 'columns', 'table', 'totals', 'spacer', 'divider', 'header', 'footer', 'conditional', 'barcode', 'qr'],
                'max_nesting' => 4,
                'max_components' => 200,
                'max_lines_table' => 500,
            ],
            InteractionType::DataEntryForm->value => [
                'allowed_components' => ['section', 'field', 'conditional_field', 'file_upload', 'signature', 'rich_text', 'lookup'],
                'max_nesting' => 3,
                'max_components' => 150,
            ],
            InteractionType::SurveyAssessment->value => [
                'allowed_components' => ['question', 'scale', 'branch', 'matrix', 'nps', 'open_text', 'ranking'],
                'max_nesting' => 3,
                'max_components' => 100,
            ],
            default => ['allowed_components' => [], 'max_nesting' => 2, 'max_components' => 100],
        };
    }

    private static function retentionPolicy(string $domain, string $interaction): array
    {
        if ($domain === 'accounting' && $interaction === InteractionType::BusinessDocument->value) {
            // Legal/accounting artifacts retained 7 years (ADR-004 §13).
            return ['classification' => 'financial', 'artifact_retention_years' => 7, 'immutable_original' => true];
        }

        if ($interaction === InteractionType::DataEntryForm->value) {
            return ['classification' => 'operational', 'submission_retention_years' => 3, 'consent_required' => true];
        }

        if ($interaction === InteractionType::SurveyAssessment->value) {
            return ['classification' => 'operational', 'response_retention_years' => 3, 'identity_policy' => 'configurable'];
        }

        return ['classification' => 'operational', 'artifact_retention_years' => 3];
    }
}
