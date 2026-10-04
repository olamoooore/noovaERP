<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;
use Modules\TemplateBuilder\Services\DataSource\DeliveryOrderDataSource;
use Modules\TemplateBuilder\Services\DataSource\InvoiceDataSource;
use Modules\TemplateBuilder\Services\DataSource\PurchaseOrderDataSource;
use Modules\TemplateBuilder\Support\CanonicalRegistrations;
use Modules\TemplateBuilder\Support\DefaultTemplates;
use Tests\TestCase;

class DataSourceCatalogTest extends TestCase
{
    public function test_invoice_sample_has_expected_shape(): void
    {
        $sample = (new InvoiceDataSource)->sample();

        $this->assertSame('invoice', InvoiceDataSource::documentType());
        $this->assertNotEmpty($sample['invoice']['number']);
        $this->assertIsArray($sample['lines']);
        $this->assertNotEmpty($sample['lines']);
        $this->assertArrayHasKey('subtotal', $sample['totals']);
        $this->assertArrayHasKey('currency_symbol', $sample['meta']);
    }

    public function test_invoice_field_catalog_is_grouped(): void
    {
        $catalog = (new InvoiceDataSource)->fieldCatalog();

        $groups = array_column($catalog, 'group');
        $this->assertContains('Invoice', $groups);
        $this->assertContains('Customer', $groups);
        $this->assertContains('Totals', $groups);
    }

    public function test_purchase_order_sample_has_expected_shape(): void
    {
        $sample = (new PurchaseOrderDataSource)->sample();

        $this->assertSame('purchase_order', PurchaseOrderDataSource::documentType());
        $this->assertNotEmpty($sample['po']['number']);
        $this->assertArrayHasKey('vendor', $sample);
        $this->assertIsArray($sample['lines']);
    }

    public function test_delivery_order_sample_exposes_canvas_totals_and_new_fields(): void
    {
        $dataSource = new DeliveryOrderDataSource;
        $sample = $dataSource->sample();

        $this->assertArrayHasKey('subtotal', $sample['totals']);
        $this->assertArrayHasKey('total', $sample['totals']);
        $this->assertArrayHasKey('due_date', $sample['delivery_order']);
        $this->assertArrayHasKey('payment_terms', $sample['delivery_order']);

        $tokens = collect($dataSource->fieldCatalog())
            ->flatMap(fn (array $group) => array_column($group['fields'], 'token'));
        $this->assertTrue($tokens->contains('totals.subtotal'));
        $this->assertTrue($tokens->contains('totals.total'));
    }

    public function test_registry_resolves_all_registered_document_types(): void
    {
        /** @var DataSourceRegistry $registry */
        $registry = app(DataSourceRegistry::class);

        foreach (['invoice', 'quotation', 'purchase_order', 'receipt'] as $type) {
            $this->assertTrue($registry->has($type), "Registry missing document type '{$type}'.");
        }

        // sampleFor should return a non-empty payload for each type.
        foreach ($registry->documentTypes() as $type) {
            $this->assertNotEmpty($registry->sampleFor($type), "Empty sample for '{$type}'.");
        }
    }

