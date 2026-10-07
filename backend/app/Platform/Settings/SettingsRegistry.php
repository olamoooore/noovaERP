<?php

namespace App\Platform\Settings;

use App\Http\Controllers\Api\CompanySettingsController;
use App\Http\Controllers\Api\InvoiceAcceptanceController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\SettingsController;
use App\Models\Customer;
use App\Models\Invoice;
use App\Platform\Settings\Contracts\SettingsRegistryContract;
use App\Services\CashRoundingService;
use App\Services\CustomerReceiptService;
use App\Services\InvoiceApprovalService;
use App\Services\InvoiceDeliveryService;
use App\Services\InvoiceEvidenceService;
use App\Services\InvoiceService;
use App\Services\SalesApprovalGate;
use App\Services\SalesDefaultsService;
use App\Services\SalesPricelistDisplayService;
use App\Services\SalesSettings;
use App\Services\TaxEngineService;
use Modules\Sales\Http\Controllers\SalesOrderController;
use Modules\Sales\Services\CreditCheckService;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;

/**
 * Issue #921 (SS5): the platform settings registry.
 *
 * Every setting a settings screen saves is declared here ONCE: its store key,
 * type, default, scope, edit permission and the code paths that read it.
 * `Issue921SettingsRegistryReadersTest` (Feature suite, runs in CI) fails
 * when a declared setting has no reader, a declared reader no longer reaches
 * the key, or code reads/writes an undeclared `Setting` key outside the
 * shrink-only baseline (`undeclared-settings-baseline.json`).
 *
 * This first slice covers the `Setting` key-value store behind the Sales
 * Settings screen (PUT /settings/sales). It reuses the #539 accessor
 * (`SalesSettings`) and the #549 defaults (`SalesSettings::defaults()`)
 * instead of re-declaring them; the other screens and stores are still to be
 * migrated (see #921).
 */
class SettingsRegistry implements SettingsRegistryContract
{
    public const SCREEN_SALES = 'sales';

    private const SALES_EDIT_PERMISSION = 'settings.write';

    /** @var list<SettingDefinition>|null */
    private ?array $definitions = null;

    public function all(): array
    {
        return $this->definitions ??= $this->declare();
    }

    public function keys(): array
    {
        return array_values(array_unique(array_map(fn (SettingDefinition $d) => $d->key, $this->all())));
    }

