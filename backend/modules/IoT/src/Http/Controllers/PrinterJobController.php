<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\IoT\Models\DeviceConfiguration;
use Modules\IoT\Models\PrintJob;
use Modules\IoT\Services\DeviceCommandRouter;
use Modules\IoT\Services\LabelGeneratorService;

class PrinterJobController extends Controller
{
    public function __construct(
        private DeviceCommandRouter $deviceRouter,
        private LabelGeneratorService $labelGenerator
    ) {}

    /**
     * Send a label print job to the printer.
     * 
     * POST /api/v1/iot/agents/{agentId}/printer/print-label
     */
    public function printLabel(Request $request, string $agentId): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|string',
            'job_type' => 'required|in:product_label,barcode_label,shipping_label,test_label,test_receipt,raw_tspl',
            'data' => 'required_unless:job_type,test_label,test_receipt|array',
            'data.productName' => 'required_if:job_type,product_label|string|max:100',
            'data.barcode' => 'required_if:job_type,product_label,barcode_label|string|max:50',
            'data.price' => 'nullable|string|max:20',
            'data.sku' => 'nullable|string|max:50',
            'data.barcodeType' => 'nullable|in:CODE128,CODE39,EAN13,EAN8,UPC-A,QR',
            'data.text' => 'nullable|string|max:100',
            'data.commands' => 'required_if:job_type,raw_tspl|string',
            'copies' => 'nullable|integer|min:1|max:100',
            'config' => 'nullable|array',
        ]);

        $deviceId = $request->input('device_id');
        $jobType = $request->input('job_type');
        $data = $request->input('data', []);
        $copies = $request->input('copies', 1);
        $config = $request->input('config', []);

        // Verify device exists and is a label printer
        $device = null;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
            $device = DeviceConfiguration::where('id', $deviceId)
                ->where('agent_id', $agentId)
                ->where('device_type', 'printer')
                ->first();
        }
        if (!$device) {
            $device = DeviceConfiguration::where('agent_id', $agentId)
                ->where('device_type', 'printer')
                ->where(function ($q) use ($deviceId) {
                    $q->where('connection_info->ump_device_id', $deviceId)
                      ->orWhere('connection_info->serialNumber', $deviceId)
                      ->orWhere('connection_info->umpId', $deviceId)
                      ->orWhere('name', $deviceId);
                })
                ->first();
        }

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Printer device not found',
            ], 404);
        }

        if (!$device->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Printer device is disabled',
            ], 400);
        }

        // Merge device config with request config
        $labelConfig = array_merge($device->driver_config ?? [], $config);

        // Generate commands based on job type
        // For receipt printers, we send to a different endpoint
        $isReceiptJob = $jobType === 'test_receipt';
        
        try {
            if ($isReceiptJob) {
                // For receipt printers, we don't generate TSPL commands
                // The agent will handle the receipt printing
                $tsplCommands = null;
            } else {
                $tsplCommands = match ($jobType) {
                    'product_label' => $this->labelGenerator->generateProductLabel(
                        array_merge($data, ['copies' => $copies]),
                        $labelConfig
                    ),
                    'barcode_label' => $this->labelGenerator->generateBarcodeLabel(
                        array_merge($data, ['copies' => $copies]),
                        $labelConfig
                    ),
                    'shipping_label' => $this->labelGenerator->generateShippingLabel(
                        array_merge($data, ['copies' => $copies]),
                        $labelConfig
                    ),
                    'test_label' => $this->labelGenerator->generateTestLabel($labelConfig),
                    'raw_tspl' => $data['commands'],
                    default => throw new \InvalidArgumentException("Unknown job type: {$jobType}"),
                };
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate label: ' . $e->getMessage(),
            ], 400);
        }

        // Create print job record
        $printJob = PrintJob::create([
            'device_id' => $device->id,
            'agent_id' => $agentId,
            'job_type' => $jobType,
            'status' => PrintJob::STATUS_PENDING,
            'content' => $data,
            'metadata' => [
                'copies' => $copies,
                'config' => $labelConfig,
                'tspl_length' => $tsplCommands ? strlen($tsplCommands) : 0,
            ],
        ]);

        try {
            // Send to device via router (protocol-agnostic)
            $endpoint = $isReceiptJob ? '/api/printer/receipt' : '/api/printer/print';
            $payload = [
                'deviceId' => $deviceId,
                'jobId' => $printJob->id,
                'jobType' => $jobType,
                'copies' => $copies,
            ];
            
            if (!$isReceiptJob) {
                $payload['commands'] = $tsplCommands;
            }
            
            $result = $this->deviceRouter->sendCommand($device, $endpoint, $payload);

            if ($result['success'] ?? false) {
                $printJob->markAsSent();

                // Update device status
                $device->update([
                    'current_status' => 'busy',
                    'status_message' => $isReceiptJob ? 'Printing receipt' : 'Printing label',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Print job sent successfully',
                    'data' => [
                        'jobId' => $printJob->id,
                        'deviceId' => $deviceId,
                        'jobType' => $jobType,
                        'status' => $printJob->status,
                    ],
                ]);
            }

            $printJob->markAsFailed($result['error'] ?? 'Failed to send to agent');

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to send print job to agent',
                'data' => ['jobId' => $printJob->id],
            ], 500);

        } catch (\Exception $e) {
            $printJob->markAsFailed($e->getMessage());

            \Log::error('Failed to send print job', [
                'agent_id' => $agentId,
                'device_id' => $deviceId,
                'job_id' => $printJob->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to communicate with agent',
                'error' => $e->getMessage(),
                'data' => ['jobId' => $printJob->id],
            ], 500);
        }
    }

    /**
     * Agent reports print job result.
     * 
     * POST /api/v1/iot/agents/{agentId}/printer/job-result
     */
    public function reportJobResult(Request $request, string $agentId): JsonResponse
    {
        $request->validate([
            'job_id' => 'required|string',
            'device_id' => 'required|string',
            'status' => 'required|in:completed,failed',
            'error' => 'nullable|string',
        ]);

        $jobId = $request->input('job_id');
        $deviceId = $request->input('device_id');
        $status = $request->input('status');
        $error = $request->input('error');

        $printJob = PrintJob::find($jobId);

        if (!$printJob) {
            return response()->json([
                'success' => false,
                'message' => 'Print job not found',
            ], 404);
        }

        if ($status === 'completed') {
            $printJob->markAsCompleted();
        } else {
            $printJob->markAsFailed($error ?? 'Unknown error');
        }

        // Update device status back to ready
        $device = DeviceConfiguration::find($deviceId);
        if ($device) {
            $updated = $device->update([
                'current_status' => 'ready',
                'status_message' => $status === 'completed' ? 'Print completed' : "Print failed: {$error}",
            ]);
            \Log::info('Device status updated', [
                'device_id' => $deviceId,
                'updated' => $updated,
                'new_status' => 'ready',
            ]);
        } else {
            \Log::warning('Device not found for status update', ['device_id' => $deviceId]);
        }

        \Log::info('Print job result received', [
            'job_id' => $jobId,
            'device_id' => $deviceId,
            'status' => $status,
            'error' => $error,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Job result recorded',
        ]);
    }

    /**
     * Get printer status.
     * 
     * GET /api/v1/iot/agents/{agentId}/printer/status
     */
    public function getStatus(Request $request, string $agentId): JsonResponse
    {
        $deviceId = $request->input('device_id');

        if (!$deviceId) {
            return response()->json([
                'success' => false,
                'message' => 'device_id is required',
            ], 400);
        }

        $device = DeviceConfiguration::where('id', $deviceId)
            ->where('agent_id', $agentId)
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Device not found',
            ], 404);
        }

        // Try to get real-time status from device
        try {
            $result = $this->deviceRouter->getStatus($device);

            if ($result['success'] ?? false) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'deviceId' => $deviceId,
                        'status' => $result['status'] ?? $device->current_status,
                        'message' => $result['message'] ?? $device->status_message,
                        'online' => true,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            // Fall back to stored status
        }

        return response()->json([
            'success' => true,
            'data' => [
                'deviceId' => $deviceId,
                'status' => $device->current_status,
                'message' => $device->status_message,
                'online' => false,
            ],
        ]);
    }

    /**
     * Get print job history.
     * 
     * GET /api/v1/iot/agents/{agentId}/printer/history
     */
    public function getHistory(Request $request, string $agentId): JsonResponse
    {
        $deviceId = $request->input('device_id');
        $limit = $request->input('limit', 50);
        $status = $request->input('status');

        $query = PrintJob::where('agent_id', $agentId)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($deviceId) {
            $query->where('device_id', $deviceId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $jobs = $query->get()->map(function ($job) {
            return [
                'id' => $job->id,
                'deviceId' => $job->device_id,
                'jobType' => $job->job_type,
                'status' => $job->status,
                'content' => $job->content,
                'metadata' => $job->metadata,
                'errorMessage' => $job->error_message,
                'sentAt' => $job->sent_at?->toIso8601String(),
                'completedAt' => $job->completed_at?->toIso8601String(),
                'createdAt' => $job->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $jobs,
        ]);
    }
}
