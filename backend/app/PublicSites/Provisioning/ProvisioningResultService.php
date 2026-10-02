<?php

namespace App\PublicSites\Provisioning;

use App\Models\Hostname;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProvisioningResultService
{
    public function complete(string $operationId, ?string $order, ?string $expires): void
    {
        DB::transaction(function () use ($operationId, $order, $expires) {
            [$operation, $hostname] = $this->lockedAndValidated($operationId);
            $before = $hostname->toArray();

            if ($operation->desired_mode === 'validation') {
                if (! $expires) {
                    throw new \RuntimeException('Certificate expiry is required.');
                }
                if ($hostname->ownership_status !== 'verified' || $hostname->routing_status !== 'ready') {
                    throw new \RuntimeException('Hostname ownership and routing are not verified.');
                }
                if ($hostname->primary_requested) {
                    Hostname::query()->where('tenant_id', $hostname->tenant_id)->where('website_uuid', $hostname->website_uuid)
                        ->whereKeyNot($hostname->id)->update(['primary_effective' => false]);
                }
                $hostname->update([
                    'tls_status' => 'ready', 'serving_status' => 'active',
                    'primary_effective' => $hostname->primary_requested,
                    'certificate_provider' => 'letsencrypt', 'certificate_order_id' => $order,
                    'certificate_expires_at' => Carbon::parse($expires), 'failure_code' => null, 'failure_message' => null,
                ]);
            } elseif ($operation->desired_mode === 'disabled') {
                $hostname->update(['serving_status' => 'quarantined', 'tls_status' => 'disabled', 'primary_effective' => false]);
            } else {
                throw new \RuntimeException('Unsupported desired provisioning mode.');
            }

            DB::table('public.hostname_provisioning_operations')->where('id', $operationId)->update([
                'status' => 'completed', 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($hostname, $operationId, 'hostname.provisioning_completed', $before);
        });
    }

    public function fail(string $operationId, string $message): void
    {
        DB::transaction(function () use ($operationId, $message) {
            $operation = DB::table('public.hostname_provisioning_operations')->where('id', $operationId)->lockForUpdate()->first();
            if (! $operation || ! in_array($operation->status, ['pending', 'processing'], true)) {
                return;
            }
            $message = mb_substr($message, 0, 1000);
            DB::table('public.hostname_provisioning_operations')->where('id', $operation->id)->update([
                'status' => 'failed', 'failure_message' => $message, 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $hostname = Hostname::query()->lockForUpdate()->find($operation->hostname_id);
            if ($hostname && $this->hasValidPayload($operation, $hostname)) {
                $before = $hostname->toArray();
                $hostname->update(['tls_status' => 'failed', 'failure_code' => 'provisioning_failed', 'failure_message' => $message]);
                $this->audit($hostname, $operationId, 'hostname.provisioning_failed', $before);
            }
        });
    }

    public function assertActionable(string $operationId): Hostname
    {
        return DB::transaction(fn () => $this->lockedAndValidated($operationId)[1]);
    }

    private function lockedAndValidated(string $id): array
    {
        $operation = DB::table('public.hostname_provisioning_operations')->where('id', $id)->lockForUpdate()->first();
        if (! $operation || ! in_array($operation->status, ['pending', 'processing'], true)) {
            throw new \RuntimeException('Provisioning operation is missing or no longer actionable.');
        }
        $hostname = Hostname::query()->lockForUpdate()->findOrFail($operation->hostname_id);
        if (! $this->hasValidPayload($operation, $hostname)) {
            throw new \RuntimeException('Provisioning operation is stale or failed integrity validation.');
        }

        return [$operation, $hostname];
    }

    private function hasValidPayload(object $operation, Hostname $hostname): bool
    {
        $payload = implode('|', [$hostname->id, $hostname->hostname, $operation->expected_lock_version, $operation->desired_ingress_revision, $operation->desired_mode]);

        return hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $operation->payload_checksum)
            && $hostname->lock_version === $operation->expected_lock_version
            && $hostname->ingress_revision === $operation->desired_ingress_revision;
    }

    private function audit(Hostname $hostname, string $operationId, string $event, array $before): void
    {
        DB::table('public.hostname_audit_events')->insert([
            'hostname_id' => $hostname->id, 'tenant_id' => $hostname->tenant_id,
            'website_uuid' => $hostname->website_uuid, 'actor_id' => null,
            'operation_id' => $operationId ?: (string) Str::uuid(), 'event_type' => $event,
            'previous_state' => json_encode($before), 'new_state' => json_encode($hostname->fresh()->toArray()),
            'created_at' => now(),
        ]);
    }
}
