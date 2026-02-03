<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\IoT\Models\DeviceConfiguration;
use Modules\IoT\Models\ScanResult;
use Modules\IoT\Events\BarcodeScanned;
use Modules\IoT\Services\DeviceCommandRouter;
use Modules\IoT\Services\QRCodeParserService;
use Modules\IoT\Services\PalmVeinParserService;

class ScannerJobController extends Controller
{
    public function __construct(
        private DeviceCommandRouter $deviceRouter,
        private QRCodeParserService $qrParser,
        private PalmVeinParserService $palmVeinParser
    ) {}

    /**
     * Start scanner listening mode.
     * 
     * POST /api/v1/iot/agents/{agentId}/scanner/start-listening
     */
    public function startListening(Request $request, string $agentId): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|string',
            'mode' => 'in:single,continuous',
            'timeout' => 'integer|min:5|max:300',
        ]);

        $deviceId = $request->input('device_id');
        $mode = $request->input('mode', 'single');
        $timeout = $request->input('timeout', 30);

        // Verify device exists and is a scanner
        $device = DeviceConfiguration::where('id', $deviceId)
            ->where('agent_id', $agentId)
            ->where('device_type', 'scanner')
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Scanner device not found',
            ], 404);
        }

        if (!$device->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Scanner device is disabled',
            ], 400);
        }

        try {
            // Forward to device via router (protocol-agnostic)
            $result = $this->deviceRouter->sendCommand($device, '/scanner/start-listening', [
                'deviceId' => $deviceId,
                'mode' => $mode,
                'timeout' => $timeout,
            ]);

            if ($result['success'] ?? false) {
                // Update device status
                $device->update([
                    'current_status' => 'busy',
                    'status_message' => 'Listening for scan',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Scanner listening started',
                    'data' => [
                        'deviceId' => $deviceId,
                        'mode' => $mode,
                        'timeout' => $timeout,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to start scanner listening',
            ], 500);

        } catch (\Exception $e) {
            \Log::error('Failed to start scanner listening', [
                'agent_id' => $agentId,
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to communicate with agent',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Stop scanner listening mode.
     * 
     * POST /api/v1/iot/agents/{agentId}/scanner/stop-listening
     */
    public function stopListening(Request $request, string $agentId): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|string',
        ]);

        $deviceId = $request->input('device_id');

        // Verify device exists
        $device = DeviceConfiguration::where('id', $deviceId)
            ->where('agent_id', $agentId)
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Device not found',
            ], 404);
        }

        try {
            // Forward to device via router (protocol-agnostic)
            $result = $this->deviceRouter->sendCommand($device, '/scanner/stop-listening', [
                'deviceId' => $deviceId,
            ]);

            // Update device status regardless of agent response
            $device->update([
                'current_status' => 'ready',
                'status_message' => 'Scanner ready',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Scanner listening stopped',
            ]);

        } catch (\Exception $e) {
            // Still update local status
            $device->update([
                'current_status' => 'ready',
                'status_message' => 'Scanner ready',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Scanner listening stopped (agent communication failed)',
            ]);
        }
    }

    /**
     * Agent reports scan result.
     * 
     * POST /api/v1/iot/agents/{agentId}/scanner/scan-result
     */
    public function reportScanResult(Request $request, string $agentId): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|string',
            'barcode' => 'required|string|max:5000', // Increased for QR codes
            'format' => 'nullable|string',
            'timestamp' => 'nullable|string',
            'parsed' => 'nullable|array', // Agent may send pre-parsed data
        ]);

        $deviceId = $request->input('device_id');
        $barcode = $request->input('barcode');
        $format = $request->input('format');
        $timestamp = $request->input('timestamp');

        // Get device info
        $device = DeviceConfiguration::find($deviceId);
        $deviceName = $device?->name ?? 'Unknown Scanner';
        $isQrScanner = $device?->driver_type === 'qr-scanner-hid';
        $isPalmVeinScanner = $device?->driver_type === 'palm-vein-winusb';

        // Parse data based on device type
        $parsedData = $request->input('parsed');
        
        // Handle palm vein data
        if ($isPalmVeinScanner && !$parsedData) {
            $parsedData = $this->palmVeinParser->parse($barcode);
        }
        
        // Handle QR data
        $looksLikeQr = $this->looksLikeQrData($barcode);
        if (!$parsedData && ($isQrScanner || $looksLikeQr)) {
            $parsedData = $this->qrParser->parse($barcode);
        }

        // Detect format based on device type and content
        if ($isPalmVeinScanner) {
            $format = $this->palmVeinParser->detectFormat($barcode);
        } elseif ($looksLikeQr || $isQrScanner || ($parsedData && $parsedData['type'] !== 'text')) {
            $format = $this->qrParser->detectFormat($barcode);
        } elseif (!$format) {
            $format = $this->qrParser->detectFormat($barcode);
        }

        // Store scan result with parsed data in metadata
        $scanResult = ScanResult::create([
            'device_id' => $deviceId,
            'agent_id' => $agentId,
            'barcode' => $barcode,
            'format' => $format,
            'scanned_at' => $timestamp ? \Carbon\Carbon::parse($timestamp) : now(),
            'metadata' => $parsedData ? [
                'parsed' => $parsedData,
                'isQrCode' => $isQrScanner || $format === 'QR' || $looksLikeQr,
            ] : null,
        ]);

        // Broadcast to frontend with parsed data
        broadcast(new BarcodeScanned(
            deviceId: $deviceId,
            barcode: $barcode,
            format: $format,
            deviceName: $deviceName,
            scanResultId: $scanResult->id,
            parsedData: $parsedData,
        ))->toOthers();

        \Log::info('Scan result recorded', [
            'agent_id' => $agentId,
            'device_id' => $deviceId,
            'barcode' => substr($barcode, 0, 100), // Truncate for log
            'format' => $format,
            'parsed_type' => $parsedData['type'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Scan result recorded',
            'data' => [
                'id' => $scanResult->id,
                'barcode' => $barcode,
                'format' => $format,
                'parsed' => $parsedData,
            ],
        ]);
    }

    /**
     * Check if data looks like QR code content (JSON, URL, or long text).
     */
    private function looksLikeQrData(string $data): bool
    {
        $upper = strtoupper($data);
        
        // JSON
        if (str_starts_with($data, '{') || str_starts_with($data, '[')) {
            return true;
        }

        // URL (case-insensitive, handles HID scanner uppercase output)
        if (str_starts_with($upper, 'HTTP://') || str_starts_with($upper, 'HTTPS://') ||
            str_starts_with($upper, 'HTTP') || str_starts_with($upper, 'WWW')) {
            return true;
        }

        // NoovaERP format
        if (str_starts_with($upper, 'NOOVAERP:')) {
            return true;
        }

        // Long data (typical barcodes are < 48 chars)
        return strlen($data) > 48;
    }

    /**
     * Get scan history for a device.
     * 
     * GET /api/v1/iot/agents/{agentId}/scanner/history
     */
    public function getHistory(Request $request, string $agentId): JsonResponse
    {
        $deviceId = $request->input('device_id');
        $limit = $request->input('limit', 50);

        $query = ScanResult::where('agent_id', $agentId)
            ->orderBy('scanned_at', 'desc')
            ->limit($limit);

        if ($deviceId) {
            $query->where('device_id', $deviceId);
        }

        $results = $query->get()->map(function ($result) {
            return [
                'id' => $result->id,
                'deviceId' => $result->device_id,
                'agentId' => $result->agent_id,
                'barcode' => $result->barcode,
                'format' => $result->format,
                'scannedAt' => $result->scanned_at?->toIso8601String(),
                'metadata' => $result->metadata,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }
}