    /**
     * Issue #615 (TP5) — the CI guard: every registered business-document type
     * must either have a data source (so it can actually render) or be on the
     * explicit hide-list of types the editor must not offer.
     */
    public function test_every_registered_business_document_type_has_a_data_source_or_is_explicitly_hidden(): void
    {
        /** @var DataSourceRegistry $registry */
        $registry = app(DataSourceRegistry::class);
        $hidden = CanonicalRegistrations::unsupportedBusinessDocumentTypes();
        $registeredBusinessDocuments = [];

        foreach (CanonicalRegistrations::definitions() as $definition) {
            if ($definition['interaction_type'] !== InteractionType::BusinessDocument->value) {
                continue;
            }

            $canonical = $definition['template_type_code'];
            $registeredBusinessDocuments[] = $canonical;
            $dataSourceKey = CanonicalRegistrations::dataSourceKeyFor($canonical);

            $this->assertFalse(
                isset($hidden[$canonical]) && $dataSourceKey !== null,
                "Business-document type '{$canonical}' is listed as both supported and hidden (#615)."
            );

            if (isset($hidden[$canonical])) {
                $this->assertNull(
                    $dataSourceKey,
                    "Hidden business-document type '{$canonical}' must not declare a data-source key (#615)."
                );

                continue;
            }

            $this->assertNotNull(
                $dataSourceKey,
                "Business-document type '{$canonical}' has no data source and no hide-list entry (#615)."
            );
            $this->assertTrue(
                $registry->has($dataSourceKey),
                "No data source registered for business-document type '{$canonical}' (key '{$dataSourceKey}') (#615)."
            );
        }

        // The hide-list may only name canonical types the platform actually
        // registers — an entry for a removed/renamed type is stale and must
        // fail CI.
        $staleHidden = array_diff(array_keys($hidden), $registeredBusinessDocuments);
        $this->assertSame(
            [],
            array_values($staleHidden),
            'Hide-list entries must reference registered business-document types (#615): '.implode(', ', $staleHidden)
        );
    }

    /**
     * Issue #615 (TP5) — one key per type: the renderable data-source keys,
     * the registered sources and the starter templates must be the same set,
     * and no hidden type may leak into it.
     */
    public function test_data_sources_starters_and_the_key_map_are_one_per_type(): void
    {
        /** @var DataSourceRegistry $registry */
        $registry = app(DataSourceRegistry::class);

        $sort = function (array $values): array {
            sort($values);

            return $values;
        };

        $renderable = CanonicalRegistrations::dataSourceKeys();
        $registered = $registry->documentTypes();
        $starters = array_map(
            fn (array $definition): string => $definition['document_type'],
            DefaultTemplates::definitions(),
        );

        $this->assertSame(
            $sort($renderable),
            $sort($registered),
            'Every registered data source must be classified in the #615 key map, and every mapped key must have a registered source.'
        );
        $this->assertSame(
            $sort($renderable),
            $sort($starters),
            'Every renderable type needs exactly one starter template and no starter may exist for a non-renderable type.'
        );

        foreach (array_keys(CanonicalRegistrations::unsupportedBusinessDocumentTypes()) as $hiddenType) {
            $this->assertNotContains($hiddenType, $starters, "Hidden type '{$hiddenType}' must not ship a starter template (#615).");
            $this->assertFalse($registry->has($hiddenType), "Hidden type '{$hiddenType}' must not have a data source (#615).");
        }
    }

    /**
     * Issue #615 (TP5) — canonical and data-source keys resolve to the same
     * pair, so the resolver matches an assignment written either way.
     */
    public function test_canonical_and_data_source_keys_resolve_to_one_equivalent_pair(): void
    {
        $pairs = [
            'ar_invoice' => 'invoice',
            'sales_quotation' => 'quotation',
            'ar_official_receipt' => 'receipt',
            'ar_credit_note' => 'credit_note',
            'sales_order' => 'sales_order',
            'sales_order_confirmation' => 'sales_order_confirmation',
            'sales_proforma_invoice' => 'sales_proforma_invoice',
        ];

        foreach ($pairs as $canonical => $dataSourceKey) {
            $this->assertSame($dataSourceKey, CanonicalRegistrations::dataSourceKeyFor($canonical));
            $this->assertSame($canonical, CanonicalRegistrations::canonicalTypeCodeFor($dataSourceKey));
            $this->assertEqualsCanonicalizing([$canonical, $dataSourceKey], CanonicalRegistrations::keysFor($canonical));
            $this->assertEqualsCanonicalizing([$canonical, $dataSourceKey], CanonicalRegistrations::keysFor($dataSourceKey));
        }

        // Unknown/tenant-registered types keep matching themselves only.
        $this->assertSame(['tenant_custom_type'], CanonicalRegistrations::keysFor('tenant_custom_type'));
        $this->assertNull(CanonicalRegistrations::dataSourceKeyFor('tenant_custom_type'));
    }
}
