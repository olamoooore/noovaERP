<?php

namespace Modules\IoT\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\IoT\Models\IoTDevice;
use Modules\IoT\Services\HealthCheckService;

class CheckDeviceHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes timeout
    public $tries = 3;

    protected $deviceId;

    /**
     * Create a new job instance.
     */
    public function __construct(?int $deviceId = null)
    {
        $this->deviceId = $deviceId;
    }

    /**
     * Execute the job.
     */
    public function handle(HealthCheckService $healthCheckService): void
    {
        try {
            if ($this->deviceId) {
                // Check specific device
                $device = IoTDevice::find($this->deviceId);
                if ($device && $device->is_active) {
                    $result = $healthCheckService->checkDevice($device);
                    Log::info("Health check completed for device {$device->id}: {$result->status}");
                }
            } else {
                // Check all devices
                $results = $healthCheckService->checkAllDevices();
                Log::info("Health check completed for " . count($results) . " devices");
            }
        } catch (\Exception $e) {
            Log::error("Health check job failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Health check job failed permanently: " . $exception->getMessage());
    }
}