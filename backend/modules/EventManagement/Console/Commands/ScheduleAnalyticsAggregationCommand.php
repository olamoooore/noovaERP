<?php

namespace Modules\EventManagement\Console\Commands;

use Modules\EventManagement\Jobs\AnalyticsAggregationJob;
use Modules\EventManagement\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ScheduleAnalyticsAggregationCommand
 * 
 * Console command to schedule analytics aggregation jobs for all tenants.
 * Handles both daily and hourly aggregation with proper error handling
 * and monitoring capabilities.
 * 
 * Requirements: 11.1, 11.5
 */
class ScheduleAnalyticsAggregationCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'event-management:aggregate-analytics 
                            {--type=daily : Type of aggregation (daily|hourly)}
                            {--date= : Specific date to process (YYYY-MM-DD)}
                            {--hour= : Specific hour to process (0-23, for hourly aggregation)}
                            {--tenant= : Specific tenant ID to process}
                            {--force : Force processing even if already processed}';

    /**
     * The console command description.
     */
    protected $description = 'Schedule analytics aggregation jobs for event performance data';

    private AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        parent::__construct();
        $this->auditService = $auditService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : null;
        $hour = $this->option('hour');
        $tenantId = $this->option('tenant');
        $force = $this->option('force');

        $this->info("Starting analytics aggregation: {$type}");

        try {
            if ($tenantId) {
                // Process specific tenant
                $this->processTenant($tenantId, $type, $date, $hour, $force);
            } else {
                // Process all tenants
                $this->processAllTenants($type, $date, $hour, $force);
            }

            $this->info('Analytics aggregation completed successfully');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Analytics aggregation failed: {$e->getMessage()}");
            
            // Log the error
            $this->auditService->logActivity(
                'analytics_aggregation_error',
                'Analytics aggregation command failed',
                [
                    'type' => $type,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );

            return Command::FAILURE;
        }
    }

    /**
     * Process analytics aggregation for all tenants
     */
    private function processAllTenants(string $type, ?Carbon $date, ?int $hour, bool $force): void
    {
        // Get all active tenants
        $tenants = DB::connection('central')
            ->table('tenants')
            ->where('status', 'active')
            ->pluck('id');

        $this->info("Processing {$tenants->count()} tenants");

        $progressBar = $this->output->createProgressBar($tenants->count());
        $progressBar->start();

        foreach ($tenants as $tenantId) {
            try {
                $this->processTenant($tenantId, $type, $date, $hour, $force);
                $progressBar->advance();
            } catch (\Exception $e) {
                $this->error("\nFailed to process tenant {$tenantId}: {$e->getMessage()}");
                continue;
            }
        }

        $progressBar->finish();
        $this->newLine();
    }

    /**
     * Process analytics aggregation for a specific tenant
     */
    private function processTenant(string $tenantId, string $type, ?Carbon $date, ?int $hour, bool $force): void
    {
        // Validate tenant exists and is active
        $tenant = DB::connection('central')
            ->table('tenants')
            ->where('id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (!$tenant) {
            throw new \Exception("Tenant {$tenantId} not found or inactive");
        }

        if ($type === 'hourly') {
            $this->processHourlyAggregation($tenantId, $date, $hour, $force);
        } else {
            $this->processDailyAggregation($tenantId, $date, $force);
        }
    }

    /**
     * Process hourly aggregation for a tenant
     */
    private function processHourlyAggregation(string $tenantId, ?Carbon $date, ?int $hour, bool $force): void
    {
        $targetDate = $date ?? now()->subHour();
        
        if ($hour !== null) {
            // Process specific hour
            $targetHour = $hour;
            
            if (!$force && $this->isAlreadyProcessed($tenantId, $targetDate, $targetHour)) {
                $this->warn("Hourly aggregation for tenant {$tenantId} on {$targetDate->toDateString()} hour {$targetHour} already processed");
                return;
            }

            AnalyticsAggregationJob::dispatch($tenantId, 'hourly', $targetDate, $targetHour)
                ->onQueue('analytics');

            $this->info("Scheduled hourly aggregation for tenant {$tenantId} - {$targetDate->toDateString()} hour {$targetHour}");
        } else {
            // Process all hours for the date
            for ($h = 0; $h < 24; $h++) {
                if (!$force && $this->isAlreadyProcessed($tenantId, $targetDate, $h)) {
                    continue;
                }

                AnalyticsAggregationJob::dispatch($tenantId, 'hourly', $targetDate, $h)
                    ->onQueue('analytics');
            }

            $this->info("Scheduled hourly aggregation for tenant {$tenantId} - {$targetDate->toDateString()} (all hours)");
        }
    }

    /**
     * Process daily aggregation for a tenant
     */
    private function processDailyAggregation(string $tenantId, ?Carbon $date, bool $force): void
    {
        $targetDate = $date ?? now()->subDay();

        if (!$force && $this->isAlreadyProcessed($tenantId, $targetDate)) {
            $this->warn("Daily aggregation for tenant {$tenantId} on {$targetDate->toDateString()} already processed");
            return;
        }

        AnalyticsAggregationJob::dispatch($tenantId, 'daily', $targetDate)
            ->onQueue('analytics');

        $this->info("Scheduled daily aggregation for tenant {$tenantId} - {$targetDate->toDateString()}");
    }

    /**
     * Check if aggregation has already been processed
     */
    private function isAlreadyProcessed(string $tenantId, Carbon $date, ?int $hour = null): bool
    {
        // Set tenant database connection
        config(['database.connections.tenant.database' => "tenant_{$tenantId}"]);
        
        $query = DB::connection('tenant')
            ->table('daily_sales_metrics')
            ->where('tenant_id', $tenantId)
            ->where('metric_date', $date->toDateString());

        if ($hour !== null) {
            $query->where('metric_hour', $hour);
        } else {
            $query->whereNull('metric_hour');
        }

        return $query->exists();
    }

    /**
     * Get aggregation status for monitoring
     */
    private function getAggregationStatus(): array
    {
        $tenants = DB::connection('central')
            ->table('tenants')
            ->where('status', 'active')
            ->pluck('id');

        $status = [];
        $yesterday = now()->subDay()->toDateString();

        foreach ($tenants as $tenantId) {
            try {
                config(['database.connections.tenant.database' => "tenant_{$tenantId}"]);
                
                $dailyProcessed = DB::connection('tenant')
                    ->table('daily_sales_metrics')
                    ->where('tenant_id', $tenantId)
                    ->where('metric_date', $yesterday)
                    ->whereNull('metric_hour')
                    ->exists();

                $hourlyProcessed = DB::connection('tenant')
                    ->table('daily_sales_metrics')
                    ->where('tenant_id', $tenantId)
                    ->where('metric_date', $yesterday)
                    ->whereNotNull('metric_hour')
                    ->count();

                $status[$tenantId] = [
                    'daily_processed' => $dailyProcessed,
                    'hourly_processed' => $hourlyProcessed,
                    'expected_hourly' => 24
                ];

            } catch (\Exception $e) {
                $status[$tenantId] = [
                    'error' => $e->getMessage()
                ];
            }
        }

        return $status;
    }
}