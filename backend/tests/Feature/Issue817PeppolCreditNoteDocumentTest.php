<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\AccountingCountry\Singapore\Services\PeppolSubmissionProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Issue #817 (EP6) — SFRSInvoiceMapper marks a credit note with
 * document_type = 'CreditNote', but PeppolSubmissionProvider::convertToUBL
 * always built a UBL Invoice: Invoice-2 root, InvoiceTypeCode 380, InvoiceLine
 * and no BillingReference. A credit note therefore reached the buyer as a
 * brand-new invoice for the credited amount.
 *
 * Contract asserted here:
 *  - a CreditNote payload produces a real UBL CreditNote document
 *    (CreditNote-2 root, CreditNoteTypeCode 381, CreditNoteLine /
 *    CreditedQuantity) with a BillingReference to the original invoice;
 *  - an Invoice payload is unchanged (Invoice-2 root, InvoiceTypeCode 380);
 *  - any other document_type is refused instead of being silently built as an
 *    invoice.
 *
 * The PINT SG schematron validation (AC3) is not asserted: no schematron
 * artifact exists in this repository and inventing one is out of bounds —
 * tracked on the issue.
 */
class Issue817PeppolCreditNoteDocumentTest extends TestCase
{
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private function convertToUBL(array $payload): string
    {
        $provider = new PeppolSubmissionProvider;

        $method = new ReflectionMethod($provider, 'convertToUBL');
        $method->setAccessible(true);

        return $method->invoke($provider, $payload);
    }

    private function invoicePayload(): array
    {
        return [
            'invoice_id' => 42,
            'document_type' => 'Invoice',
            'custom_id' => 'INV-2026-0001',
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'currency' => 'SGD',
            'subtotal' => 100.0,
            'tax_amount' => 9.0,
            'total' => 109.0,
            'supplier' => ['name' => 'Supplier Pte Ltd', 'registration' => '202600001A'],
            'customer' => ['name' => 'Customer Pte Ltd', 'registration' => '202600002B'],
            'lines' => [
                ['id' => 1, 'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100],
            ],
        ];
    }

    private function creditNotePayload(): array
    {
        return array_merge($this->invoicePayload(), [
            'document_type' => 'CreditNote',
            'custom_id' => 'CN-2026-0001',
            'related_invoice_id' => 42,
            'original_invoice_number' => 'INV-2026-0001',
        ]);
    }

    /**
     * @return array{0: \DOMDocument, 1: \DOMXPath}
     */
    private function dom(string $xml): array
    {
        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($xml), 'the provider must produce well-formed XML');

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cbc', self::NS_CBC);
        $xpath->registerNamespace('cac', self::NS_CAC);

        return [$document, $xpath];
    }

    public function test_a_credit_note_payload_produces_a_ubl_credit_note_with_a_billing_reference(): void
    {
        [$document, $xpath] = $this->dom($this->convertToUBL($this->creditNotePayload()));

        $this->assertSame(
            'CreditNote',
            $document->documentElement->localName,
            'a credit note must not be serialised as a UBL Invoice'
        );
        $this->assertSame(
            'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
            $document->documentElement->namespaceURI,
            'the root must be in the UBL CreditNote-2 namespace'
        );
        $this->assertSame('381', $xpath->evaluate('string(/*/cbc:CreditNoteTypeCode)'), 'a credit note declares type 381');
        $this->assertSame('CN-2026-0001', $xpath->evaluate('string(/*/cbc:ID)'), 'the document carries the credit note number');

        $this->assertSame(
            'INV-2026-0001',
            $xpath->evaluate('string(/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID)'),
            'a credit note must reference the invoice it credits'
        );

        $this->assertSame(1, $xpath->query('/*/cac:CreditNoteLine')->length, 'lines are CreditNoteLine elements');
        $this->assertSame('1', $xpath->evaluate('string(/*/cac:CreditNoteLine/cbc:CreditedQuantity)'));
        $this->assertSame(0, $xpath->query('/*/cbc:InvoiceTypeCode')->length, 'an invoice type code must not appear on a credit note');
        $this->assertSame(0, $xpath->query('/*/cac:InvoiceLine')->length, 'invoice lines must not appear on a credit note');
    }

    public function test_an_invoice_payload_is_still_a_ubl_invoice(): void
    {
        [$document, $xpath] = $this->dom($this->convertToUBL($this->invoicePayload()));

        $this->assertSame('Invoice', $document->documentElement->localName);
        $this->assertSame('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', $document->documentElement->namespaceURI);
        $this->assertSame('380', $xpath->evaluate('string(/*/cbc:InvoiceTypeCode)'));
        $this->assertSame('INV-2026-0001', $xpath->evaluate('string(/*/cbc:ID)'));
        $this->assertSame(1, $xpath->query('/*/cac:InvoiceLine')->length);
        $this->assertSame(0, $xpath->query('/*/cac:BillingReference')->length, 'an invoice needs no billing reference');
    }

    public function test_an_unknown_document_type_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->convertToUBL(array_merge($this->invoicePayload(), ['document_type' => 'SelfBilledInvoice']));
    }
}
