<?php

namespace Modules\PaymentGateway\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\PaymentGateway\Models\PaymentTransaction;

class BackfillWebhookTenantLookups extends Command
{
    protected $signature = 'payment-gateway:backfill-webhook-tenant-lookups {--tenant= : Limit the backfill to a single tenant ID}';

    protected $description = 'Populate the shared webhook tenant lookup table from existing tenant payment transactions';

    public function handle(): int
    {
        if (! Schema::hasTable(PaymentTransaction::webhookTenantLookupBaseTable())) {
            $this->error('Shared webhook lookup table not found. Run the latest database migrations first.');

            return self::FAILURE;
        }

        $tenantQuery = Tenant::query()->orderBy('id');
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenantQuery->whereKey($tenantId);
        }

        $tenants = $tenantQuery->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants found.');

            return self::SUCCESS;
        }

        $totalSynced = 0;

        foreach ($tenants as $tenant) {
            try {
                $tenantSynced = $tenant->run(function () use ($tenant): int {
                    if (! Schema::hasTable('payment_transactions') || ! Schema::hasTable('payment_gateways')) {
                        return 0;
                    }

                    $lookupTable = PaymentTransaction::webhookTenantLookupTable();
                    $tenantKey = (string) $tenant->getTenantKey();
                    $synced = 0;

                    PaymentTransaction::query()
                        ->select(['id', 'transaction_id', 'payment_gateway_id'])
                        ->with('gateway:id,gateway_name')
                        ->orderBy('id')
                        ->chunkById(200, function ($transactions) use ($lookupTable, $tenantKey, &$synced): void {
                            $timestamp = now();
                            $rows = [];

                            foreach ($transactions as $transaction) {
                                $gatewayName = $transaction->gateway?->gateway_name;

                                if (! $gatewayName || ! $transaction->transaction_id) {
                                    continue;
                                }

                                $rows[] = [
                                    'gateway_name' => $gatewayName,
                                    'transaction_id' => $transaction->transaction_id,
                                    'tenant_id' => $tenantKey,
                                    'created_at' => $timestamp,
                                    'updated_at' => $timestamp,
                                ];
                            }

                            if ($rows === []) {
                                return;
                            }

                            DB::table($lookupTable)->upsert(
                                $rows,
                                ['gateway_name', 'transaction_id'],
                                ['tenant_id', 'updated_at']
                            );

                            $synced += count($rows);
                        });

                    return $synced;
                });
            } catch (\Throwable $e) {
                $this->warn("Tenant {$tenant->id}: backfill skipped ({$e->getMessage()})");

                continue;
            }

            $totalSynced += $tenantSynced;
            $this->line("Tenant {$tenant->id}: synced {$tenantSynced} webhook lookup(s).");
        }

        $this->info("Backfilled {$totalSynced} shared webhook lookup(s).");

        return self::SUCCESS;
    }
}
