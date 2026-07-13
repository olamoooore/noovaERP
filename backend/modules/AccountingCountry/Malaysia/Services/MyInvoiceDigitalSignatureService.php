<?php

namespace Modules\AccountingCountry\Malaysia\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class MyInvoiceDigitalSignatureService
{
    public function sign(array $document, array $context = []): array
    {
        $signatureEnabled = (bool) ($context['signature_enabled'] ?? false);
        if (!$signatureEnabled) {
            return [
                'success' => true,
                'signed_document' => $document,
                'signature_metadata' => [
                    'enabled' => false,
                    'mode' => 'disabled',
                ],
                'used_for_submission' => false,
            ];
        }

        $canonical = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            return [
                'success' => false,
                'message' => 'Unable to canonicalize MyInvois document before signing.',
            ];
        }

        $privateKeyPem = $this->resolveKeyValue((string) ($context['signature_private_key'] ?? ''));
        if ($privateKeyPem === '') {
            return [
                'success' => false,
                'message' => 'Digital signature is enabled but no private key is configured.',
            ];
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem, (string) ($context['signature_private_key_passphrase'] ?? ''));
        if ($privateKey === false) {
            return [
                'success' => false,
                'message' => 'Unable to read the configured signing private key. Check the key format or passphrase.',
            ];
        }

        $payloadHash = hash('sha256', $canonical);

        Log::info('myinvois:createDigitalSignature — entry', [
            'has_private_key' => $privateKeyPem !== '',
            'payload_hash' => $payloadHash,
        ]);

        $signature = '';
        $signed = openssl_sign($canonical, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$signed) {
            Log::error('myinvois:createDigitalSignature — OpenSSL signing failed', [
                'payload_hash' => $payloadHash,
                'error_message' => 'openssl_sign returned false',
            ]);
            return [
                'success' => false,
                'message' => 'OpenSSL failed to produce a signature for the MyInvois document.',
            ];
        }

        Log::info('myinvois:createDigitalSignature — Certificate signing success', [
            'payload_hash' => $payloadHash,
            'signature_length' => strlen($signature),
        ]);

        $certificatePem = $this->resolveKeyValue((string) ($context['signature_certificate'] ?? ''));
        $certificateInfo = $this->parseCertificate($certificatePem);

        // Warn operators before the signing certificate expires so they can renew
        // it before submissions start failing with certificate-related errors.
        if (!empty($certificateInfo['valid_to'])) {
            $expiresAt       = \Illuminate\Support\Carbon::parse((string) $certificateInfo['valid_to']);
            $daysUntilExpiry = (int) now()->diffInDays($expiresAt, false);
            if ($daysUntilExpiry < 0) {
                Log::error('myinvois:sign — signing certificate has EXPIRED', [
                    'expired_at'   => $certificateInfo['valid_to'],
                    'days_overdue' => abs($daysUntilExpiry),
                    'subject'      => $certificateInfo['subject'] ?? null,
                ]);
            } elseif ($daysUntilExpiry <= 30) {
                Log::warning('myinvois:sign — signing certificate expires soon', [
                    'expires_at'      => $certificateInfo['valid_to'],
                    'days_remaining'  => $daysUntilExpiry,
                    'subject'         => $certificateInfo['subject'] ?? null,
                ]);
            }
        }
        $signatureId = (string) ($context['signature_key_id'] ?? '');
        if ($signatureId === '') {
            $signatureId = 'urn:uuid:' . (string) \Illuminate\Support\Str::uuid();
        }

        $signatoryName = (string) ($context['signature_name'] ?? '');
        if ($signatoryName === '') {
            $signatoryName = (string) ($certificateInfo['subject'] ?? '');
        }

        $certificateValidFrom = $certificateInfo['valid_from'] ?? null;
        $certificateValidTo = $certificateInfo['valid_to'] ?? null;
        $signatureStatus = $this->resolveSignatureStatus($certificateInfo);

