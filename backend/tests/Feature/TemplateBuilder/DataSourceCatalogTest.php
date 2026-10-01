<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;
use Modules\TemplateBuilder\Services\DataSource\InvoiceDataSource;
use Modules\TemplateBuilder\Services\DataSource\DeliveryOrderDataSource;
use Modules\TemplateBuilder\Services\DataSource\PurchaseOrderDataSource;
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
        $this->assertSame($sample['invoice']['terms'], $sample['invoice']['payment_terms']);
    }

    public function test_invoice_field_catalog_is_grouped(): void
    {
        $catalog = (new InvoiceDataSource)->fieldCatalog();

        $groups = array_column($catalog, 'group');
        $this->assertContains('Invoice', $groups);
        $this->assertContains('Customer', $groups);
        $this->assertContains('Totals', $groups);

        $tokens = collect($catalog)
            ->flatMap(fn (array $group) => array_column($group['fields'], 'token'));
        $this->assertTrue($tokens->contains('invoice.payment_terms'));
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
}
