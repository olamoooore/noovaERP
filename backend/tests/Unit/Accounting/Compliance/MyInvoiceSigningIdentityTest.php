<?php

namespace Tests\Unit\Accounting\Compliance;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceSubmissionProvider;
use Tests\TestCase;

/**
 * Issue #701 (EC4) — the signing identity and environment are the tenant's,
 * not the platform's:
 *  - a production tenant without its own certificate must never have its
 *    documents signed with the platform certificate (it is refused instead);
 *  - sandbox keeps the platform fallback for platform testing;
 *  - a sandbox tenant and a production tenant submit to different endpoints.
 *
 * This file boots the Laravel application (config() is part of the scenario);
 * the general provider behaviour suites stay container-free.
 */
class MyInvoiceSigningIdentityTest extends TestCase
{
    private function makeProvider(?Client $client = null): MyInvoiceSubmissionProvider
    {
        return new MyInvoiceSubmissionProvider($client ?? new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
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

    /**
     * @return array{0: string, 1: string} PEM private key and certificate
     */
    private function platformCertificate(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $privateKey);

        $csr = openssl_csr_new(['commonName' => 'Platform Test Certificate'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $certificatePem);

        return [$privateKey, $certificatePem];
    }

    public function test_production_signing_never_falls_back_to_the_platform_certificate(): void
    {
        $provider = $this->makeProvider();

        [$privateKey, $certificate] = $this->platformCertificate();
        config([
            'compliance.malaysia.signature_enabled' => true,
            'compliance.malaysia.signature_use_for_submission' => true,
            'compliance.malaysia.signature_private_key' => $privateKey,
            'compliance.malaysia.signature_certificate' => $certificate,
        ]);

        $result = $provider->signDocument($this->validPayload(), ['test_mode' => false]);

        $this->assertFalse($result['success'], 'the platform certificate must never sign a tenant production document');
        $this->assertStringContainsString('certificate', strtolower($result['message']));
    }

    public function test_production_signing_uses_the_tenants_own_certificate(): void
    {
        $provider = $this->makeProvider();

        [$privateKey, $certificate] = $this->platformCertificate();

        $result = $provider->signDocument($this->validPayload(), [
            'test_mode' => false,
            'signature_enabled' => true,
            'signature_use_for_submission' => true,
            'signature_private_key' => $privateKey,
            'signature_certificate' => $certificate,
            'signature_name' => 'Tenant Signer',
        ]);

        $this->assertTrue($result['success'], 'a tenant certificate must be accepted in production');
        $this->assertTrue($result['signature_metadata']['enabled'] ?? false);
    }

    public function test_sandbox_signing_keeps_the_platform_fallback(): void
    {
        $provider = $this->makeProvider();

        [$privateKey, $certificate] = $this->platformCertificate();
        config([
            'compliance.malaysia.signature_enabled' => true,
            'compliance.malaysia.signature_private_key' => $privateKey,
            'compliance.malaysia.signature_certificate' => $certificate,
        ]);

        $result = $provider->signDocument($this->validPayload(), ['test_mode' => true]);

        $this->assertTrue($result['success'], 'sandbox keeps the platform fallback for platform testing');
    }

    public function test_sandbox_and_production_tenants_submit_to_different_endpoints(): void
    {
        config(['compliance.malaysia.myinvoice_api_endpoint' => null]);

        $history = [];
        $mock = new MockHandler([
            new Response(201, [], json_encode(['submissionUID' => 'SUB-SANDBOX', 'response_code' => 'RECEIVED', 'status' => 'submitted'])),
            new Response(201, [], json_encode(['submissionUID' => 'SUB-PROD', 'response_code' => 'RECEIVED', 'status' => 'submitted'])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $provider = new MyInvoiceSubmissionProvider(new Client(['handler' => $stack]));

        $sandbox = $provider->submit($this->validPayload(), ['api_token' => 't', 'test_mode' => true]);
        $this->assertTrue($sandbox['success']);

        $productionPayload = $this->validPayload();
        $productionPayload['_signature_metadata'] = ['enabled' => true, 'signature_value' => 'sig'];
        $production = $provider->submit($productionPayload, ['api_token' => 't', 'test_mode' => false]);
        $this->assertTrue($production['success']);

        $this->assertStringStartsWith(
            'https://preprod-api.myinvois.hasil.gov.my',
            (string) $history[0]['request']->getUri(),
            'the sandbox tenant submits to the preprod endpoint'
        );
        $this->assertStringStartsWith(
            'https://api.myinvois.hasil.gov.my',
            (string) $history[1]['request']->getUri(),
            'the production tenant submits to the production endpoint'
        );
    }
}
