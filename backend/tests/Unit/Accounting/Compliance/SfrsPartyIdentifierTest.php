<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Compliance;

use Modules\AccountingCountry\Singapore\Services\PeppolSubmissionProvider;
use Modules\AccountingCountry\Singapore\Services\SFRSInvoiceMapper;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Issue #705 (SG2) — the SG party identifier said `scheme_id = 'SG_UEN'`
 * (not a Peppol participant scheme) and fell back to the internal database id
 * when the UEN was missing; the GST registration was never mapped, and the
 * UBL builder ignored the mapper's party shape entirely.
 *
 * Contract asserted here:
 *  - a party with a UEN maps to Peppol ICD scheme 0195;
 *  - a party without a UEN never falls back to the internal id, and the
 *    payload fails validation;
 *  - the UBL writes cac:PartyIdentification (schemeID 0195) and the separate
 *    GST registration as cac:PartyTaxScheme/CompanyID with TaxScheme GST.
 */
class SfrsPartyIdentifierTest extends TestCase
{
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private function mapParty(object $party): array
    {
        $mapper = new SFRSInvoiceMapper;

        $method = new ReflectionMethod($mapper, 'mapParty');
        $method->setAccessible(true);

        return $method->invoke($mapper, $party, 'customer');
    }

    /**
     * Model-like party: missing attributes resolve to null (Eloquent
     * behaviour) instead of raising an undefined-property warning.
     */
    private function party(array $attributes): object
    {
        return new class($attributes)
        {
            public function __construct(private array $attributes) {}

            public function __get(string $name): mixed
            {
                return $this->attributes[$name] ?? null;
            }

            public function __isset(string $name): bool
            {
                return isset($this->attributes[$name]);
            }
        };
    }

    private function partyWithUen(): array
    {
        return $this->mapParty($this->party([
            'id' => 42,
            'name' => 'Acme Pte Ltd',
            'company_registration_number' => '202312345A',
            'gst_registration_number' => 'GST-REG-0001',
        ]));
    }

    private function partyWithoutUen(): array
    {
        return $this->mapParty($this->party([
            'id' => 42,
            'name' => 'No Uen Pte Ltd',
        ]));
    }

    private function payloadWithParties(array $supplier, array $customer): array
    {
        return [
            'invoice_id' => 1,
            'document_type' => 'Invoice',
            'supplier' => $supplier,
            'customer' => $customer,
            'line_items' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100]],
            'totals' => ['gross_amount' => 109.0],
        ];
    }

    public function test_a_party_with_a_uen_maps_to_peppol_icd_0195(): void
    {
        $mapped = $this->partyWithUen();

        $this->assertSame('0195', $mapped['party_id']['scheme_id'], 'SG UEN uses Peppol ICD 0195, not an internal label');
        $this->assertSame('202312345A', $mapped['party_id']['value']);
        $this->assertSame('202312345A', $mapped['party_legal_entity']['company_id']);
        $this->assertSame('GST', $mapped['party_tax']['scheme_id']);
        $this->assertSame('GST-REG-0001', $mapped['party_tax']['value'], 'the GST registration is a separate identifier');
    }

    public function test_a_party_without_a_uen_never_falls_back_to_the_internal_id(): void
    {
        $mapped = $this->partyWithoutUen();

        $this->assertSame('0195', $mapped['party_id']['scheme_id']);
        $this->assertNotSame(42, $mapped['party_id']['value'], 'the internal database id must never be sent as a participant id');
        $this->assertTrue(
            trim((string) $mapped['party_id']['value']) === '',
            'a missing UEN maps to no identifier so validation can refuse it'
        );
    }

    public function test_a_payload_without_a_party_uen_fails_validation(): void
    {
        $mapper = new SFRSInvoiceMapper;

        $this->assertFalse(
            $mapper->validatePayload($this->payloadWithParties($this->partyWithoutUen(), $this->partyWithUen())),
            'a supplier without a UEN must be a validation error'
        );
        $this->assertFalse(
            $mapper->validatePayload($this->payloadWithParties($this->partyWithUen(), $this->partyWithoutUen())),
            'a customer without a UEN must be a validation error'
        );
    }

    public function test_a_payload_with_party_uens_passes_validation(): void
    {
        $mapper = new SFRSInvoiceMapper;

        $this->assertTrue(
            $mapper->validatePayload($this->payloadWithParties($this->partyWithUen(), $this->partyWithUen()))
        );
    }

    public function test_the_ubl_writes_party_identification_0195_and_the_gst_scheme(): void
    {
        $payload = array_merge($this->payloadWithParties($this->partyWithUen(), $this->partyWithUen()), [
            'custom_id' => 'INV-705-0001',
            'issue_date' => '2026-10-01',
            'currency' => 'SGD',
            'supplier' => $this->partyWithUen(),
            'customer' => $this->partyWithUen(),
            'lines' => [['id' => 1, 'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
        ]);

        $provider = new PeppolSubmissionProvider;

        $method = new ReflectionMethod($provider, 'convertToUBL');
        $method->setAccessible(true);

        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($method->invoke($provider, $payload)));

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cbc', self::NS_CBC);
        $xpath->registerNamespace('cac', self::NS_CAC);

        $supplier = '/*/cac:AccountingSupplierParty/cac:Party';

        $this->assertSame(
            '0195',
            $xpath->evaluate("string({$supplier}/cac:PartyIdentification/cbc:ID/@schemeID)"),
            'the UEN identifier carries the Peppol ICD 0195 scheme'
        );
        $this->assertSame(
            '202312345A',
            $xpath->evaluate("string({$supplier}/cac:PartyIdentification/cbc:ID)"),
            'the participant id is the UEN'
        );
        $this->assertSame(
            '202312345A',
            $xpath->evaluate("string({$supplier}/cac:PartyLegalEntity/cbc:CompanyID)")
        );
        $this->assertSame(
            'GST-REG-0001',
            $xpath->evaluate("string({$supplier}/cac:PartyTaxScheme/cbc:CompanyID)"),
            'the GST registration is written separately from the UEN'
        );
        $this->assertSame(
            'GST',
            $xpath->evaluate("string({$supplier}/cac:PartyTaxScheme/cac:TaxScheme/cbc:ID)")
        );
        $this->assertSame(
            'Acme Pte Ltd',
            $xpath->evaluate("string({$supplier}/cac:PartyName/cbc:Name)"),
            'the mapper party shape reaches the UBL (it used to be ignored)'
        );
    }
}
