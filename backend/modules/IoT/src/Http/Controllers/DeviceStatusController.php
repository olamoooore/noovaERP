<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\IoT\Models\DeviceConfiguration;
use Modules\IoT\Http\Requests\UpdateDeviceStatusRequest;
use Modules\IoT\Services\DeviceManagementService;

class DeviceStatusController extends Controller
{
    public function __construct(
        private DeviceManagementService $deviceService
    ) {}

    /**
     * Agent reports device status update.
     * 
     * POST /api/v1/iot/agents/{agentId}/device-status
     */
    public function update(UpdateDeviceStatusRequest $request, string $agentId): JsonResponse
    {
        $deviceId = $request->input('device_id');
        $status = $request->input('status');
        $message = $request->input('message');
        $errorCode = $request->input('error_code');

        $device = DeviceConfiguration::byAgent($agentId)->findOrFail($deviceId);

        $this->deviceService->updateDeviceStatus(
            $device,
            $status,
            $message,
            $errorCode
        );

        return response()->json([
            'success' => true,
            'message' => 'Device status updated successfully',
        ]);
    }
}
