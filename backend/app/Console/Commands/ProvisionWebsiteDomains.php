<?php

namespace App\Console\Commands;

use App\PublicSites\HostnameNormalizer;
use App\PublicSites\Provisioning\PrivilegedIngressClient;
use App\PublicSites\Provisioning\ProvisioningResultService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProvisionWebsiteDomains extends Command
{
    protected $signature = 'website-domains:provision {--once : Process available operations and exit} {--sleep=5 : Seconds between polls} {--limit=10 : Maximum operations per poll}';

    protected $description = 'Run the privileged custom-domain ingress provisioning worker';

    public function handle(PrivilegedIngressClient $ingress, ProvisioningResultService $results, HostnameNormalizer $normalizer): int
    {
        $this->recoverStaleClaims($ingress);
        do {
            $processed = 0;
            while ($processed < max(1, (int) $this->option('limit')) && ($operation = $this->claim())) {
                $processed++;
                $this->process($operation, $ingress, $results, $normalizer);
            }
            if ($this->option('once')) {
                break;
            }
            sleep(max(1, (int) $this->option('sleep')));
        } while (true);

        return self::SUCCESS;
    }

    private function claim(): ?object
    {
        return DB::transaction(function () {
            $operation = DB::table('public.hostname_provisioning_operations')->where('status', 'pending')->orderBy('created_at')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $operation) {
                return null;
            }
            DB::table('public.hostname_provisioning_operations')->where('id', $operation->id)->update(['status' => 'processing', 'claimed_at' => now(), 'updated_at' => now()]);
            $operation->status = 'processing';

            return $operation;
        });
    }

    private function process(object $operation, PrivilegedIngressClient $ingress, ProvisioningResultService $results, HostnameNormalizer $normalizer): void
    {
        $key = 'site-'.$operation->hostname_id;
        $begun = false;
        try {
            $hostname = $results->assertActionable($operation->id);
            $host = $normalizer->normalize($hostname->hostname);
            $certificate = 'noovaerp-site-'.$hostname->id;
            $ingress->begin($key);
            $begun = true;

            if ($operation->desired_mode === 'disabled') {
                $ingress->remove($key);
                $ingress->reload();
                $results->assertActionable($operation->id);
                $ingress->commit($key);
                $begun = false;
                $results->complete($operation->id, null, null);

                return;
            }
            if ($operation->desired_mode !== 'validation') {
                throw new \RuntimeException('Unsupported provisioning mode.');
            }

            $ingress->installBootstrap($key, $host);
            $ingress->reload();
            $ingress->issueCertificate($host, $certificate);
            $token = Str::random(48);
            $ingress->installValidation($key, $host, $certificate, $token);
            $ingress->reload();
            $ingress->healthCheck($host, $token);
            $ingress->installServing($key, $host, $certificate);
            $ingress->reload();
            $expiry = $ingress->certificateExpiry($certificate);
            $results->assertActionable($operation->id);
            $ingress->commit($key);
            $begun = false;
            $results->complete($operation->id, $certificate, $expiry);
        } catch (\Throwable $e) {
            if ($begun) {
                try {
                    $ingress->rollback($key);
                } catch (\Throwable $rollback) {
                    report($rollback);
                }
            }
            try {
                $results->fail($operation->id, $this->safeFailure($e));
            } catch (\Throwable $completion) {
                report($completion);
            }
            report($e);
            $this->error("Operation {$operation->id} failed: ".$this->safeFailure($e));
        }
    }

    private function recoverStaleClaims(PrivilegedIngressClient $ingress): void
    {
        $stale = DB::table('public.hostname_provisioning_operations')->where('status', 'processing')
            ->where('claimed_at', '<', now()->subMinutes(config('website_domain_worker.stale_after_minutes', 30)))->get();

        foreach ($stale as $operation) {
            try {
                $ingress->rollback('site-'.$operation->hostname_id);
                DB::table('public.hostname_provisioning_operations')->where('id', $operation->id)->where('status', 'processing')
                    ->update(['status' => 'pending', 'claimed_at' => null, 'updated_at' => now()]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function safeFailure(\Throwable $e): string
    {
        return mb_substr(preg_replace('/[\r\n]+/', ' ', $e->getMessage()) ?: 'Provisioning failed.', 0, 1000);
    }
}