        $signatureMetadata = [
            'enabled' => true,
            'mode' => 'xades-detached',
            'status' => $signatureStatus,
            'signed_at' => now()->toIso8601String(),
            'signature_method' => 'RSA-SHA256',
            'digest_method' => 'SHA-256',
            'digest_value' => base64_encode(hash('sha256', $canonical, true)),
            'signature_value' => base64_encode($signature),
            'key_id' => $signatureId,
            'signatory_name' => $signatoryName,
            'certificate' => $certificateInfo,
        ];

        // SHA-256 of DER certificate bytes (base64) for XAdES CertDigest
        $certDerBytes = $certificatePem !== '' ? base64_decode(
            preg_replace('/-----[^-]+-----|[\r\n]/', '', $certificatePem) ?? '', true
        ) : false;
        $certDerBase64 = $certDerBytes !== false ? base64_encode($certDerBytes) : '';
        $certDigestValue = $certDerBytes !== false ? base64_encode(hash('sha256', $certDerBytes, true)) : '';

        // UBLDocumentSignatures wraps the W3C XMLDSIG/XAdES-BES Signature block
        $ublExtensions = [[
            'UBLExtension' => [[
                'ExtensionURI' => [['_' => 'urn:oasis:names:specification:ubl:dsig:enveloped:xades']],
                'ExtensionContent' => [[
                    'UBLDocumentSignatures' => [[
                        'SignatureInformation' => [[
                            'ID' => [['_' => 'urn:oasis:names:tc:DSIG:enveloped:xades']],
                            'ReferencedSignatureID' => [['_' => 'urn:oasis:names:tc:DSIG:enveloped:xades']],
                            'Signature' => [[
                                'Id' => $signatureId,
                                'SignedInfo' => [[
                                    'CanonicalizationMethod' => [['Algorithm' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315']],
                                    'SignatureMethod' => [['Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256']],
                                    'Reference' => [[
                                        'Id' => 'id-doc-signed-data',
                                        'URI' => '',
                                        'DigestMethod' => [['Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256']],
                                        'DigestValue' => [['_' => $signatureMetadata['digest_value']]],
                                    ]],
                                ]],
                                'SignatureValue' => [['_' => $signatureMetadata['signature_value']]],
                                'KeyInfo' => [[
                                    'X509Data' => [[
                                        'X509Certificate' => [['_' => $certDerBase64]],
                                        'X509IssuerSerial' => [[
                                            'X509IssuerName' => [['_' => (string) ($certificateInfo['issuer'] ?? '')]],
                                            'X509SerialNumber' => [['_' => (string) ($certificateInfo['serial_number'] ?? '')]],
                                        ]],
                                        'X509SubjectName' => [['_' => $signatoryName]],
                                    ]],
                                ]],
                                'Object' => [[
                                    'QualifyingProperties' => [[
                                        'Target' => '#' . $signatureId,
                                        'SignedProperties' => [[
                                            'Id' => 'xades-signed-properties',
                                            'SignedSignatureProperties' => [[
                                                'SigningTime' => [['_' => $signatureMetadata['signed_at']]],
                                                'SigningCertificate' => [[
                                                    'Cert' => [[
                                                        'CertDigest' => [[
                                                            'DigestMethod' => [['Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256']],
                                                            'DigestValue' => [['_' => $certDigestValue]],
                                                        ]],
                                                        'IssuerSerial' => [[
                                                            'X509IssuerName' => [['_' => (string) ($certificateInfo['issuer'] ?? '')]],
                                                            'X509SerialNumber' => [['_' => (string) ($certificateInfo['serial_number'] ?? '')]],
                                                        ]],
                                                    ]],
                                                ]],
                                            ]],
                                        ]],
                                    ]],
                                ]],
                            ]],
                        ]],
                    ]],
                ]],
            ]],
        ]];

        // UBL spec requires UBLExtensions to appear first in the Invoice element
        $signedDocument = $document;
        $invoice = $signedDocument['Invoice'][0] ?? [];
        unset($invoice['UBLExtensions'], $invoice['Signature']);
        $invoice = array_merge(['UBLExtensions' => $ublExtensions], $invoice);

        // Signature reference block — points to the XAdES signature in UBLExtensions
        $signatureRef = [
            'ID' => [['_' => $signatureId]],
            'Note' => [['_' => 'Issuer digital signature']],
            'SignatoryParty' => [[
                'PartyIdentification' => [[
                    'ID' => [[
                        '_' => (string) ($certificateInfo['serial_number'] ?? $signatureId),
                        'schemeID' => 'X509_SERIAL',
                    ]],
                ]],
                'PartyName' => [[
                    'Name' => [['_' => $signatoryName]],
                ]],
            ]],
            'DigitalSignatureAttachment' => [[
                'ExternalReference' => [[
                    'URI' => [['_' => '#' . $signatureId]],
                ]],
            ]],
        ];
        $invoice['Signature'] = [$signatureRef];
        $signedDocument['Invoice'][0] = $invoice;

        Log::info('myinvois:createDigitalSignature — Signed JSON generated', [
            'payload_hash' => $payloadHash,
            'signature_status' => $signatureStatus,
            'signatory_name' => $signatoryName,
            'key_id' => $signatureId,
            'has_ubl_extensions' => true,
            'used_for_submission' => (bool) ($context['signature_use_for_submission'] ?? false),
        ]);

        return [
            'success' => true,
            'signed_document' => $signedDocument,
            'signature_metadata' => $signatureMetadata,
            'used_for_submission' => (bool) ($context['signature_use_for_submission'] ?? false),
        ];
    }

    /**
     * Accept either raw PEM content or a file path (absolute or storage-relative).
     */
    private function resolveKeyValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Looks like a file path rather than PEM content
        if (!str_contains($value, '-----BEGIN')) {
            // /storage/... → resolve relative to Laravel base path
            if (str_starts_with($value, '/storage/')) {
                $resolved = base_path(ltrim($value, '/'));
            } elseif (str_starts_with($value, '/')) {
                $resolved = $value; // absolute filesystem path
            } else {
                $resolved = base_path($value); // relative to project root
            }
            if (file_exists($resolved) && is_readable($resolved)) {
                // Private key files must not be world-readable (should be 0400 or 0600).
                // Log a security warning but still proceed so signing is not silently broken.
                $perms = fileperms($resolved);
                if ($perms !== false && ($perms & 0004)) {
                    Log::warning('myinvois:signature — private key file is world-readable; restrict to 0600 or 0400', [
                        'path'        => $resolved,
                        'permissions' => decoct($perms & 0777),
                    ]);
                }
                return trim((string) file_get_contents($resolved));
            }
            Log::warning('myinvois:signature — key file not found or not readable', ['path' => $value, 'resolved' => $resolved]);
            return '';
        }

        return $value;
    }

