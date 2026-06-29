<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\IoT\Models\DeviceConfiguration;
use Modules\IoT\Models\PrintJob;
use Modules\IoT\Services\DeviceCommandRouter;
use Modules\IoT\Services\ReceiptGeneratorService;

class ReceiptPrinterController extends Controller
{
    public function __construct(
        private DeviceCommandRouter $deviceRouter,
        private ReceiptGeneratorService $receiptGenerator
    ) {}

    /**
     * Send a receipt print job to the printer.
     * 
     * POST /api/v1/iot/print-jobs/receipt
     */
    public function printReceipt(Request $request): JsonResponse
    {
        \Log::info('printReceipt called', [
            'request_data' => $request->all(),
            'url' => $request->fullUrl(),
        ]);
        
        $request->validate([
            'agent_id' => 'required|string',
            'device_id' => 'required|string',
            'receipt_data' => 'required|array',
            'receipt_data.store_name' => 'nullable|string|max:100',
            'receipt_data.items' => 'required|array|min:1',
            'receipt_data.items.*.name' => 'required|string',
            'receipt_data.items.*.quantity' => 'required|numeric|min:0',
            'receipt_data.items.*.price' => 'required|numeric|min:0',
            'receipt_data.subtotal' => 'required|numeric|min:0',
            'receipt_data.tax' => 'nullable|numeric|min:0',
            'receipt_data.total' => 'required|numeric|min:0',
            'receipt_data.payment_method' => 'required|string',
            'receipt_data.payment_amount' => 'nullable|numeric|min:0',
            'receipt_data.change' => 'nullable|numeric|min:0',
            'receipt_data.receipt_number' => 'nullable|string',
            'receipt_data.cashier' => 'nullable|string',
            'receipt_data.date' => 'nullable|string',
        ]);

        $agentId = $request->input('agent_id');
        $deviceId = $request->input('device_id');
        $receiptData = $request->input('receipt_data');

        // Verify device exists and is a receipt printer
        $device = DeviceConfiguration::where('id', $deviceId)
            ->where('agent_id', $agentId)
            ->where('device_type', 'printer')
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt printer device not found',
            ], 404);
        }

        if (!$device->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt printer device is disabled',
            ], 400);
        }

        // Verify it's a receipt printer
        if (!str_contains($device->driver_type, 'receipt')) {
            return response()->json([
                'success' => false,
                'message' => 'Device is not configured as a receipt printer',
            ], 400);
        }

        try {
            // Generate ESC/POS commands
            $escposCommands = $this->receiptGenerator->generateSalesReceipt($receiptData);
            
            // Encode for JSON transmission
            $encodedCommands = $this->receiptGenerator->encodeForJson($escposCommands);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate receipt: ' . $e->getMessage(),
            ], 400);
        }

        // Create print job record
        $printJob = PrintJob::create([
            'device_id' => $deviceId,
            'agent_id' => $agentId,
            'job_type' => 'sales_receipt',
            'status' => PrintJob::STATUS_PENDING,
            'content' => $receiptData,
            'metadata' => [
                'commands_length' => strlen($escposCommands),
                'encoded_length' => strlen($encodedCommands),
            ],
        ]);

        try {
            // Send to device via router (protocol-agnostic)
            $result = $this->deviceRouter->sendCommand($device, '/api/printer/receipt', [
                'deviceId' => $deviceId,
                'jobId' => $printJob->id,
                'jobType' => 'sales_receipt',
                'commands' => $encodedCommands,
                'encoding' => 'base64',
            ]);

            if ($result['success'] ?? false) {
                $printJob->markAsSent();

                // Update device status
                $device->update([
                    'current_status' => 'busy',
                    'status_message' => 'Printing receipt',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Receipt print job sent successfully',
                    'data' => [
                        'jobId' => $printJob->id,
                        'deviceId' => $deviceId,
                        'status' => $printJob->status,
                    ],
                ]);
            }

            $printJob->markAsFailed($result['error'] ?? 'Failed to send to agent');

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to send receipt print job to agent',
                'data' => ['jobId' => $printJob->id],
            ], 500);

        } catch (\Exception $e) {
            $printJob->markAsFailed($e->getMessage());

            \Log::error('Failed to send receipt print job', [
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
     * Test receipt printer.
     * 
     * POST /api/v1/iot/agents/{agentId}/printer/test-receipt
     */
    public function testReceipt(Request $request, string $agentId): JsonResponse
    {
        \Log::info('testReceipt called', [
            'agent_id' => $agentId,
            'request_data' => $request->all(),
            'url' => $request->fullUrl(),
        ]);
        
        $request->validate([
            'device_id' => 'required|string',
        ]);

        $deviceId = $request->input('device_id');

        // Verify device exists — accept UUID id or serial/ump_device_id
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
                'message' => 'Receipt printer device not found',
            ], 404);
        }

        if (!$device->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt printer device is disabled',
            ], 400);
        }

        try {
            // Generate test receipt
            $escposCommands = $this->receiptGenerator->generateTestReceipt();
            
            // Encode for JSON transmission
            $encodedCommands = $this->receiptGenerator->encodeForJson($escposCommands);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate test receipt: ' . $e->getMessage(),
            ], 400);
        }

        // Create print job record
        $printJob = PrintJob::create([
            'device_id' => $device->id,
            'agent_id' => $agentId,
            'job_type' => 'test_receipt',
            'status' => PrintJob::STATUS_PENDING,
            'content' => ['test' => true],
            'metadata' => [
                'commands_length' => strlen($escposCommands),
                'encoded_length' => strlen($encodedCommands),
            ],
        ]);

        try {
            // Send to device via router (protocol-agnostic)
            \Log::info('Sending test receipt to device', [
                'agent_id' => $agentId,
                'device_id' => $deviceId,
                'job_id' => $printJob->id,
            ]);
            
            $result = $this->deviceRouter->sendCommand($device, '/api/printer/receipt', [
                'deviceId' => $deviceId,
                'jobId' => $printJob->id,
                'jobType' => 'test_receipt',
                'commands' => $encodedCommands,
                'encoding' => 'base64',
            ]);

            \Log::info('Agent response received', [
                'result' => $result,
            ]);

            if ($result['success'] ?? false) {
                $printJob->markAsSent();

                // Update device status
                $device->update([
                    'current_status' => 'busy',
                    'status_message' => 'Printing test receipt',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Test receipt sent successfully',
                    'data' => [
                        'jobId' => $printJob->id,
                        'deviceId' => $deviceId,
                        'status' => $printJob->status,
                    ],
                ]);
            }

            $printJob->markAsFailed($result['error'] ?? 'Failed to send to agent');

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to send test receipt to agent',
                'data' => ['jobId' => $printJob->id],
            ], 500);

        } catch (\Exception $e) {
            $printJob->markAsFailed($e->getMessage());

            \Log::error('Failed to send test receipt', [
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
}
