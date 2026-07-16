<?php

namespace Modules\IoT\Services\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\IoT\Models\IoTDevice;

class GprinterLabelDriver implements PrinterDriverInterface
{
    protected IoTDevice $device;

    protected string $agentUrl;

    public function __construct(IoTDevice $device)
    {
        $this->device = $device;
        $this->agentUrl = config('iot.agent.default_url');
    }

    /**
     * Connect to the printer via Local Agent.
     */
    public function connect(): bool
    {
        try {
            $response = Http::timeout(10)->post("{$this->agentUrl}/api/printer/connect", [
                'deviceId' => $this->device->id,
                'vendor' => $this->device->vendor,
                'connection' => $this->device->connection_type->value,
                'config' => $this->device->config,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return $data['connected'] ?? false;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Gprinter connection failed', [
                'device_id' => $this->device->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Print labels using TSPL commands.
     */
    public function print(array $data): array
    {
        try {
            $payload = [
                'printerType' => 'label_tspl',
                'connection' => 'usb',
                'labels' => $data['labels'] ?? [],
            ];

            $response = Http::timeout(30)->post("{$this->agentUrl}/api/printer/print-label", $payload);

            if ($response->successful()) {
                $result = $response->json();

                $this->device->logAction('print_label', 'success', null, null,
                    'Printed '.count($payload['labels']).' labels');

                return [
                    'success' => true,
                    'message' => $result['message'] ?? 'Labels printed successfully',
                    'jobId' => $result['jobId'] ?? null,
                ];
            }

            $error = $response->json()['message'] ?? 'Print job failed';
            $this->device->logAction('print_label', 'failed', null, null, $error);

            return [
                'success' => false,
                'message' => $error,
            ];
        } catch (\Exception $e) {
            $this->device->logAction('print_label', 'failed', null, null, $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get printer status from Local Agent.
     */
    public function getStatus(): array
    {
        try {
            $response = Http::timeout(10)->get("{$this->agentUrl}/api/printer/status", [
                'deviceId' => $this->device->id,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'status' => 'unknown',
                'message' => 'Failed to get printer status',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Disconnect from the printer.
     */
    public function disconnect(): void
    {
        try {
            Http::timeout(5)->post("{$this->agentUrl}/api/printer/disconnect", [
                'deviceId' => $this->device->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to disconnect from Gprinter', [
                'device_id' => $this->device->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate TSPL commands for label printing.
     */
    public function generateTSPLCommands(array $labelData): string
    {
        $cmd = '';

        // Label setup (76mm x 50mm with 2mm gap)
        $cmd .= "SIZE 76 mm, 50 mm\r\n";
        $cmd .= "GAP 2 mm, 0 mm\r\n";
        $cmd .= "DIRECTION 1\r\n";
        $cmd .= "CLS\r\n";

        // Product name (X=30, Y=30, Font=3, Scale=1x1)
        $cmd .= "TEXT 30,30,\"3\",0,1,1,\"{$labelData['productName']}\"\r\n";

        // Price (X=30, Y=80, Font=3, Scale=2x2 for emphasis)
        $cmd .= "TEXT 30,80,\"3\",0,2,2,\"{$labelData['price']}\"\r\n";

        // Barcode (X=30, Y=180, Code 128, Height=80, Human readable)
        $cmd .= "BARCODE 30,180,\"128\",80,1,0,2,2,\"{$labelData['sku']}\"\r\n";

        // Print command
        $cmd .= "PRINT {$labelData['quantity']}\r\n";

        return $cmd;
    }
}