    private function parseCertificate(string $certificatePem): array
    {
        if ($certificatePem === '') {
            return [];
        }

        $certificate = openssl_x509_read($certificatePem);
        if ($certificate === false) {
            return [];
        }

        $parsed = openssl_x509_parse($certificate) ?: [];

        return array_filter([
            'subject' => Arr::get($parsed, 'subject.CN'),
            'issuer' => Arr::get($parsed, 'issuer.CN'),
            'serial_number' => Arr::get($parsed, 'serialNumberHex'),
            'valid_from' => isset($parsed['validFrom_time_t']) ? date(DATE_ATOM, (int) $parsed['validFrom_time_t']) : null,
            'valid_to' => isset($parsed['validTo_time_t']) ? date(DATE_ATOM, (int) $parsed['validTo_time_t']) : null,
            'fingerprint_sha1' => openssl_x509_fingerprint($certificatePem, 'sha1') ?: null,
            'fingerprint_sha256' => openssl_x509_fingerprint($certificatePem, 'sha256') ?: null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function resolveSignatureStatus(array $certificateInfo): string
    {
        $validFrom = isset($certificateInfo['valid_from']) ? strtotime((string) $certificateInfo['valid_from']) : null;
        $validTo = isset($certificateInfo['valid_to']) ? strtotime((string) $certificateInfo['valid_to']) : null;
        $now = time();

        if ($validFrom !== null && $now < $validFrom) {
            return 'certificate_not_yet_valid';
        }

        if ($validTo !== null && $now > $validTo) {
            return 'certificate_expired';
        }

        return 'signed';
    }
}