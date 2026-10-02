<?php

namespace App\PublicSites;

use InvalidArgumentException;

final class HostnameNormalizer
{
    public function normalize(string $input): string
    {
        $host = $this->canonicalize($input);

        foreach (config('website_domains.reserved_suffixes', []) as $suffix) {
            if ($this->isHostOrSubdomainOf($host, $this->canonicalizeConfigurationHost((string) $suffix))) {
                throw new InvalidArgumentException('This hostname is reserved and cannot be claimed.');
            }
        }

        $protectedHosts = array_merge(
            config('website_domains.central_hosts', []),
            [config('website_domains.cname_target')],
        );
        foreach ($protectedHosts as $protectedHost) {
            $protectedHost = $this->canonicalizeConfigurationHost((string) $protectedHost);
            if ($protectedHost !== '' && $this->isHostOrSubdomainOf($host, $protectedHost)) {
                throw new InvalidArgumentException('This hostname belongs to the platform and cannot be claimed.');
            }
        }

        return $host;
    }

    private function canonicalize(string $input): string
    {
        $host = strtolower(rtrim(trim($input), '.'));
        if ($host === '' || str_contains($host, '://') || str_contains($host, '/') || str_contains($host, '@') || str_contains($host, ':') || str_contains($host, '*')) {
            throw new InvalidArgumentException('Enter a hostname only, without scheme, port, path, credentials, or wildcard.');
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new InvalidArgumentException('The internationalized hostname is invalid.');
            }
            $host = strtolower($ascii);
        }

        if (strlen($host) > 253 || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Enter a valid public fully-qualified hostname.');
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                throw new InvalidArgumentException('The hostname contains an invalid label.');
            }
        }

        return $host;
    }

    private function canonicalizeConfigurationHost(string $host): string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B."));
        if ($host !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }

    private function isHostOrSubdomainOf(string $host, string $suffix): bool
    {
        return $host === $suffix || ($suffix !== '' && str_ends_with($host, '.'.$suffix));
    }
}
