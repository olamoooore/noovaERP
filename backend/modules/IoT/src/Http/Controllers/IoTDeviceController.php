<?php

namespace Modules\IoT\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\IoT\Models\IoTDevice;
use Modules\IoT\Services\DeviceManager;

class IoTDeviceController extends Controller
{
    public function __construct(
        protected DeviceManager $deviceManager
    ) {}

    /**
     * List all devices.
     */
    public function index(Request $request): JsonResponse
    {
        $query = IoTDevice::query();

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter active only
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $devices = $query->with(['bindings'])
            ->orderBy('name')
            ->get();

        return response()->json($devices);
    }

    /**
     * Create a new device.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:printer,terminal,scanner,kiosk',
            'vendor' => 'required|string|max:255',
            'connection_type' => 'required|in:cloud,lan,agent,bluetooth,usb',
            'config' => 'required|array',
            'is_active' => 'boolean',
        ]);

        $device = IoTDevice::create($validated);

        $device->logAction('device_created', 'success');

        return response()->json([
            'message' => 'Device created successfully',
            'device' => $device,
        ], 201);
    }

    /**
     * Show a specific device.
     */
    public function show($device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        $device->load(['bindings', 'logs' => function ($query) {
            $query->latest()->limit(50);
        }]);

        return response()->json($device);
    }

    /**
     * Update a device.
     */
    public function update(Request $request, $device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        $validated = $request->validate([
            'name' => 'string|max:255',
            'type' => 'in:printer,terminal,scanner,kiosk',
            'vendor' => 'string|max:255',
            'connection_type' => 'in:cloud,lan,agent,bluetooth,usb',
            'config' => 'array',
            'is_active' => 'boolean',
        ]);

        $device->update($validated);

        $device->logAction('device_updated', 'success');

        return response()->json([
            'message' => 'Device updated successfully',
            'device' => $device,
        ]);
    }

    /**
     * Delete a device.
     */
    public function destroy($device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        $device->logAction('device_deleted', 'success');
        $device->delete();

        return response()->json([
            'message' => 'Device deleted successfully',
        ]);
    }

    /**
     * Test device connection.
     */
    public function test($device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        $result = $this->deviceManager->testConnection($device);

        return response()->json($result, $result['success'] ? 200 : 500);
    }

    /**
     * Get device logs.
     */
    public function logs(Request $request, $device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        $limit = $request->input('limit', 100);

        $logs = $device->logs()
            ->latest()
            ->limit($limit)
            ->get();

        return response()->json($logs);
    }

    /**
     * Save device configuration as a template.
     */
    public function saveAsTemplate(Request $request, $device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        $validated = $request->validate([
            'template_name' => 'required|string|max:255',
            'category' => 'required|string|max:50',
            'description' => 'nullable|string',
            'is_verified' => 'boolean',
            'is_popular' => 'boolean',
        ]);

        // Create template from device configuration
        $template = \Modules\IoT\Models\IoTDeviceTemplate::create([
            'name' => $validated['template_name'],
            'category' => $validated['category'],
            'type' => $device->type,
            'vendor' => $device->vendor,
            'model' => $device->config['model'] ?? null,
            'connection_type' => $device->connection_type,
            'config' => $device->config,
            'description' => $validated['description'],
            'is_verified' => $validated['is_verified'] ?? false,
            'is_popular' => $validated['is_popular'] ?? false,
        ]);

        $device->logAction('template_created', 'success', ['template_id' => $template->id]);

        return response()->json([
            'message' => 'Template created successfully',
            'template' => $template,
        ], 201);
    }
}
