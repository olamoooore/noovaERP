<?php

namespace App\Jobs;

use App\Models\Hostname;
use App\PublicSites\WebsiteHostnameService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class VerifyWebsiteHostname implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $hostnameId, public int $expectedLockVersion) {}

    public function backoff(): array
    {
        return [60, 300, 1800, 7200];
    }

    public function handle(WebsiteHostnameService $service): void
    {
        $hostname = Hostname::query()->find($this->hostnameId);
        if (! $hostname || $hostname->lock_version !== $this->expectedLockVersion || $hostname->serving_status !== 'pending') {
            return;
        }

        if (! $hostname->challenge_expires_at || $hostname->challenge_expires_at->isPast()) {
            $this->failRecord($hostname, 'challenge_expired', 'The DNS ownership challenge expired.');
            return;
        }

        $token = Crypt::decryptString($hostname->challenge_token_ciphertext);
        $expectedTxt = 'noovaerp-domain-verification='.$token;
        $txtRecords = dns_get_record('_noovaerp-verification.'.$hostname->hostname, DNS_TXT) ?: [];
        $txtVerified = collect($txtRecords)->contains(fn (array $record) => hash_equals($expectedTxt, (string) ($record['txt'] ?? '')));
        if (! $txtVerified) {
            $this->failRecord($hostname, 'ownership_txt_missing', 'The ownership TXT record was not found.');
            return;
        }

        $approved = array_values(array_filter(array_merge(
            config('website_domains.apex_ipv4', []),
            config('website_domains.apex_ipv6', []),
        )));
        $cname = rtrim((string) config('website_domains.cname_target'), '.');
        $records = dns_get_record($hostname->hostname, DNS_A | DNS_AAAA | DNS_CNAME) ?: [];
        $routingVerified = collect($records)->contains(function (array $record) use ($approved, $cname) {
            if (($record['type'] ?? null) === 'CNAME') {
                return hash_equals($cname, rtrim((string) ($record['target'] ?? ''), '.'));
            }
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            return is_string($ip) && in_array($ip, $approved, true) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        });

        if (! $routingVerified) {
            $this->failRecord($hostname, 'routing_target_mismatch', 'DNS does not point to an approved website ingress target.');
            return;
        }

        $hostname->update([
            'ownership_status' => 'verified',
            'routing_status' => 'ready',
            'tls_status' => 'issuing',
            'verified_at' => now(),
            'last_ownership_check_at' => now(),
            'last_routing_check_at' => now(),
            'failure_code' => null,
            'failure_message' => null,
            'retry_count' => 0,
            'ingress_revision' => (string) Str::uuid(),
            'lock_version' => $hostname->lock_version + 1,
        ]);
        $service->queueProvisioning($hostname->fresh(), 'validation');
    }

    private function failRecord(Hostname $hostname, string $code, string $message): void
    {
        $hostname->update([
            'failure_code' => $code,
            'failure_message' => $message,
            'retry_count' => $hostname->retry_count + 1,
            'next_retry_at' => now()->addMinutes(min(120, 2 ** min(6, $hostname->retry_count))),
            'last_ownership_check_at' => now(),
        ]);
    }
}
