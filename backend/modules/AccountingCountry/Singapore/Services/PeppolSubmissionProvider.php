<?php

namespace Modules\AccountingCountry\Singapore\Services;

use App\Accounting\Contracts\SubmissionProviderContract;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Singapore PEPPOL Submission Provider
 * Submits invoices to PEPPOL network via access point
 */
class PeppolSubmissionProvider implements SubmissionProviderContract
{
    protected Client $client;

    protected string $endpoint;

    protected bool $testMode;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client;
        $this->testMode = (bool) config('compliance.singapore.peppol_test_mode', true);
        // Issue #99: there is deliberately NO example.com fallback. A real
        // access-point endpoint must be configured; an unconfigured deployment
        // fails closed and is reported as unconfigured rather than pretending
        // to submit.
        $this->endpoint = trim((string) ($this->testMode
            ? config('compliance.singapore.peppol_test_endpoint', '')
            : config('compliance.singapore.peppol_endpoint', '')));
    }

    /**
     * Issue #99: whether a real Peppol access point is configured.
     */
    public function isConfigured(): bool
    {
        return $this->endpoint !== ''
            && filter_var($this->endpoint, FILTER_VALIDATE_URL) !== false
            && ! str_contains(parse_url($this->endpoint, PHP_URL_HOST) ?: '', 'example.com');
    }

    /**
     * Issue #99: XMLDSig signing requires an XML security library. Until one is
     * installed and wired, signed submission is unavailable and the provider
     * refuses to submit unsigned documents.
     */
    public function signingAvailable(): bool
    {
        return class_exists(\RobRichards\XMLSecLibs\XMLSecurityDSig::class)
            || class_exists(\RobRichards\XMLSecLibs\XMLSecurityKey::class);
    }

    /**
     * Submit invoice to PEPPOL network
     */
    public function submit(array $payload, array $credentials): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Singapore Peppol/InvoiceNow is not configured: set '
                    .'compliance.singapore.peppol_endpoint (and credentials) to a real '
                    .'access-point URL. No submission was attempted.',
            ];
        }

        // Issue #99: Peppol requires signed documents — never submit unsigned.
        if (empty($credentials['signing_key'])) {
            return [
                'success' => false,
                'message' => 'A signing key is required for Peppol submissions; refusing to submit an unsigned invoice.',
            ];
        }

        if (! $this->signingAvailable()) {
            return [
                'success' => false,
                'message' => 'XML digital signing is unavailable: install an XMLDSig library '
                    .'(e.g. robrichards/xmlseclibs) before submitting to Peppol. '
                    .'Refusing to submit an unsigned document.',
            ];
        }

        try {
            // Convert payload to UBL XML
            $xml = $this->convertToUBL($payload);

            // Validate XML structure
            if (! $this->validateXML($xml)) {
                return ['success' => false, 'message' => 'Invalid UBL XML generated'];
            }

            $xml = $this->signXML($xml, $credentials['signing_key']);

            // Submit to PEPPOL access point
            $response = $this->client->post("{$this->endpoint}/api/v1/invoices", [
                'headers' => $this->buildHeaders($credentials),
                'body' => $xml,
                'timeout' => 30,
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() === 202 || $response->getStatusCode() === 200) {
                return [
                    'success' => true,
                    'provider_reference' => $responseBody['reference_id'] ?? Str::uuid(),
                    'transaction_id' => $responseBody['transaction_id'] ?? null,
                    'status' => 'acknowledged',
                ];
            }

            return ['success' => false, 'message' => $responseBody['message'] ?? 'PEPPOL submission failed'];

        } catch (\Exception $e) {
            Log::error('PEPPOL submission error', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Submission error: '.$e->getMessage()];
        }
    }

    /**
     * Retry submission
     */
    public function retry(array $payload, string $providerReference, array $credentials): array
    {
        // Similar to submit, but with idempotency key
        return $this->submit($payload, array_merge($credentials, ['idempotency_key' => $providerReference]));
    }

    /**
     * Get submission status
     */
    public function getStatus(string $providerReference, array $credentials): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'Singapore Peppol/InvoiceNow is not configured.'];
        }

        try {
            $response = $this->client->get("{$this->endpoint}/api/v1/invoices/{$providerReference}", [
                'headers' => $this->buildHeaders($credentials),
                'timeout' => 10,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'status' => $data['status'] ?? 'unknown',
                'details' => $data,
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * #818 (EP7): Peppol has no recall — once the document reached the buyer's
     * access point it exists, and the only correction is a credit note. The
     * pack declares cancellation unsupported so the core routes the user to
     * the credit-note flow instead of issuing a cancellation nobody else saw.
     */
    public function supportsCancellation(): bool
    {
        return false;
    }

    /**
     * Cancel submission
     *
     * #818 (EP7): always refused. The previous implementation sent an HTTP
     * DELETE to a non-existent access-point endpoint and reported success on
     * HTTP 200 while the buyer still held the invoice. A queued-at-AP
     * withdrawal is not implemented (the access-point integration itself is
     * still the placeholder tracked in #706).
     */
    public function cancel(string $providerReference, array $credentials): array
    {
        return [
            'success' => false,
            'message' => 'Peppol/InvoiceNow does not support cancelling a delivered document; issue a credit note against the original invoice instead.',
        ];
    }

    /**
     * Convert payload to UBL XML
     */
    private function convertToUBL(array $payload): string
    {
        $documentType = strtolower(trim((string) ($payload['document_type'] ?? '')));

        // #817 (EP6): the provider picks the builder from document_type. A
        // credit note built as an Invoice reaches the buyer as a second
        // invoice, so anything that is not an invoice or a credit note is
        // refused rather than serialised as the wrong document.
        return match ($documentType) {
            '', 'invoice' => $this->buildUblDocument($payload, false),
            'creditnote', 'credit_note' => $this->buildUblDocument($payload, true),
            default => throw new \InvalidArgumentException(
                "Unsupported Peppol document type '{$documentType}': refusing to build a document that would misrepresent it."
            ),
        };
    }

    /**
     * Issue #99: build a real UBL 2.1 document (Peppol BIS 3 profile) with the
     * parties, monetary totals and lines, using DOMDocument so values are
     * escaped correctly.
     *
     * Issue #817 (EP6): an invoice and a credit note are different UBL
     * documents — root namespace, type code, line element names and the
     * mandatory BillingReference all differ.
     */
    private function buildUblDocument(array $payload, bool $isCreditNote): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElementNS(
            $isCreditNote
                ? 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2'
                : 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            $isCreditNote ? 'CreditNote' : 'Invoice'
        );
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $document->appendChild($root);

        $append = function (string $name, ?string $value) use ($document, $root) {
            $element = $document->createElement('cbc:'.$name);
            $element->appendChild($document->createTextNode((string) $value));
            $root->appendChild($element);
        };

        $append('CustomizationID', $payload['customization_id'] ?? 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0');
        $append('ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $append('ID', $payload['custom_id'] ?? $payload['invoice_number'] ?? '');
        $append('IssueDate', $payload['issue_date'] ?? now()->toDateString());
        $append('DueDate', $payload['due_date'] ?? null);
        $append(
            $isCreditNote ? 'CreditNoteTypeCode' : 'InvoiceTypeCode',
            $payload['type_code'] ?? ($isCreditNote ? '381' : '380')
        );
        $append('DocumentCurrencyCode', $payload['currency'] ?? 'SGD');

        if ($isCreditNote) {
            // The buyer cannot reconcile a credit without the reference to the
            // document it credits.
            $billingReference = $document->createElement('cac:BillingReference');
            $invoiceReference = $document->createElement('cac:InvoiceDocumentReference');
            $referenceId = $document->createElement('cbc:ID');
            $referenceId->appendChild($document->createTextNode(
                (string) ($payload['original_invoice_number'] ?? $payload['related_invoice_id'] ?? '')
            ));
            $invoiceReference->appendChild($referenceId);
            $billingReference->appendChild($invoiceReference);
            $root->appendChild($billingReference);
        }

        foreach ([
            'AccountingSupplierParty' => $payload['supplier'] ?? [],
            'AccountingCustomerParty' => $payload['customer'] ?? [],
        ] as $wrapperName => $party) {
            $wrapper = $document->createElement('cac:'.$wrapperName);
            $partyElement = $document->createElement('cac:Party');

            // #705 (SG2): the participant identifier is the UEN under Peppol
            // ICD 0195 (e.g. 0195:202312345A) — never the internal id.
            $partyId = is_array($party['party_id'] ?? null) ? $party['party_id'] : [];
            if (trim((string) ($partyId['value'] ?? '')) !== '') {
                $identification = $document->createElement('cac:PartyIdentification');
                $identifier = $document->createElement('cbc:ID');
                $identifier->setAttribute('schemeID', (string) ($partyId['scheme_id'] ?? '0195'));
                $identifier->appendChild($document->createTextNode((string) $partyId['value']));
                $identification->appendChild($identifier);
                $partyElement->appendChild($identification);
            }

            $partyNameValue = $party['party_name'] ?? $party['name'] ?? null;
            if (! empty($partyNameValue)) {
                $partyName = $document->createElement('cac:PartyName');
                $name = $document->createElement('cbc:Name');
                $name->appendChild($document->createTextNode((string) $partyNameValue));
                $partyName->appendChild($name);
                $partyElement->appendChild($partyName);
            }

            // #705 (SG2): the GST registration is a separate identifier from
            // the UEN and belongs in its own PartyTaxScheme.
            $partyTax = is_array($party['party_tax'] ?? null) ? $party['party_tax'] : [];
            if (trim((string) ($partyTax['value'] ?? '')) !== '') {
                $partyTaxScheme = $document->createElement('cac:PartyTaxScheme');
                $taxCompanyId = $document->createElement('cbc:CompanyID');
                $taxCompanyId->appendChild($document->createTextNode((string) $partyTax['value']));
                $partyTaxScheme->appendChild($taxCompanyId);
                $taxScheme = $document->createElement('cac:TaxScheme');
                $taxSchemeId = $document->createElement('cbc:ID');
                $taxSchemeId->appendChild($document->createTextNode((string) ($partyTax['scheme_id'] ?? 'GST')));
                $taxScheme->appendChild($taxSchemeId);
                $partyTaxScheme->appendChild($taxScheme);
                $partyElement->appendChild($partyTaxScheme);
            }

            $registrationValue = $party['party_legal_entity']['company_id'] ?? $party['registration'] ?? null;
            if (! empty($registrationValue)) {
                $legal = $document->createElement('cac:PartyLegalEntity');
                $registration = $document->createElement('cbc:CompanyID');
                $registration->appendChild($document->createTextNode((string) $registrationValue));
                $legal->appendChild($registration);
                $partyElement->appendChild($legal);
            }

            $wrapper->appendChild($partyElement);
            $root->appendChild($wrapper);
        }

        $taxTotal = $document->createElement('cac:TaxTotal');
        $currency = $payload['currency'] ?? 'SGD';
        $taxAmount = $document->createElement('cbc:TaxAmount', number_format((float) ($payload['tax_amount'] ?? 0), 2, '.', ''));
        $taxAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($taxAmount);

        // G-37 (#274): Peppol TaxTotal carries a TaxSubtotal per category
        // (S standard / Z zero-rated / E exempt / O out of scope). Falls back
        // to deriving one category from the totals when the mapper supplied
        // no breakdown.
        $taxSubtotalEntries = $payload['tax_subtotal'] ?? null;

        if (! is_array($taxSubtotalEntries) || $taxSubtotalEntries === []) {
            $taxSubtotalEntries = [[
                'tax_category_code' => (float) ($payload['tax_amount'] ?? 0) > 0 ? 'S' : 'Z',
                'percent' => $payload['tax_rate'] ?? 0,
                'taxable_amount' => ['amount' => $payload['subtotal'] ?? 0],
                'tax_amount' => ['amount' => $payload['tax_amount'] ?? 0],
            ]];
        }

        foreach ($taxSubtotalEntries as $entry) {
            $subtotal = $document->createElement('cac:TaxSubtotal');

            $taxableAmount = $document->createElement('cbc:TaxableAmount', number_format((float) ($entry['taxable_amount']['amount'] ?? 0), 2, '.', ''));
            $taxableAmount->setAttribute('currencyID', $currency);
            $subtotal->appendChild($taxableAmount);

            $subtotalTax = $document->createElement('cbc:TaxAmount', number_format((float) ($entry['tax_amount']['amount'] ?? 0), 2, '.', ''));
            $subtotalTax->setAttribute('currencyID', $currency);
            $subtotal->appendChild($subtotalTax);

            $percent = $document->createElement('cbc:Percent', number_format((float) ($entry['percent'] ?? 0), 2, '.', ''));
            $subtotal->appendChild($percent);

            $category = $document->createElement('cac:TaxCategory');
            $category->appendChild($document->createElement('cbc:ID', (string) ($entry['tax_category_code'] ?? 'S')));
            $taxScheme = $document->createElement('cac:TaxScheme');
            $taxScheme->appendChild($document->createElement('cbc:ID', 'GST'));
            $category->appendChild($taxScheme);
            $subtotal->appendChild($category);

            $taxTotal->appendChild($subtotal);
        }

        $root->appendChild($taxTotal);

        $monetaryTotal = $document->createElement('cac:LegalMonetaryTotal');
        foreach ([
            'LineExtensionAmount' => $payload['subtotal'] ?? 0,
            'TaxExclusiveAmount' => $payload['subtotal'] ?? 0,
            'TaxInclusiveAmount' => $payload['total'] ?? 0,
            'PayableAmount' => $payload['total'] ?? 0,
        ] as $name => $value) {
            $amount = $document->createElement('cbc:'.$name, number_format((float) $value, 2, '.', ''));
            $amount->setAttribute('currencyID', $payload['currency'] ?? 'SGD');
            $monetaryTotal->appendChild($amount);
        }
        $root->appendChild($monetaryTotal);

        foreach ($payload['lines'] ?? [] as $index => $line) {
            $lineElement = $document->createElement($isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
            $lineElement->appendChild($document->createElement('cbc:ID', (string) ($line['id'] ?? $index + 1)));
            $lineElement->appendChild($document->createElement($isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity', (string) ($line['quantity'] ?? 1)));

            $lineAmount = $document->createElement('cbc:LineExtensionAmount', number_format((float) ($line['line_total'] ?? 0), 2, '.', ''));
            $lineAmount->setAttribute('currencyID', $payload['currency'] ?? 'SGD');
            $lineElement->appendChild($lineAmount);

            $item = $document->createElement('cac:Item');
            $itemName = $document->createElement('cbc:Name');
            $itemName->appendChild($document->createTextNode((string) ($line['description'] ?? 'Item')));
            $item->appendChild($itemName);
            $lineElement->appendChild($item);

            $price = $document->createElement('cac:Price');
            $price->appendChild($document->createElement('cbc:PriceAmount', number_format((float) ($line['unit_price'] ?? 0), 2, '.', '')));
            $lineElement->appendChild($price);

            $root->appendChild($lineElement);
        }

        return $document->saveXML();
    }

    /**
     * Validate XML structure
     */
    private function validateXML(string $xml): bool
    {
        if (empty($xml) || ! str_starts_with($xml, '<?xml')) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = simplexml_load_string($xml);

            return $loaded !== false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Sign XML with the configured private key.
     *
     * Issue #99: this previously returned the document unsigned. Submissions
     * now require a real XMLDSig implementation (signingAvailable()), and this
     * method performs the actual enveloped signature once a library is
     * installed.
     */
    private function signXML(string $xml, string $signingKey): string
    {
        if (! $this->signingAvailable()) {
            throw new \RuntimeException(
                'XML digital signing is unavailable: install an XMLDSig library (robrichards/xmlseclibs).'
            );
        }

        $document = new \DOMDocument;
        $document->loadXML($xml);

        $signature = new \RobRichards\XMLSecLibs\XMLSecurityDSig;
        $signature->setCanonicalMethod(\RobRichards\XMLSecLibs\XMLSecurityDSig::EXC_C14N);
        $signature->addReference(
            $document,
            \RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature']
        );

        $key = new \RobRichards\XMLSecLibs\XMLSecurityKey(
            \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256,
            ['type' => 'private']
        );
        $key->loadKey($signingKey);

        $signature->sign($key);
        $signature->appendSignature($document->documentElement);

        return $document->saveXML();
    }

    /**
     * Build request headers
     */
    private function buildHeaders(array $credentials): array
    {
        return [
            'Content-Type' => 'application/xml',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.($credentials['api_key'] ?? ''),
            'X-Idempotency-Key' => $credentials['idempotency_key'] ?? Str::uuid(),
        ];
    }

    /**
     * Provider configuration requirements for SG Peppol.
     */
    public function getConfigRequirements(): array
    {
        return [
            'fields' => ['api_key', 'signing_key'],
            'description' => 'Peppol API key and optional XML signing key/certificate settings',
        ];
    }
}
