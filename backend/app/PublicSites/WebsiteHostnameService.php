<?php

namespace App\PublicSites;

use App\Models\Hostname;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\WebsiteBuilder\Models\Website;

final class WebsiteHostnameService
{
    public function __construct(private readonly HostnameNormalizer $normalizer) {}

    public function create(Website $website, string $input, bool $primary, ?int $actorId): Hostname
    {
        if ($website->status !== 'published') {
            throw ValidationException::withMessages(['hostname' => 'Publish the website before adding a custom domain.']);
        }

        $hostname = $this->normalizer->normalize($input);
        $tenantId = (string) tenant('id');
        $token = Str::random(48);

        try {
            return DB::transaction(function () use ($website, $hostname, $tenantId, $token, $primary, $actorId) {
                $existing = Hostname::query()->where('hostname', $hostname)->lockForUpdate()->first();

                if ($existing) {
                    if ((string) $existing->tenant_id !== $tenantId
                        || ! in_array($existing->serving_status, ['release_requested', 'quarantined', 'released'], true)) {
                        throw ValidationException::withMessages(['hostname' => 'This hostname is already claimed.']);
                    }

                    return $this->restoreReleasedHostname($existing, $website, $token, $primary, $actorId);
                }

                if ($primary) {
                    Hostname::query()->where('tenant_id', $tenantId)->where('website_uuid', $website->uuid)
                        ->where('primary_requested', true)->update(['primary_requested' => false]);
                }

                $record = Hostname::query()->create([
                    'hostname' => $hostname,
                    'tenant_id' => $tenantId,
                    'purpose' => 'website',
                    'website_uuid' => $website->uuid,
                    'primary_requested' => $primary,
                    'ownership_status' => 'pending',
                    'routing_status' => 'pending',
                    'tls_status' => 'pending',
                    'serving_status' => 'pending',
                    'challenge_token_ciphertext' => Crypt::encryptString($token),
                    'challenge_token_hash' => hash('sha256', $token),
                    'challenge_expires_at' => now()->addHours(config('website_domains.challenge_ttl_hours', 72)),
                    'ingress_revision' => (string) Str::uuid(),
                    'created_by' => $actorId,
                ]);

                $this->audit($record, 'hostname.created', null, $record->toArray(), $actorId);

                return $record;
            });
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['hostname' => 'This hostname is already claimed.']);
            }
            throw $e;
        }
    }

    public function dnsInstructions(Hostname $hostname): array
    {
        $token = Crypt::decryptString($hostname->challenge_token_ciphertext);
        $labels = explode('.', $hostname->hostname);
        $isApex = count($labels) === 2;
        $routing = $isApex
            ? array_merge(
                array_map(fn ($ip) => ['type' => 'A', 'name' => '@', 'value' => $ip], config('website_domains.apex_ipv4', [])),
                array_map(fn ($ip) => ['type' => 'AAAA', 'name' => '@', 'value' => $ip], config('website_domains.apex_ipv6', [])),
            )
            : [['type' => 'CNAME', 'name' => $labels[0], 'value' => config('website_domains.cname_target')]];

        $routing[] = [
            'type' => 'TXT',
            'name' => '_noovaerp-verification.'.$hostname->hostname,
            'value' => 'noovaerp-domain-verification='.$token,
        ];

        return $routing;
    }

    public function requestRelease(Hostname $hostname, ?int $actorId): void
    {
        DB::transaction(function () use ($hostname, $actorId) {
            $previous = $hostname->toArray();
            $hostname->update([
                'serving_status' => 'release_requested',
                'primary_effective' => false,
                'primary_requested' => false,
                'release_requested_at' => now(),
                'quarantined_until' => now()->addDays(config('website_domains.quarantine_days', 7)),
                'lock_version' => $hostname->lock_version + 1,
                'ingress_revision' => (string) Str::uuid(),
            ]);
            $this->queueProvisioning($hostname->fresh(), 'disabled');
            $this->audit($hostname, 'hostname.release_requested', $previous, $hostname->fresh()->toArray(), $actorId);
        });
    }

    private function restoreReleasedHostname(Hostname $hostname, Website $website, string $token, bool $primary, ?int $actorId): Hostname
    {
        if ($primary) {
            Hostname::query()
                ->where('tenant_id', $hostname->tenant_id)
                ->where('website_uuid', $website->uuid)
                ->where('id', '<>', $hostname->id)
                ->where('primary_requested', true)
                ->update(['primary_requested' => false]);
        }

        $previous = $hostname->toArray();
        $hostname->update([
            'purpose' => 'website',
            'website_uuid' => $website->uuid,
            'primary_requested' => $primary,
            'primary_effective' => false,
            'ownership_status' => 'pending',
            'routing_status' => 'pending',
            'tls_status' => 'pending',
            'serving_status' => 'pending',
            'challenge_version' => $hostname->challenge_version + 1,
            'challenge_token_ciphertext' => Crypt::encryptString($token),
            'challenge_token_hash' => hash('sha256', $token),
            'challenge_expires_at' => now()->addHours(config('website_domains.challenge_ttl_hours', 72)),
            'verified_at' => null,
            'last_ownership_check_at' => null,
            'last_routing_check_at' => null,
            'next_retry_at' => null,
            'retry_count' => 0,
            'certificate_provider' => null,
            'certificate_order_id' => null,
            'certificate_expires_at' => null,
            'failure_code' => null,
            'failure_message' => null,
            'disabled_at' => null,
            'release_requested_at' => null,
            'quarantined_until' => null,
            'released_at' => null,
            'lock_version' => $hostname->lock_version + 1,
            'ingress_revision' => (string) Str::uuid(),
            'created_by' => $actorId,
        ]);

        $restored = $hostname->fresh();
        $this->audit($restored, 'hostname.restored_by_owner', $previous, $restored->toArray(), $actorId);

        return $restored;
    }

    public function queueVerification(Hostname $hostname): void
    {
        \App\Jobs\VerifyWebsiteHostname::dispatch($hostname->id, $hostname->lock_version);
    }

    public function queueProvisioning(Hostname $hostname, string $mode): void
    {
        $id = (string) Str::uuid();
        $payload = implode('|', [$hostname->id, $hostname->hostname, $hostname->lock_version, $hostname->ingress_revision, $mode]);
        DB::table('public.hostname_provisioning_operations')->insert([
            'id' => $id,
            'hostname_id' => $hostname->id,
            'expected_lock_version' => $hostname->lock_version,
            'desired_ingress_revision' => $hostname->ingress_revision,
            'desired_mode' => $mode,
            'status' => 'pending',
            'payload_checksum' => hash_hmac('sha256', $payload, (string) config('app.key')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function audit(Hostname $hostname, string $type, ?array $previous, array $new, ?int $actorId): void
    {
        DB::table('public.hostname_audit_events')->insert([
            'hostname_id' => $hostname->id,
            'tenant_id' => $hostname->tenant_id,
            'website_uuid' => $hostname->website_uuid,
            'actor_id' => $actorId,
            'operation_id' => (string) Str::uuid(),
            'event_type' => $type,
            'previous_state' => $previous ? json_encode($previous) : null,
            'new_state' => json_encode($new),
            'created_at' => now(),
        ]);
    }
}
