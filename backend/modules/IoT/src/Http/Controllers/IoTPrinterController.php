<?php

namespace Modules\IoT\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\IoT\Models\IoTDevice;
use Modules\IoT\Services\DeviceManager;
use Modules\IoT\Enums\DeviceType;

class IoTPrinterController extends Controller
{
    public function __construct(
        protected DeviceManager $deviceManager
    ) {}

    /**
     * Print labels using a label printer.
     */
    public function printLabel(Request $request, $device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        // Validate that the device is a printer
        if ($device->type !== DeviceType::PRINTER) {
            return response()->json([
                'success' => false,
                'message' => 'Device is not a printer',
            ], 400);
        }

        // Validate request data
        $validated = $request->validate([
            'labels' => 'required|array|min:1|max:100',
            'labels.*.productName' => 'required|string|max:20',
            'labels.*.sku' => 'required|string|regex:/^[0-9]+$/',
            'labels.*.price' => 'required|string|regex:/^[A-Z]{2}\s\d+\.\d{2}$/',
            'labels.*.quantity' => 'required|integer|min:1|max:100',
        ]);

        try {
            $printer = $this->deviceManager->getPrinterDriver($device);
            
            $result = $printer->print($validated);

            return response()->json($result, $result['success'] ? 200 : 500);
        } catch (\Exception $e) {
            $device->logAction('print_label', 'failed', null, null, $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get printer status.
     */
    public function getStatus($device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        if ($device->type !== DeviceType::PRINTER) {
            return response()->json([
                'success' => false,
                'message' => 'Device is not a printer',
            ], 400);
        }

        try {
            $printer = $this->deviceManager->getPrinterDriver($device);
            $status = $printer->getStatus();

            return response()->json([
                'success' => true,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test printer connection.
     */
    public function testConnection($device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        if ($device->type !== DeviceType::PRINTER) {
            return response()->json([
                'success' => false,
                'message' => 'Device is not a printer',
            ], 400);
        }

        try {
            $printer = $this->deviceManager->getPrinterDriver($device);
            $connected = $printer->connect();

            if ($connected) {
                $device->markOnline();
                $device->logAction('test_connection', 'success');

                return response()->json([
                    'success' => true,
                    'message' => 'Printer connected successfully',
                ]);
            }

            $device->markOffline();
            $device->logAction('test_connection', 'failed');

            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to printer',
            ]);
        } catch (\Exception $e) {
            $device->markOffline();
            $device->logAction('test_connection', 'failed', null, null, $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}