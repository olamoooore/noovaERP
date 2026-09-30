<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Exceptions\DomainOnboardingException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLAT-03 / A-26 — tenant domain onboarding (custom + platform
 * subdomains) with DNS ownership verification and SSL status tracking.
 *
 * All reads/writes target the CENTRAL schema explicitly (public.domains /
 * public.tenants): this runs both from SuperAdmin routes (public
 * search_path) and from authenticated tenant routes (tenant search_path),
 * so relying on the ambient search_path would silently write into the
 * wrong schema. Same convention as InitializeTenantForPublicAccess.
 *
 * Security invariant: a custom domain only becomes SERVABLE after its TXT
 * verification record matches (dns_status = verified) — see
 * InitializeTenantForPublicAccess::domainServable(). Until then a request
 * for that host fails closed with 404, so tenant B cannot claim tenant A's
 * (or anyone's) domain by inserting a row first.
 */
class DomainOnboardingService
{
    public const VERIFICATION_HOST_PREFIX = '_storefront-verify.';

    public function __construct(
        private readonly DnsResolver $dns,
        private readonly SslProbe $ssl,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(string $tenantId): Collection
    {
        $this->assertTenantExists($tenantId);

        return DB::table('public.domains')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => $this->shape($row))
            ->values();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DomainOnboardingException
     */
    public function add(string $tenantId, string $domain, string $type = 'custom'): array
    {
        $this->assertTenantExists($tenantId);

        if (! in_array($type, ['platform', 'custom'], true)) {
            throw new DomainOnboardingException('Type must be "platform" or "custom".', 'invalid_domain_type');
        }

        $normalized = $this->normalize($domain);

        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $normalized)) {
            throw new DomainOnboardingException(
                'Not a valid domain name (lowercase letters, digits, hyphens and dots only).',
                'invalid_domain'
            );
        }

        $central = config('tenancy.central_domains', ['localhost']);
        $underCentral = collect($central)->contains(
            fn (string $c) => $normalized === trim($c) || str_ends_with($normalized, '.'.trim($c))
        );

        if ($type === 'platform' && ! $underCentral) {
            throw new DomainOnboardingException(
                'A platform subdomain must end with one of the platform domains ('.implode(', ', $central).').',
                'not_platform_subdomain'
            );
        }

        if ($type === 'custom' && $underCentral) {
            throw new DomainOnboardingException(
                'That domain belongs to the platform zone and cannot be onboarded as a custom domain.',
                'platform_domain_forbidden'
            );
        }

        if (DB::table('public.domains')->where('domain', $normalized)->exists()) {
            throw new DomainOnboardingException('That domain is already registered.', 'domain_taken', 409);
        }

        $hasAny = DB::table('public.domains')->where('tenant_id', $tenantId)->exists();
        $token = bin2hex(random_bytes(16));
        $now = now();

        $id = DB::table('public.domains')->insertGetId([
            'tenant_id' => $tenantId,
            'domain' => $normalized,
            'type' => $type,
            'is_primary' => ! $hasAny,
            // Platform zone is controlled by us — nothing to verify. Custom
            // domains stay pending until the TXT record is proven.
            'dns_status' => $type === 'platform' ? 'verified' : 'pending',
            'ssl_status' => 'unknown',
            'verification_token' => $type === 'platform' ? null : $token,
            'verified_at' => $type === 'platform' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($tenantId, (int) $id);
    }

    /**
     * @throws DomainOnboardingException
     */
    public function remove(string $tenantId, int $domainId): void
    {
        $row = $this->findRow($tenantId, $domainId);

        if ($row->type === 'platform') {
            throw new DomainOnboardingException(
                'Platform subdomains are system-assigned and cannot be removed here.',
                'platform_domain_protected',
                403
            );
        }

        $wasPrimary = (bool) $row->is_primary;

        DB::table('public.domains')->where('id', $row->id)->delete();

        if ($wasPrimary) {
            $promote = DB::table('public.domains')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('type') // string DESC: 'platform' before 'custom' — keep the system subdomain primary
                ->orderBy('id')
                ->first();

            if ($promote) {
                DB::table('public.domains')->where('id', $promote->id)->update(['is_primary' => true]);
            }
        }
    }

    /**
     * Prove ownership: the tenant must publish the row's verification
     * token as a TXT record at _storefront-verify.<domain>.
     *
     * Returns the shaped row with dns_status verified/failed — a failed
     * verification is a business outcome, not an exception (the UI shows
     * the status and the operator retries).
     *
     * @throws DomainOnboardingException
     */
    public function verify(string $tenantId, int $domainId): array
    {
        $row = $this->findRow($tenantId, $domainId);

        if ($row->type === 'platform') {
            // Platform-controlled zone: already trusted at insert time.
            // Idempotent re-assert so the row never regresses.
            DB::table('public.domains')->where('id', $row->id)->update([
                'dns_status' => 'verified',
                'verified_at' => $row->verified_at ?? now(),
                'updated_at' => now(),
            ]);

            return $this->find($tenantId, (int) $row->id);
        }

        $matched = $this->tokenPresent($row);

        DB::table('public.domains')->where('id', $row->id)->update([
            'dns_status' => $matched ? 'verified' : 'failed',
            'verified_at' => $matched ? now() : $row->verified_at,
            'updated_at' => now(),
        ]);

        return $this->find($tenantId, (int) $row->id);
    }

    /**
     * Refresh DNS + SSL status. Localhost/panel hosts are never TLS-probed
     * (there is no certificate infrastructure there) — their ssl_status
     * stays 'unknown' rather than being falsely reported as failed.
     *
     * @throws DomainOnboardingException
     */
    public function check(string $tenantId, int $domainId): array
    {
        $row = $this->findRow($tenantId, $domainId);
        $updates = ['last_checked_at' => now(), 'updated_at' => now()];

        if ($row->type === 'custom') {
            // Re-run the ownership proof: a verified domain whose TXT
            // record was removed drops back to failed (honest status).
            $matched = $this->tokenPresent($row);
            $updates['dns_status'] = $matched ? 'verified' : 'failed';
            if ($matched && $row->verified_at === null) {
                $updates['verified_at'] = now();
            }
        }

        if ($this->isLocalHost($row->domain)) {
            $updates['ssl_status'] = 'unknown';
            $updates['cert_expires_at'] = null;
        } else {
            $probe = $this->ssl->probe($row->domain);
            $updates['ssl_status'] = $probe['ok'] ? 'active' : 'failed';
            $updates['cert_expires_at'] = $probe['ok'] ? $probe['expires_at'] : null;
        }

        DB::table('public.domains')->where('id', $row->id)->update($updates);

        return $this->find($tenantId, (int) $row->id);
    }

    // ─── internals ─────────────────────────────────────────────────────

    private function tokenPresent(object $row): bool
    {
        if ($row->verification_token === null) {
            return false;
        }

        $records = $this->dns->txtRecords(self::VERIFICATION_HOST_PREFIX.$row->domain);

        foreach ($records as $record) {
            if (hash_equals($row->verification_token, trim($record))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $domain): string
    {
        $value = strtolower(trim($domain));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value) ?? $value;
        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];
        $value = explode('?', $value)[0];

        return rtrim($value, '.');
    }

    private function isLocalHost(string $domain): bool
    {
        if (str_contains($domain, 'localhost') || str_ends_with($domain, '.local')) {
            return true;
        }

        return filter_var($domain, FILTER_VALIDATE_IP) !== false;
    }

    private function assertTenantExists(string $tenantId): void
    {
        if (! DB::table('public.tenants')->where('id', $tenantId)->exists()) {
            throw DomainOnboardingException::tenantNotFound();
        }
    }

    private function findRow(string $tenantId, int $domainId): object
    {
        $this->assertTenantExists($tenantId);

        $row = DB::table('public.domains')
            ->where('tenant_id', $tenantId)
            ->where('id', $domainId)
            ->first();

        if (! $row) {
            throw DomainOnboardingException::domainNotFound();
        }

        return $row;
    }

    private function find(string $tenantId, int $domainId): array
    {
        return $this->shape(DB::table('public.domains')
            ->where('tenant_id', $tenantId)
            ->where('id', $domainId)
            ->firstOrFail());
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'domain' => $row->domain,
            'type' => $row->type,
            'is_primary' => (bool) $row->is_primary,
            'dns_status' => $row->dns_status,
            'ssl_status' => $row->ssl_status,
            'verification_token' => $row->verification_token,
            'verification_record' => [
                'type' => 'TXT',
                'host' => self::VERIFICATION_HOST_PREFIX.$row->domain,
                'value' => $row->verification_token,
            ],
            'verified_at' => $row->verified_at,
            'cert_expires_at' => $row->cert_expires_at,
            'last_checked_at' => $row->last_checked_at,
            'created_at' => $row->created_at,
        ];
    }
}
