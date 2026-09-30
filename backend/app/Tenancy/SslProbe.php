<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * HTTPS certificate probe for custom shop domains (FR-PLAT-03 DNS/SSL
 * status). Opens a TLS connection with peer verification ON — a self-signed
 * or hostname-mismatched certificate counts as failed, which is exactly
 * what a shopper's browser would reject.
 *
 * Seam'd for tests (bind a fake SslProbe) and for environments where
 * outbound TLS is blocked: probe() never throws, it reports ok=false with
 * the transport error.
 */
class SslProbe
{
    /**
     * @return array{ok: bool, expires_at: ?string, error: ?string}
     */
    public function probe(string $host): array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'SNI_server_name' => $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'ssl://'.$host.':443',
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! $client) {
            return [
                'ok' => false,
                'expires_at' => null,
                'error' => $errstr !== '' ? $errstr : "connection failed ({$errno})",
            ];
        }

        try {
            $meta = stream_get_meta_data($client);
            $certificate = $meta['peer_certificate'] ?? null;

            if ($certificate === null) {
                return ['ok' => false, 'expires_at' => null, 'error' => 'no peer certificate'];
            }

            $parsed = openssl_x509_parse($certificate);
            $validTo = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

            return [
                'ok' => true,
                'expires_at' => $validTo !== null ? date('c', (int) $validTo) : null,
                'error' => null,
            ];
        } finally {
            fclose($client);
        }
    }
}
