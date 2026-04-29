<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\IoT\Models\DeviceConfiguration;
use Modules\IoT\Models\ScanResult;
use Modules\IoT\Events\BarcodeScanned;
use Modules\IoT\Services\DeviceCommandRouter;
use Modules\IoT\Services\QRCodeParserService;
use Modules\IoT\Services\PalmVeinParserService;
use Modules\IoT\Services\PalmVeinAttendanceService;

class ScannerJobController extends Controller
{
    public function __construct(
        private DeviceCommandRouter $deviceRouter,
        private QRCodeParserService $qrParser,
        private PalmVeinParserService $palmVeinParser,
        private PalmVeinAttendanceService $palmVeinAttendance
    ) {}

    /**
     * Resolve and initialize the tenant from the request Host header.
     * Used for agent requests that bypass Sanctum authentication (no user context).
     * Safe to call when tenancy is already initialized — it skips in that case.
     */
    private function ensureTenantContext(Request $request): void
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            return;  // already initialized (user-authenticated request)
        }

        $host = $request->getHost();
        if (!$host) {
            return;
        }

        try {
            // Always query domains from the central (public) schema.
            // Temporarily set search_path to public to avoid tenant schema pollution.
            DB::statement("SET search_path TO public");
            $domain = DB::table('domains')->where('domain', $host)->first();

            if ($domain) {
                $tenant = \App\Models\Tenant::find($domain->tenant_id);
                if ($tenant) {
                    Log::info('ScannerJobController: Initializing tenant from Host header', [
                        'host'      => $host,
                        'tenant_id' => $tenant->id,
                    ]);
                    tenancy()->initialize($tenant);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ScannerJobController: Tenant resolution from Host header failed', [
                'host'  => $host,
                'error' => $e->getMessage(),
            ]);
        }
    }

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
        // Resolve tenant from Host header so all subsequent DB queries go to the
        // correct tenant schema (important for HRM attendance event creation).
        $this->ensureTenantContext($request);

        $request->validate([
            'device_id' => 'nullable|string',
            'barcode' => 'required|string|max:5000', // Increased for QR codes
            'format' => 'nullable|string',
            'timestamp' => 'nullable|string',
            'parsed' => 'nullable|array', // Agent may send pre-parsed data
        ]);

        // Guard: device_id column in scan_results is UUID type; non-UUID strings
        // (e.g. UMP device identifiers like "palm-vein-1") must be treated as null
        // to avoid a PostgreSQL type error.
        $rawDeviceId = $request->input('device_id') ?? $request->input('deviceId');
        $deviceId = $this->safeUuid($rawDeviceId);

        $barcode = $request->input('barcode');
        $format = $request->input('format');
        $timestamp = $request->input('timestamp');

        // Get device info
        $device = DeviceConfiguration::find($deviceId);
        $deviceName = $device?->name ?? 'Unknown Scanner';
        $isQrScanner = $device?->driver_type === 'qr-scanner-hid';
        // Detect palm vein either by device type or by the format sent by the agent
        $isPalmVeinScanner = $device?->driver_type === 'palm-vein-winusb'
            || ($format !== null && str_starts_with(strtoupper($format), 'PALM_VEIN'))
            || ($format !== null && strtoupper($format) === 'PALM');

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

        // Broadcast to frontend with parsed data.
        // Use the raw device ID string here (not the UUID-coerced $deviceId which may be null).
        broadcast(new BarcodeScanned(
            deviceId: $rawDeviceId ?? '',
            barcode: $barcode,
            format: $format,
            deviceName: $deviceName,
            scanResultId: $scanResult->id,
            parsedData: $parsedData,
        ))->toOthers();

        Log::info('Scan result recorded', [
            'agent_id' => $agentId,
            'device_id' => $deviceId,
            'barcode' => substr($barcode, 0, 100), // Truncate for log
            'format' => $format,
            'parsed_type' => $parsedData['type'] ?? null,
        ]);

        // ── Palm vein → HRM attendance ────────────────────────────────────────
        // Extract subject_code: use explicitly-parsed subjectCode, or fall back
        // to the raw barcode value (the palm scanner's userId is the subject_code).
        $attendanceResult = null;
        if ($isPalmVeinScanner) {
            $subjectCode = $parsedData['data']['subjectCode']
                ?? $parsedData['subjectCode']
                ?? $barcode;   // barcode == userId from the Python palm API

            $attendanceResult = $this->palmVeinAttendance->processScan(
                subjectCode: (string) $subjectCode,
                deviceId:    $deviceId,
                agentId:     $agentId,
                capturedAt:  $timestamp,
            );

            if ($attendanceResult['success']) {
                Log::info('Palm vein attendance recorded via scan-result', [
                    'subject_code' => $subjectCode,
                    'event_type'   => $attendanceResult['event_type'] ?? null,
                    'employee_id'  => $attendanceResult['event']?->employee_id ?? null,
                ]);
            } else {
                Log::warning('Palm vein attendance failed', [
                    'subject_code' => $subjectCode,
                    'message'      => $attendanceResult['message'],
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Scan result recorded',
            'data' => [
                'id'         => $scanResult->id,
                'barcode'    => $barcode,
                'format'     => $format,
                'parsed'     => $parsedData,
                'attendance' => $attendanceResult,
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
     * Return the string only if it is a valid UUID; otherwise null.
     * Protects the UUID-typed device_id column in scan_results from bad input.
     */
    private function safeUuid(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) ? $value : null;
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
