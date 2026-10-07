<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Modules\TemplateBuilder\Support\DefaultTemplates;
use Tests\TestCase;

/**
 * #1019 — the seeded default invoice template must be print-ready for a
 * Singapore GST tax invoice: GST registration number, per-code tax summary
 * and a signature/stamp area. Labels/tokens stay country-pack driven (#614);
 * this test only asserts the layout carries them.
 */
class DefaultInvoiceTemplateTest extends TestCase
{
    public function test_default_invoice_template_carries_statutory_and_signature_blocks(): void
    {
        $json = json_encode(DefaultTemplates::invoice());

        // Statutory/company data already exposed by #614.
        $this->assertStringContainsString('tax_summary', $json, 'Per-code tax summary table');
        $this->assertStringContainsString('company.gst_number', $json, 'GST registration number token');
        $this->assertStringContainsString('company.tin', $json, 'TIN token');
        $this->assertStringContainsString('company.bank.account_number', $json, 'Bank details');

        // #1019 signature area.
        $this->assertStringContainsString('Authorised Signature', $json);
        $this->assertStringContainsString('Received By', $json);
        $this->assertStringContainsString('Company Stamp', $json);
    }

    public function test_templates_derived_from_the_default_invoice_inherit_the_signature_block(): void
    {
        $quotation = json_encode(DefaultTemplates::quotation());

        $this->assertStringContainsString('Authorised Signature', $quotation);
        $this->assertStringContainsString('Company Stamp', $quotation);
    }

    public function test_delivery_order_default_template_keeps_its_signature_lines(): void
    {
        $json = json_encode(DefaultTemplates::deliveryOrder());

        $this->assertStringContainsString('Received by (Signature)', $json);
        $this->assertStringContainsString('Delivered by (Signature)', $json);
    }
}
