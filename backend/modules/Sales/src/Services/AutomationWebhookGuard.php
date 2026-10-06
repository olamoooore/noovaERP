<?php

namespace Modules\Sales\Services;

use RuntimeException;

/**
 * AutomationWebhookGuard — SSRF guard for the `webhook` automation action
 * (issue #913).
 *
 * A tenant-configured URL is user input that the server will fetch. Without a
 * guard that is an SSRF hole into the internal network (cloud metadata
 * endpoints, admin panels, localhost services). The check:
 *
 *   1. only http/https URLs are accepted;
 *   2. the host is resolved (A + AAAA) and every resolved address must be a
 *      public, globally-routable IP. Localhost, private (RFC 1918), link-local
 *      (169.254.0.0/16, fe80::/10), carrier-grade NAT (100.64.0.0/10), unique
 *      local (fc00::/7), loopback and reserved ranges are rejected;
 *   3. the validated address is returned so the caller can pin it for the
 *      request (CURLOPT_RESOLVE). This closes the DNS-rebinding gap between
 *      the check and the actual connect as far as the HTTP client allows.
 *
 * Hostnames that do not resolve are rejected (fail closed).
 */
class AutomationWebhookGuard
{
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata.google.internal',
        'metadata.google',
        'instance-data',
    ];

    /**
     * @return array{host: string, port: int, ip: string, url: string}
     *
     * @throws RuntimeException when the URL is not a safe public endpoint
     */
    public function resolve(string $url): array
    {
        $parts = parse_url(trim($url));

        if ($parts === false || empty($parts['host'])) {
            throw new RuntimeException('Webhook URL is not a valid absolute URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Webhook URL must use http or https.');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '' || in_array($host, self::BLOCKED_HOSTNAMES, true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new RuntimeException('Webhook URL points at a blocked internal host.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $addresses = $this->resolveAddresses($host);

        if ($addresses === []) {
            throw new RuntimeException("Webhook host \"{$host}\" did not resolve to any address.");
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new RuntimeException(
                    "Webhook host \"{$host}\" resolves to non-public address {$address}; internal webhook destinations are blocked."
                );
            }
        }

        return [
            'host' => $host,
            'port' => $port,
            'ip' => $addresses[0],
            'url' => $url,
        ];
    }

    /**
     * @return string[]
     */
    private function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];

        // IPv4 A records.
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = array_merge($addresses, $v4);
        }

        // IPv6 AAAA records (gethostbynamel is IPv4-only).
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (! empty($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $address): bool
    {
        // IPv4-mapped IPv6 (::ffff:127.0.0.1) must be judged as its IPv4 form.
        if (stripos($address, '::ffff:') === 0) {
            $address = substr($address, 7);
        }

        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }

        $isV6 = (bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        if ($isV6) {
            $normalized = strtolower($address);

            if ($normalized === '::' || $normalized === '::1') {
                return false;
            }

            // fc00::/7 (unique local), fe80::/10 (link-local), ff00::/8 (multicast).
            $first = hexdec(substr($normalized, 0, 2));
            if (($first & 0xFE) === 0xFC) {
                return false;
            }
            if (($first & 0xFF) === 0xFF) {
                return false;
            }
            $second = hexdec(substr($normalized, 2, 2));
            if (($first & 0xFF) === 0xFE && ($second & 0xC0) === 0x80) {
                return false;
            }

            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // filter_var covers RFC1918, loopback, link-local, 0.0.0.0/8 and
        // reserved ranges. CGNAT (100.64.0.0/10) and benchmarking
        // (198.18.0.0/15) are not in PHP's reserved list, so check them too.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $long = ip2long($address);

        if ($long === false) {
            return false;
        }

        foreach ([['100.64.0.0', 10], ['198.18.0.0', 15], ['192.0.0.0', 24], ['192.0.2.0', 24]] as [$network, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