    public function isDeclared(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function forScreen(string $screen): array
    {
        $out = [];
        foreach ($this->all() as $definition) {
            if ($definition->screen === $screen) {
                $out[$definition->field] = $definition;
            }
        }

        return $out;
    }

    public function storeKeysForScreen(string $screen): array
    {
        $out = [];
        foreach ($this->forScreen($screen) as $field => $definition) {
            if ($definition->isWholeValue()) {
                $out[$field] = $definition->key;
            }
        }

        return $out;
    }

    public function whereUsed(string $screen): array
    {
        return array_map(fn (SettingDefinition $d) => $d->toArray(), $this->forScreen($screen));
    }

    public function screenMethods(string $screen): array
    {
        return match ($screen) {
            self::SCREEN_SALES => [
                CompanySettingsController::class.'::getSalesSettings',
                CompanySettingsController::class.'::updateSalesSettings',
            ],
            default => [],
        };
    }

    /** @return list<SettingDefinition> */
    private function declare(): array
    {
        return array_map(
            fn (string $field, array $spec) => $this->sales($field, $spec),
            array_keys($this->salesDeclarations()),
            $this->salesDeclarations(),
        );
    }

    /** @param array<string, mixed> $spec */
    private function sales(string $field, array $spec): SettingDefinition
    {
        [$group, $name] = explode('.', $field, 2);
        $defaults = SalesSettings::defaults();

        // #549: the Sales Settings screen already has ONE default per key;
        // reuse it rather than declaring a second copy that could drift.
        $default = array_key_exists($group, $defaults) && array_key_exists($name, $defaults[$group])
            ? $defaults[$group][$name]
            : ($spec['default'] ?? null);

        return new SettingDefinition(
            screen: self::SCREEN_SALES,
            field: $field,
            key: $spec['key'],
            type: $spec['type'],
            default: $default,
            scope: 'tenant',
            editPermission: self::SALES_EDIT_PERMISSION,
            readers: $spec['readers'],
            via: $spec['via'] ?? [],
            path: $spec['path'] ?? null,
            keyParts: $spec['key_parts'] ?? [],
            note: $spec['note'] ?? null,
        );
    }

    /**
     * Sales Settings screen (GET/PUT /settings/sales), keyed by payload field.
     *
     * @return array<string, array<string, mixed>>
     */
    private function salesDeclarations(): array
    {
        $roundingVia = [
            CashRoundingService::class.'::settings',
            CashRoundingService::class.'::isEnabled',
            CashRoundingService::class.'::round',
            CashRoundingService::class.'::delta',
            CashRoundingService::class.'::accountId',
            CashRoundingService::class.'::accountIdOrFail',
        ];
        $roundingReaders = [Invoice::class.'::calculateTotals', InvoiceService::class.'::buildPostingDraft'];

        $declarations = [
            'cash_rounding.enabled' => ['key' => CashRoundingService::SETTING_KEY, 'path' => 'enabled', 'type' => 'bool', 'via' => $roundingVia, 'readers' => $roundingReaders],
            'cash_rounding.method' => ['key' => CashRoundingService::SETTING_KEY, 'path' => 'method', 'type' => 'enum', 'via' => $roundingVia, 'readers' => $roundingReaders],
            'cash_rounding.precision' => ['key' => CashRoundingService::SETTING_KEY, 'path' => 'precision', 'type' => 'decimal', 'via' => $roundingVia, 'readers' => $roundingReaders],
            'cash_rounding.account_id' => ['key' => CashRoundingService::SETTING_KEY, 'path' => 'account_id', 'type' => 'id', 'default' => null, 'via' => $roundingVia, 'readers' => [InvoiceService::class.'::buildPostingDraft']],

            'invoice.allow_custom_number' => ['key' => SalesSettings::ALLOW_CUSTOM_NUMBER, 'type' => 'bool', 'via' => [SalesSettings::class.'::allowCustomInvoiceNumber'], 'readers' => [InvoiceController::class.'::customInvoiceNumberingAllowed']],
            'invoice.field_profile' => ['key' => 'sales.invoice.field_profile', 'type' => 'enum', 'readers' => [CompanySettingsController::class.'::getSalesDocumentDefaults'], 'note' => 'Applied by the invoice and quotation entry screens through GET /settings/sales/defaults.'],
            'invoice.default_requires_approval' => ['key' => 'sales.invoice.default_requires_approval', 'type' => 'bool', 'readers' => [InvoiceService::class.'::createInvoiceInternal', InvoiceController::class.'::update']],
            'invoice.approval_expiry_days' => ['key' => SalesSettings::APPROVAL_EXPIRY_DAYS, 'type' => 'int', 'via' => [SalesSettings::class.'::approvalExpiryDays'], 'readers' => [InvoiceApprovalService::class.'::submit', SalesApprovalGate::class.'::expiryDays']],
            'invoice.auto_send_to_customer_on_post' => ['key' => SalesSettings::AUTO_SEND_ON_POST, 'type' => 'bool', 'via' => [SalesSettings::class.'::autoSendToCustomerOnPost'], 'readers' => [InvoiceController::class.'::post']],
            'invoice.allow_partial_payments' => ['key' => SalesSettings::ALLOW_PARTIAL_PAYMENTS, 'type' => 'bool', 'via' => [SalesSettings::class.'::allowPartialPayments'], 'readers' => [PaymentController::class.'::store', CustomerReceiptService::class.'::receive']],
            'invoice.default_payment_terms_days' => ['key' => SalesSettings::DEFAULT_PAYMENT_TERMS_DAYS, 'type' => 'int', 'via' => [SalesSettings::class.'::defaultPaymentTermsDays'], 'readers' => [InvoiceService::class.'::createInvoiceInternal', SalesOrderService::class.'::createInvoice', Customer::class.'::boot']],
            'invoice.default_notes' => ['key' => SalesSettings::DEFAULT_NOTES, 'type' => 'text', 'via' => [SalesSettings::class.'::defaultNotes'], 'readers' => [InvoiceService::class.'::createInvoiceInternal', SalesOrderService::class.'::createInvoice']],
            // Issue #326: warn or block when an invoice fails the credit check.
            'invoice.credit_limit_policy' => ['key' => SalesSettings::CREDIT_LIMIT_POLICY, 'type' => 'enum', 'default' => SalesSettings::CREDIT_LIMIT_POLICY_BLOCK, 'via' => [SalesSettings::class.'::invoiceCreditLimitPolicy'], 'readers' => [CreditCheckService::class.'::invoiceVerdict']],
            'invoice.default_terms' => ['key' => SalesSettings::DEFAULT_TERMS, 'type' => 'text', 'via' => [SalesSettings::class.'::defaultTerms'], 'readers' => [InvoiceService::class.'::createInvoiceInternal']],

            'portal.acceptance_enabled' => ['key' => SalesSettings::ACCEPTANCE_ENABLED, 'type' => 'bool', 'via' => [SalesSettings::class.'::acceptanceEnabled'], 'readers' => [InvoiceAcceptanceController::class.'::accept', InvoiceController::class.'::createAcceptanceLink', InvoiceController::class.'::sendBundle', InvoiceController::class.'::post']],
            'portal.require_signature' => ['key' => SalesSettings::REQUIRE_SIGNATURE, 'type' => 'bool', 'via' => [SalesSettings::class.'::requireSignature'], 'readers' => [InvoiceAcceptanceController::class.'::accept']],
            'portal.acceptance_link_expiry_days' => ['key' => SalesSettings::ACCEPTANCE_LINK_EXPIRY_DAYS, 'type' => 'int', 'via' => [SalesSettings::class.'::acceptanceLinkExpiryDays'], 'readers' => [InvoiceEvidenceService::class.'::createPortalAcceptanceLink']],
            'portal.payment_link_expiry_days' => ['key' => SalesSettings::PAYMENT_LINK_EXPIRY_DAYS, 'type' => 'int', 'via' => [SalesSettings::class.'::paymentLinkExpiryDays'], 'readers' => [InvoiceController::class.'::createPaymentLink', InvoiceController::class.'::sendBundle']],

            // MRD R10.3: the three tax fields are merged into the ONE
            // canonical `tax` block the tax engine reads.
            'tax.default_tax_rate' => ['key' => 'tax', 'path' => 'default_tax_id', 'type' => 'decimal', 'default' => null, 'readers' => [TaxEngineService::class.'::resolveRaw'], 'note' => 'Saved as the matching active tax rule (default_tax_id), never as a rate.'],
            'tax.prices_include_tax' => ['key' => 'tax', 'path' => 'pricing_mode', 'type' => 'bool', 'default' => false, 'readers' => [SettingsController::class.'::show'], 'note' => 'Only consumed by the product create screen (initial "tax included" value) through GET /settings; the tax engine does not read it.'],
            'tax.default_tax_treatment' => ['key' => 'tax', 'path' => 'default_tax_treatment', 'type' => 'enum', 'default' => null, 'readers' => [TaxEngineService::class.'::defaultTreatment']],

            'delivery.attach_pdf' => ['key' => SalesSettings::ATTACH_PDF, 'type' => 'bool', 'via' => [SalesSettings::class.'::attachPdf'], 'readers' => [InvoiceDeliveryService::class.'::sendEmail']],
            'delivery.cc_internal_email' => ['key' => SalesSettings::CC_INTERNAL_EMAIL, 'type' => 'email', 'via' => [SalesSettings::class.'::ccInternalEmail'], 'readers' => [InvoiceDeliveryService::class.'::sendEmail']],
            'delivery.document_type_on_send' => ['key' => SalesSettings::DOCUMENT_TYPE_ON_SEND, 'type' => 'enum', 'via' => [SalesSettings::class.'::documentTypeOnSend'], 'readers' => [InvoiceDeliveryService::class.'::deliver', InvoiceController::class.'::sendBundle']],

            'delivery_order.default_requires_approval' => ['key' => 'sales.delivery_order.default_requires_approval', 'type' => 'bool', 'readers' => [DeliveryOrderService::class.'::resolveRequiresApproval']],

            'defaults.salesperson_mode' => ['key' => SalesDefaultsService::SETTING_SALESPERSON_MODE, 'type' => 'enum', 'via' => [SalesDefaultsService::class.'::salespersonMode', SalesDefaultsService::class.'::defaultSalesperson', SalesDefaultsService::class.'::applyCreationDefaults'], 'readers' => [QuotationController::class.'::store', SalesOrderService::class.'::createOrderInsideTransaction']],
            'defaults.team_by_user' => ['key' => SalesDefaultsService::SETTING_TEAM_BY_USER, 'type' => 'map', 'default' => [], 'via' => [SalesDefaultsService::class.'::defaultTeam', SalesDefaultsService::class.'::applyCreationDefaults'], 'readers' => [QuotationController::class.'::store', SalesOrderService::class.'::createOrderInsideTransaction']],

            'pricelist.display' => ['key' => SalesPricelistDisplayService::SETTING_KEY, 'type' => 'enum', 'via' => [SalesPricelistDisplayService::class.'::displayMode', SalesPricelistDisplayService::class.'::applyToDocumentPayload'], 'readers' => [DataSourceRegistry::class.'::loadPayload']],

            // Issue #545: Quotations and Sales Orders sections.
            'quotation.default_validity_days' => ['key' => SalesSettings::QUOTATION_DEFAULT_VALIDITY_DAYS, 'type' => 'int', 'default' => SalesSettings::DEFAULT_QUOTATION_VALIDITY_DAYS, 'via' => [SalesSettings::class.'::quotationDefaultValidityDays'], 'readers' => [CompanySettingsController::class.'::getSalesDocumentDefaults'], 'note' => 'Applied by the quotation entry screen (pre-filled Valid Until) through GET /settings/sales/defaults.'],
            'quotation.default_terms' => ['key' => SalesSettings::QUOTATION_DEFAULT_TERMS, 'type' => 'text', 'default' => null, 'via' => [SalesSettings::class.'::quotationDefaultTerms'], 'readers' => [QuotationController::class.'::store', CompanySettingsController::class.'::getSalesDocumentDefaults']],
            'sales_order.auto_lock_on_confirm' => ['key' => SalesSettings::AUTO_LOCK_ON_CONFIRM, 'type' => 'bool', 'default' => false, 'via' => [SalesSettings::class.'::autoLockOnConfirm'], 'readers' => [SalesOrderService::class.'::autoLockOnConfirmEnabled']],
            'sales_order.default_invoicing_policy' => ['key' => SalesSettings::DEFAULT_INVOICING_POLICY, 'type' => 'enum', 'default' => 'ordered_qty', 'via' => [SalesSettings::class.'::defaultInvoicingPolicy'], 'readers' => [SalesOrderController::class.'::applyDefaultInvoicingPolicy']],
        ];

        // Issue #645: one reviewer pool per request type, all resolved by
        // the ONE reviewer resolver from a composed `{prefix}.reviewer_*` key.
        foreach (['invoice' => 'sales.invoice', 'quotation' => 'sales.quotation', 'sales_order' => 'sales.sales_order', 'invoice_threshold' => 'sales.invoice_threshold', 'delivery_order' => 'sales.delivery_order'] as $group => $prefix) {
            foreach (['reviewer_user_ids' => 'id_list', 'reviewer_roles' => 'string_list'] as $name => $type) {
                $declarations["{$group}.{$name}"] = [
                    'key' => "{$prefix}.{$name}",
                    'type' => $type,
                    'default' => [],
                    'key_parts' => [$prefix, ".{$name}"],
                    'readers' => [SalesApprovalGate::class.'::resolveReviewers'],
                ];
            }
        }

        return $declarations;
    }
}
