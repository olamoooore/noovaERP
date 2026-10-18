<?php

namespace Tests\Unit\Accounting\Compliance;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceSubmissionProvider;
use PHPUnit\Framework\TestCase;

class MyInvoiceSubmissionProviderTest extends TestCase
{
    private function makeProviderWithResponses(array $responses): MyInvoiceSubmissionProvider
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        return new MyInvoiceSubmissionProvider($client, 'https://sandbox.myinvoice.test');
    }

    private function validPayload(): array
    {
        return [
            'document_type' => 'invoice',
            'invoice_id' => 1,
            'invoice_number' => 'INV-MY-001',
            'invoice_date' => '2026-03-25',
            'currency_code' => 'MYR',
            'supplier' => [
                'name' => 'NoovaERP Sdn Bhd',
                'tin' => 'C2584563200',
                'company_registration' => '202401234567',
            ],
            'buyer' => [
                'name' => 'Customer Sdn Bhd',
            ],
            'line_items' => [
                [
                    'item_id' => '1',
                    'description' => 'Consulting Service',
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'line_amount' => 100.00,
                ],
            ],
            'totals' => [
                'gross_amount' => 106.00,
            ],
        ];
    }

    public function test_submit_returns_acknowledged_result_for_sandbox_success(): void
    {
        $provider = $this->makeProviderWithResponses([
            new Response(201, [], json_encode([
                'submissionUID' => 'MY-SUB-001',
                'response_code' => 'RECEIVED',
                'status' => 'submitted',
                'acceptedDocuments' => [],
                'rejectedDocuments' => [],
            ])),
        ]);

        $result = $provider->submit($this->validPayload(), [
            'api_key' => 'sandbox-key',
            'api_token' => 'sandbox-token',
            'idempotency_key' => 'idem-001',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('MY-SUB-001', $result['provider_reference']);
        $this->assertSame('acknowledged', $result['status']);
        $this->assertSame('idem-001', $result['idempotency_key']);
    }

    public function test_submit_accepts_individual_supplier_identity_without_company_registration(): void
    {
        $provider = $this->makeProviderWithResponses([
            new Response(201, [], json_encode([
                'submissionUID' => 'MY-SUB-005',
                'response_code' => 'RECEIVED',
                'status' => 'submitted',
            ])),
        ]);

        $payload = $this->validPayload();
        unset($payload['supplier']['company_registration']);
        $payload['supplier']['identity_type'] = 'NRIC';
        $payload['supplier']['identity_number'] = '840516145713';

        $result = $provider->submit($payload, [
            'api_key' => 'sandbox-key',
            'api_token' => 'sandbox-token',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('MY-SUB-005', $result['provider_reference']);
    }

    public function test_get_status_maps_accepted_status_correctly(): void
    {
        $provider = $this->makeProviderWithResponses([
            new Response(200, [], json_encode([
                'submissionUid' => 'MY-SUB-002',
                'overallStatus' => 'valid',
                'documentSummary' => [[
                    'uuid' => 'DOC-002',
                    'longId' => 'LONG-002',
                    'status' => 'Valid',
                ]],
            ])),
        ]);

        $result = $provider->getStatus('MY-SUB-002', [
            'api_key' => 'sandbox-key',
            'api_token' => 'sandbox-token',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame('DOC-002', $result['external_document_uuid']);
        $this->assertSame('LONG-002', $result['external_long_id']);
        $this->assertSame('https://sandbox.myinvoice.test/DOC-002/share/LONG-002', $result['validation_link']);
    }

    public function test_get_status_maps_rejected_to_finance_actionable_category(): void
    {
        $provider = $this->makeProviderWithResponses([
            new Response(200, [], json_encode([
                'submissionUid' => 'MY-SUB-003',
                'overallStatus' => 'invalid',
                'documentSummary' => [[
                    'uuid' => 'DOC-003',
                    'status' => 'Invalid',
                ]],
            ])),
            new Response(200, [], json_encode([
                'uuid' => 'DOC-003',
                'status' => 'Invalid',
                'validationResults' => [
                    'status' => 'Invalid',
                    'validationSteps' => [
                        [
                            'name' => 'Tax Validator',
                            'status' => 'Invalid',
                            'error' => [
                                'code' => 'INVALID_TAX_CODE',
                                'message' => 'Tax code SSTX is invalid for this document type',
                            ],
                        ],
                    ],
                ],
            ])),
        ]);

        $result = $provider->getStatus('MY-SUB-003', [
            'api_key' => 'sandbox-key',
            'api_token' => 'sandbox-token',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('tax_mapping_error', $result['error_category']);
        $this->assertStringContainsString('tax code', strtolower($result['finance_action']));
        $this->assertSame('DOC-003', $result['external_document_uuid']);
        $this->assertNotEmpty($result['details']['validation_errors']);
    }

    public function test_submit_rejects_non_consolidated_buyer_without_tin(): void
    {
        $provider = $this->makeProviderWithResponses([]);

        $payload = $this->validPayload();
        unset($payload['buyer']['tin'], $payload['buyer']['company_registration']);
        // G-37 (#274): an explicitly non-consolidated document must not be
        // silently upgraded to consolidated; it requires a real buyer TIN.
        $payload['is_consolidated'] = false;
        $result = $provider->submit($payload, [
            'api_key' => 'sandbox-key',
            'api_token' => 'sandbox-token',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('PAYLOAD_VALIDATION_FAILED', $result['error_code']);
        $this->assertSame('Buyer TIN is required for non-consolidated invoices', $result['message']);
    }

    public function test_build_payload_omits_signature_blocks_when_not_signed(): void
    {
        $provider = $this->makeProviderWithResponses([]);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $this->validPayload());
        $invoice = $document['Invoice'][0];

        $this->assertArrayNotHasKey('UBLExtensions', $invoice);
        $this->assertArrayNotHasKey('Signature', $invoice);
    }

    public function test_build_payload_includes_supplier_sst_and_ttx_identifiers_as_na_when_not_registered(): void
    {
        $provider = $this->makeProviderWithResponses([]);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $this->validPayload());
        $identifications = $document['Invoice'][0]['AccountingSupplierParty'][0]['Party'][0]['PartyIdentification'];

        $schemeIds = array_map(static fn (array $item): string => $item['ID'][0]['schemeID'] ?? '', $identifications);
        $valuesByScheme = [];
        foreach ($identifications as $item) {
            $valuesByScheme[$item['ID'][0]['schemeID'] ?? ''] = $item['ID'][0]['_'] ?? null;
        }

        $this->assertSame(['TIN', 'BRN', 'SST', 'TTX'], $schemeIds);
        $this->assertSame('NA', $valuesByScheme['SST'] ?? null);
        $this->assertSame('NA', $valuesByScheme['TTX'] ?? null);
    }

    public function test_build_payload_normalizes_foreign_country_and_state_defaults(): void
    {
        $provider = $this->makeProviderWithResponses([]);
        $payload = $this->validPayload();
        $payload['buyer']['address'] = [
            'street' => '10 Example Road',
            'city' => 'Jebba',
            'postal_code' => '241101',
            'country' => 'Singapore',
        ];

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $payload);
        $postalAddress = $document['Invoice'][0]['AccountingCustomerParty'][0]['Party'][0]['PostalAddress'][0];

        $this->assertSame('SGP', $postalAddress['Country'][0]['IdentificationCode'][0]['_']);
        $this->assertSame('NA', $postalAddress['CountrySubentityCode'][0]['_']);
        $this->assertSame('Jebba', $postalAddress['CityName'][0]['_']);
        $this->assertSame('241101', $postalAddress['PostalZone'][0]['_']);
    }

    public function test_build_payload_uses_minimal_address_shape_when_address_is_missing(): void
    {
        $provider = $this->makeProviderWithResponses([]);
        $payload = $this->validPayload();
        $payload['buyer']['address'] = [];

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $payload);
        $postalAddress = $document['Invoice'][0]['AccountingCustomerParty'][0]['Party'][0]['PostalAddress'][0];

        // G-37 (#274): a buyer without TIN is auto-classified consolidated, and
        // LHDN requires state '17' (Not Applicable) for consolidated MY invoices
        // instead of the invalid 'NA' literal.
        $this->assertSame('17', $postalAddress['CountrySubentityCode'][0]['_']);
        $this->assertSame('NA', $postalAddress['AddressLine'][0]['Line'][0]['_']);
        $this->assertSame('NA', $postalAddress['CityName'][0]['_']);
        $this->assertSame('00000', $postalAddress['PostalZone'][0]['_']);
    }

    public function test_build_payload_uses_consolidated_general_buyer_defaults_only_when_explicitly_marked(): void
    {
        $provider = $this->makeProviderWithResponses([]);
        $payload = $this->validPayload();
        $payload['is_consolidated'] = true;
        $payload['classification_code'] = '004';
        $payload['buyer'] = [
            'name' => 'General Public',
            'tin' => 'EI00000000010',
            'address' => [],
        ];

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $payload);
        $buyerParty = $document['Invoice'][0]['AccountingCustomerParty'][0]['Party'][0];
        $identifications = $buyerParty['PartyIdentification'];
        $postalAddress = $buyerParty['PostalAddress'][0];

        $valuesByScheme = [];
        foreach ($identifications as $item) {
            $valuesByScheme[$item['ID'][0]['schemeID'] ?? ''] = $item['ID'][0]['_'] ?? null;
        }

        $this->assertSame('EI00000000010', $valuesByScheme['TIN'] ?? null);
        $this->assertSame('NA', $valuesByScheme['BRN'] ?? null);
        $this->assertArrayNotHasKey('NRIC', $valuesByScheme);
        $this->assertSame('17', $postalAddress['CountrySubentityCode'][0]['_']);
    }

    public function test_build_payload_orders_buyer_party_elements_to_match_sample_sequence(): void
    {
        $provider = $this->makeProviderWithResponses([]);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $this->validPayload());
        $buyerParty = $document['Invoice'][0]['AccountingCustomerParty'][0]['Party'][0];

        $this->assertSame(
            ['PostalAddress', 'PartyLegalEntity', 'PartyIdentification', 'Contact'],
            array_keys($buyerParty)
        );
    }

    public function test_build_payload_clamps_old_issue_datetime_to_recent_utc_timestamp(): void
    {
        $provider = $this->makeProviderWithResponses([]);
        $payload = $this->validPayload();
        $payload['invoice_date'] = '2026-02-03';
        $payload['issue_datetime'] = '2026-02-03T00:00:00Z';

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $document = $method->invoke($provider, $payload);
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $issueDate = $document['Invoice'][0]['IssueDate'][0]['_'];
        $issueTime = $document['Invoice'][0]['IssueTime'][0]['_'];
        $issuedAt = new \DateTimeImmutable($issueDate.'T'.rtrim($issueTime, 'Z').'Z');

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $issuedAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $issuedAt->getTimestamp());
    }

    public function test_build_payload_falls_back_when_supplier_phone_is_blank(): void
    {
        $provider = $this->makeProviderWithResponses([]);
        $payload = $this->validPayload();
        $payload['supplier']['contact'] = [
            'phone' => '   ',
            'email' => 'supplier@example.com',
        ];

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $payload);
        $contact = $document['Invoice'][0]['AccountingSupplierParty'][0]['Party'][0]['Contact'][0];

        $this->assertSame('+60000000000', $contact['Telephone'][0]['_']);
        $this->assertSame('supplier@example.com', $contact['ElectronicMail'][0]['_']);
    }

    public function test_build_payload_includes_signature_blocks_when_signed_metadata_present(): void
    {
        $provider = $this->makeProviderWithResponses([]);
        $payload = $this->validPayload();
        $payload['_signature_metadata'] = [
            'enabled' => true,
            'signature_value' => 'abc123base64',
            'digest_value' => 'digestbase64',
            'signed_at' => '2026-04-06T09:28:53Z',
            'key_id' => 'urn:uuid:test-signature',
            'signatory_name' => 'NoovaERP Sdn Bhd',
            'certificate' => [
                'serial_number' => 'SER123',
            ],
        ];

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $payload);
        $invoice = $document['Invoice'][0];

        $this->assertArrayHasKey('UBLExtensions', $invoice);
        $this->assertArrayHasKey('Signature', $invoice);
        $this->assertSame('abc123base64', $invoice['UBLExtensions'][0]['UBLExtension'][0]['ExtensionContent'][0]['Signature'][0]['SignatureValue'][0]['_']);
    }

    /**
     * #702 (MY1): a document carrying the digital signature must declare the
     * signed schema version 1.1. The unsigned path stays on 1.0 — see
     * MyInvoiceMapperTest's ERR237 regression guard.
     */
    public function test_signed_payload_declares_version_1_1(): void
    {
        $provider = $this->makeProviderWithResponses([]);
        $payload = $this->validPayload();
        $payload['_signature_metadata'] = [
            'enabled' => true,
            'signature_value' => 'abc123base64',
            'digest_value' => 'digestbase64',
            'key_id' => 'urn:uuid:test-signature',
            'certificate' => ['serial_number' => 'SER123'],
        ];

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $payload);
        $invoice = $document['Invoice'][0];

        $this->assertSame(
            '1.1',
            $invoice['InvoiceTypeCode'][0]['listVersionID'] ?? null,
            'a signed document must declare the signed-schema version 1.1'
        );
        $this->assertArrayHasKey('UBLExtensions', $invoice);
    }

    /**
     * #702 (MY1): an unsigned document may go to the sandbox, but production
     * must refuse it — before any provider call.
     */
    public function test_production_unsigned_submission_is_blocked_with_signature_required(): void
    {
        $provider = $this->makeProviderWithResponses([
            new Response(201, [], json_encode([
                'submissionUID' => 'MY-SUB-UNSIGNED',
                'response_code' => 'RECEIVED',
                'status' => 'submitted',
            ])),
        ]);

        $result = $provider->submit($this->validPayload(), [
            'api_key' => 'prod-key',
            'api_token' => 'prod-token',
            'test_mode' => false,
        ]);

        $this->assertFalse($result['success'], 'an unsigned production submission must be refused');
        $this->assertSame('SIGNATURE_REQUIRED_IN_PRODUCTION', $result['error_code'] ?? null);
        $this->assertStringContainsString('digital signature', $result['message']);
    }

    public function test_production_signed_submission_proceeds(): void
    {
        $provider = $this->makeProviderWithResponses([
            new Response(201, [], json_encode([
                'submissionUID' => 'MY-SUB-SIGNED',
                'response_code' => 'RECEIVED',
                'status' => 'submitted',
            ])),
        ]);

        $payload = $this->validPayload();
        $payload['_signature_metadata'] = [
            'enabled' => true,
            'signature_value' => 'abc123base64',
        ];

        $result = $provider->submit($payload, [
            'api_key' => 'prod-key',
            'api_token' => 'prod-token',
            'test_mode' => false,
        ]);

        $this->assertTrue($result['success'], 'a signed production submission must not be blocked by the signature policy');
    }

    public function test_sandbox_unsigned_submission_is_still_allowed(): void
    {
        $provider = $this->makeProviderWithResponses([
            new Response(201, [], json_encode([
                'submissionUID' => 'MY-SUB-SANDBOX',
                'response_code' => 'RECEIVED',
                'status' => 'submitted',
            ])),
        ]);

        $result = $provider->submit($this->validPayload(), [
            'api_key' => 'sandbox-key',
            'api_token' => 'sandbox-token',
            'test_mode' => true,
        ]);

        $this->assertTrue($result['success'], 'the sandbox still accepts unsigned documents');
    }

    public function test_production_handwritten_only_metadata_does_not_count_as_a_digital_signature(): void
    {
        $provider = $this->makeProviderWithResponses([
            new Response(201, [], json_encode([
                'submissionUID' => 'MY-SUB-HANDWRITTEN',
                'response_code' => 'RECEIVED',
                'status' => 'submitted',
            ])),
        ]);

        $payload = $this->validPayload();
        $payload['_signature_metadata'] = [
            'enabled' => true,
            'mode' => 'handwritten',
            'handwritten_signature' => ['data_url' => 'data:image/png;base64,AAAA'],
        ];

        $result = $provider->submit($payload, [
            'api_key' => 'prod-key',
            'api_token' => 'prod-token',
            'test_mode' => false,
        ]);

        $this->assertFalse(
            $result['success'],
            'LHDN requires the digital certificate — a handwritten signature is not a signed submission'
        );
        $this->assertSame('SIGNATURE_REQUIRED_IN_PRODUCTION', $result['error_code'] ?? null);
    }

    public function test_submit_fails_locally_when_header_tax_does_not_match_line_taxes(): void
    {
        $provider = $this->makeProviderWithResponses([]);
        $payload = $this->validPayload();
        $payload['line_items'][0]['quantity'] = 12;
        $payload['line_items'][0]['unit_price'] = 120;
        $payload['line_items'][0]['line_amount'] = 1512;
        $payload['line_items'][0]['sst_rate'] = 5;
        $payload['line_items'][0]['sst_amount'] = 75.6;
        $payload['totals']['sst_amount'] = 72.0;
        $payload['totals']['gross_amount'] = 1584.0;

        $result = $provider->submit($payload, [
            'api_key' => 'sandbox-key',
            'api_token' => 'sandbox-token',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('PAYLOAD_VALIDATION_FAILED', $result['error_code']);
        $this->assertStringContainsString('does not match the sum of line taxes', $result['message']);
    }

    public function test_cancel_returns_cancelled_status_for_sandbox_path(): void
    {
        $provider = $this->makeProviderWithResponses([
            new Response(200, [], json_encode([
                'status' => 'cancelled',
                'reason' => 'Cancelled by issuer',
            ])),
        ]);

        $result = $provider->cancel('DOC-004', [
            'api_key' => 'sandbox-key',
            'api_token' => 'sandbox-token',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame('cancelled', $result['provider_status']);
    }

    // -------------------------------------------------------------------------
    // Helpers for document-type tests
    // -------------------------------------------------------------------------

    /** Creates a provider that records every outgoing request into $container. */
    private function makeProviderCapturing(array $responses, array &$container): MyInvoiceSubmissionProvider
    {
        $mock = new MockHandler($responses);
        $history = Middleware::history($container);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = new Client(['handler' => $stack]);

        return new MyInvoiceSubmissionProvider($client, 'https://sandbox.myinvoice.test');
    }

    /** Decodes the UBL Invoice array from the first captured HTTP request. */
    private function captureUbl(array $container): array
    {
        $body = (string) $container[0]['request']->getBody();
        $outer = json_decode($body, true);
        $encoded = $outer['documents'][0]['document'] ?? '';
        $decoded = base64_decode($encoded, true);

        return json_decode($decoded, true) ?? [];
    }

    private function submittedCredentials(): array
    {
        return ['api_token' => 'sandbox-token'];
    }

    private function acceptedResponse(string $ref = 'SUB-001'): Response
    {
        return new Response(201, [], json_encode([
            'submissionUID' => $ref,
            'status' => 'submitted',
        ]));
    }

    /** Base payload shared by all document-type tests. */
    private function noteBasePayload(): array
    {
        return [
            'document_type' => 'credit_note',
            'invoice_number' => 'CN-2026-0001',
            'invoice_date' => '2026-07-01',
            'currency_code' => 'MYR',
            'supplier' => [
                'name' => 'NoovaERP Sdn Bhd',
                'tin' => 'C2584563200',
                'company_registration' => '202401234567',
                'msic_code' => '62010',
                'address' => [
                    'street' => '1 Jalan Test',
                    'city' => 'Kuala Lumpur',
                    'state' => 'Wilayah Persekutuan',
                    'country' => 'MYS',
                ],
            ],
            'buyer' => [
                'name' => 'Buyer Sdn Bhd',
                'tin' => 'C1234567890',
                'company_registration' => '202300012345',
                'address' => [
                    'street' => '2 Jalan Buyer',
                    'city' => 'Petaling Jaya',
                    'state' => 'Selangor',
                    'country' => 'MYS',
                ],
            ],
            'line_items' => [[
                'description' => 'Credit for INV-2026-0005',
                'quantity' => 1,
                'unit_price' => 100.00,
                'line_amount' => 100.00,
            ]],
            'totals' => ['gross_amount' => 100.00],
        ];
    }

    // -------------------------------------------------------------------------
    // Document type code tests
    // -------------------------------------------------------------------------

    public function test_credit_note_produces_type_code_02(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->noteBasePayload();
        $payload['document_type'] = 'credit_note';
        $payload['original_invoice_references'] = [
            ['invoice_number' => 'INV-2026-0005', 'uuid' => 'lhdn-uuid-0005'],
        ];

        $result = $provider->submit($payload, $this->submittedCredentials());

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $ubl = $this->captureUbl($container);
        $invoice = $ubl['Invoice'][0];

        $this->assertSame('02', $invoice['InvoiceTypeCode'][0]['_'], 'Credit Note must be type 02');
    }

    public function test_debit_note_produces_type_code_03(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->noteBasePayload();
        $payload['document_type'] = 'debit_note';
        $payload['invoice_number'] = 'DN-2026-0001';
        $payload['original_invoice_references'] = [
            ['invoice_number' => 'INV-2026-0005'],
        ];

        $result = $provider->submit($payload, $this->submittedCredentials());

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $ubl = $this->captureUbl($container);
        $this->assertSame('03', $ubl['Invoice'][0]['InvoiceTypeCode'][0]['_'], 'Debit Note must be type 03');
    }

    public function test_refund_note_produces_type_code_04(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->noteBasePayload();
        $payload['document_type'] = 'refund_note';
        $payload['invoice_number'] = 'RN-2026-0001';
        $payload['original_invoice_references'] = [
            ['invoice_number' => 'INV-2026-0005'],
        ];

        $result = $provider->submit($payload, $this->submittedCredentials());

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $ubl = $this->captureUbl($container);
        $this->assertSame('04', $ubl['Invoice'][0]['InvoiceTypeCode'][0]['_'], 'Refund Note must be type 04');
    }

    public function test_self_billed_invoice_produces_type_code_11(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->noteBasePayload();
        $payload['document_type'] = 'self_billed_invoice';
        $payload['invoice_number'] = 'SBI-2026-0001';
        unset($payload['original_invoice_references']);

        $result = $provider->submit($payload, $this->submittedCredentials());

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $ubl = $this->captureUbl($container);
        $this->assertSame('11', $ubl['Invoice'][0]['InvoiceTypeCode'][0]['_'], 'Self-Billed Invoice must be type 11 (LHDN e-Invoice API v1.0)');
    }

    public function test_self_billed_credit_note_produces_type_code_12(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->noteBasePayload();
        $payload['document_type'] = 'self_billed_credit_note';
        $payload['invoice_number'] = 'SBCN-2026-0001';
        $payload['original_invoice_references'] = [
            ['invoice_number' => 'SBI-2026-0001'],
        ];

        $result = $provider->submit($payload, $this->submittedCredentials());

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $ubl = $this->captureUbl($container);
        $this->assertSame('12', $ubl['Invoice'][0]['InvoiceTypeCode'][0]['_']);
    }

    // -------------------------------------------------------------------------
    // BillingReference structure tests
    // -------------------------------------------------------------------------

    public function test_credit_note_billing_reference_uses_invoice_document_reference_with_uuid(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->noteBasePayload();
        $payload['document_type'] = 'credit_note';
        $payload['original_invoice_references'] = [
            ['invoice_number' => 'INV-2026-0005', 'uuid' => 'lhdn-uuid-abc-123'],
        ];

        $provider->submit($payload, $this->submittedCredentials());

        $invoice = $this->captureUbl($container)['Invoice'][0];
        $bilRef = $invoice['BillingReference'][0];

        $this->assertArrayHasKey('InvoiceDocumentReference', $bilRef, 'Must use InvoiceDocumentReference, not AdditionalDocumentReference');
        $this->assertArrayNotHasKey('AdditionalDocumentReference', $bilRef);

        $docRef = $bilRef['InvoiceDocumentReference'][0];
        $this->assertSame('INV-2026-0005', $docRef['ID'][0]['_']);
        $this->assertSame('lhdn-uuid-abc-123', $docRef['UUID'][0]['_'], 'IRBM UUID must be present');
    }

    public function test_credit_note_billing_reference_without_uuid_omits_uuid_field(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->noteBasePayload();
        $payload['original_invoice_references'] = [
            ['invoice_number' => 'INV-2026-0005'],   // no uuid
        ];

        $provider->submit($payload, $this->submittedCredentials());

        $docRef = $this->captureUbl($container)['Invoice'][0]['BillingReference'][0]['InvoiceDocumentReference'][0];
        $this->assertSame('INV-2026-0005', $docRef['ID'][0]['_']);
        $this->assertArrayNotHasKey('UUID', $docRef, 'UUID must be absent when not provided');
    }

    public function test_credit_note_supports_multiple_original_references(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->noteBasePayload();
        $payload['original_invoice_references'] = [
            ['invoice_number' => 'INV-2026-0003', 'uuid' => 'uuid-0003'],
            ['invoice_number' => 'INV-2026-0004', 'uuid' => 'uuid-0004'],
        ];

        $provider->submit($payload, $this->submittedCredentials());

        $billingRefs = $this->captureUbl($container)['Invoice'][0]['BillingReference'];
        $this->assertCount(2, $billingRefs, 'Each original invoice must get its own BillingReference entry');

        $this->assertSame('INV-2026-0003', $billingRefs[0]['InvoiceDocumentReference'][0]['ID'][0]['_']);
        $this->assertSame('INV-2026-0004', $billingRefs[1]['InvoiceDocumentReference'][0]['ID'][0]['_']);
    }

    public function test_legacy_original_invoice_number_scalar_still_works_for_credit_note(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->noteBasePayload();
        $payload['original_invoice_number'] = 'INV-2026-0005';
        $payload['original_invoice_uuid'] = 'legacy-uuid-xyz';

        $provider->submit($payload, $this->submittedCredentials());

        $docRef = $this->captureUbl($container)['Invoice'][0]['BillingReference'][0]['InvoiceDocumentReference'][0];
        $this->assertSame('INV-2026-0005', $docRef['ID'][0]['_']);
        $this->assertSame('legacy-uuid-xyz', $docRef['UUID'][0]['_']);
    }

    // -------------------------------------------------------------------------
    // Regression — invoices must still produce type 01
    // -------------------------------------------------------------------------

    public function test_regular_invoice_still_produces_type_code_01(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $result = $provider->submit($this->validPayload(), $this->submittedCredentials());

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $ubl = $this->captureUbl($container);
        $this->assertSame('01', $ubl['Invoice'][0]['InvoiceTypeCode'][0]['_'], 'Regular invoice must remain type 01');
    }

    public function test_consolidated_invoice_produces_type_code_01_not_04(): void
    {
        $container = [];
        $provider = $this->makeProviderCapturing([$this->acceptedResponse()], $container);

        $payload = $this->validPayload();
        $payload['document_type'] = 'consolidated_invoice';
        $payload['buyer']['tin'] = 'EI00000000010';

        $result = $provider->submit($payload, $this->submittedCredentials());

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $ubl = $this->captureUbl($container);
        $this->assertSame('01', $ubl['Invoice'][0]['InvoiceTypeCode'][0]['_'],
            'Consolidated invoice uses type 01 per LHDN spec; buyer TIN EI00000000010 distinguishes it');
        $this->assertArrayNotHasKey(
            'BillingPeriod',
            $ubl['Invoice'][0],
            'MyInvois JSON 1.0 rejects BillingPeriod as an additional property'
        );
    }

    public function test_build_payload_emits_per_category_tax_subtotals_and_exemption_fields(): void
    {
        $provider = $this->makeProviderWithResponses([]);

        $payload = $this->validPayload();
        $payload['line_items'] = [
            ['line_number' => 1, 'description' => 'Standard sale', 'quantity' => 1, 'unit_price' => 100, 'line_amount' => 100, 'sst_rate' => 9, 'sst_amount' => 9, 'tax_type_code' => '01'],
            ['line_number' => 2, 'description' => 'Zero-rated export', 'quantity' => 1, 'unit_price' => 50, 'line_amount' => 50, 'sst_rate' => 0, 'sst_amount' => 0, 'tax_type_code' => '06', 'tax_exemption_reason' => 'Zero-rated supply', 'tax_exemption_amount' => 50],
            ['line_number' => 3, 'description' => 'Exempt supply', 'quantity' => 1, 'unit_price' => 50, 'line_amount' => 50, 'sst_rate' => 0, 'sst_amount' => 0, 'tax_type_code' => 'E', 'tax_exemption_reason' => 'Statutory exemption', 'tax_exemption_amount' => 50],
        ];
        $payload['totals'] = ['net_amount' => 200, 'tax_amount' => 9, 'gross_amount' => 209];

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $document = $method->invoke($provider, $payload);
        $lines = $document['Invoice'][0]['InvoiceLine'];

        $this->assertSame('01', $lines[0]['TaxTotal'][0]['TaxSubtotal'][0]['TaxCategory'][0]['ID'][0]['_']);
        $this->assertSame('06', $lines[1]['TaxTotal'][0]['TaxSubtotal'][0]['TaxCategory'][0]['ID'][0]['_']);
        $this->assertSame('Zero-rated supply', $lines[1]['TaxTotal'][0]['TaxSubtotal'][0]['TaxExemptionReason'][0]['_']);
        $this->assertSame('E', $lines[2]['TaxTotal'][0]['TaxSubtotal'][0]['TaxCategory'][0]['ID'][0]['_']);
        $this->assertSame('Statutory exemption', $lines[2]['TaxTotal'][0]['TaxSubtotal'][0]['TaxExemptionReason'][0]['_']);
        $this->assertEqualsWithDelta(50.0, (float) $lines[2]['TaxTotal'][0]['TaxSubtotal'][0]['TaxExemptionAmount'][0]['_'], 0.001);

        $invoiceSubtotals = $document['Invoice'][0]['TaxTotal'][0]['TaxSubtotal'];
        $codes = array_map(fn ($st) => $st['TaxCategory'][0]['ID'][0]['_'], $invoiceSubtotals);
        sort($codes);
        $this->assertSame(['01', '06', 'E'], $codes, 'Invoice-level TaxTotal must carry one TaxSubtotal per category.');
    }

    public function test_submit_rejects_declared_business_buyer_without_tin(): void
    {
        $provider = $this->makeProviderWithResponses([]);

        $payload = $this->validPayload();
        unset($payload['buyer']['tin'], $payload['buyer']['company_registration']);
        // Issue #664: a declared business buyer is never auto-classified as a
        // consolidated general-public invoice, even without an explicit flag.
        $payload['buyer']['buyer_type'] = 'business';

        $result = $provider->submit($payload, [
            'api_key' => 'sandbox-key',
            'api_token' => 'sandbox-token',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('PAYLOAD_VALIDATION_FAILED', $result['error_code']);
        $this->assertStringContainsString('has no TIN', $result['message']);
        $this->assertStringContainsString('mark as individual', $result['message']);
    }
}
