<?php

namespace Modules\POS\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\IoT\Services\DeviceCommandRouter;
use Modules\IoT\Services\ReceiptGeneratorService;
use Modules\IoT\Models\DeviceConfiguration;
use Modules\POS\Models\PosOrder;

class ReceiptPrintingService
{
    public function __construct(
        private DeviceCommandRouter $deviceRouter,
        private ReceiptGeneratorService $receiptGenerator
    ) {}

    /**
     * Print receipt for a POS order
     */
    public function printOrderReceipt(PosOrder $order): array
    {
        try {
            // Check if auto-print is enabled
            $autoPrint = Setting::getValue('receipt_printer_auto_print', true);
            if (!$autoPrint) {
                return ['success' => false, 'message' => 'Auto-print disabled'];
            }

            // Get printer configuration
            $printer = $this->getReceiptPrinter();
            if (!$printer) {
                return ['success' => false, 'message' => 'No receipt printer configured'];
            }

            // Get device configuration
            $device = DeviceConfiguration::find($printer['device_id']);
            
            if (!$device) {
                return ['success' => false, 'message' => 'Device not found'];
            }

            // Transform order to receipt data
            $receiptData = $this->transformOrderToReceiptData($order);

            // Generate ESC/POS commands
            $escposCommands = $this->receiptGenerator->generateSalesReceipt($receiptData);
            
            // Encode for JSON transmission
            $encodedCommands = $this->receiptGenerator->encodeForJson($escposCommands);

            // Generate unique job ID (UUID format required by print_jobs table)
            $jobId = (string) Str::uuid();

            // Send to device via router (protocol-agnostic)
            $response = $this->deviceRouter->sendCommand(
                $device,
                '/api/printer/receipt',
                [
                    'jobId' => $jobId,
                    'deviceId' => $printer['device_id'],
                    'jobType' => 'sales_receipt',
                    'commands' => $encodedCommands,
                    'encoding' => 'base64', // Tell agent to decode base64
                ]
            );

            Log::info('Receipt print job sent', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'device_id' => $printer['device_id'],
                'agent_id' => $printer['agent_id'],
                'result' => $response,
            ]);

            return $response;

        } catch (\Exception $e) {
            Log::error('Receipt printing failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Transform POS order to receipt data format
     */
    private function transformOrderToReceiptData(PosOrder $order): array
    {
        $order->load(['items', 'payments', 'createdBy']);

        // Get store information from settings
        $storeName = Setting::getValue('store_name', 'Store Name');
        $storeAddress = Setting::getValue('store_address', '');
        $storePhone = Setting::getValue('store_phone', '');
        $footerMessage = Setting::getValue('receipt_footer_message', 'Thank you for your business!');

        // Get payment info
        $payment = $order->payments->first();
        $paymentMethod = $payment ? $payment->method : 'unknown';
        $paymentAmount = $payment ? (float) $payment->amount : (float) $order->total;

        // Calculate change for cash payments
        $change = 0;
        if ($paymentMethod === 'cash' && $paymentAmount > $order->total) {
            $change = $paymentAmount - $order->total;
        }

        return [
            'store_name' => $storeName,
            'store_address' => $storeAddress,
            'store_phone' => $storePhone,
            'receipt_number' => $order->order_number,
            'date' => $order->paid_at ? $order->paid_at->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s'),
            'cashier' => $order->createdBy ? $order->createdBy->name : 'Unknown',
            'items' => $order->items->map(function ($item) {
                return [
                    'name' => $item->name,
                    'quantity' => (int) $item->quantity,
                    'price' => (float) $item->unit_price,
                ];
            })->toArray(),
            'subtotal' => (float) $order->subtotal,
            'tax' => (float) $order->tax_amount,
            'discount' => (float) ($order->discount_amount ?? 0),
            'total' => (float) $order->total,
            'payment_method' => ucfirst(str_replace('_', ' ', $paymentMethod)),
            'payment_amount' => $paymentAmount,
            'change' => $change,
            'footer_message' => $footerMessage,
        ];
    }

    /**
     * Get configured receipt printer from settings
     */
    private function getReceiptPrinter(): ?array
    {
        $deviceId = Setting::getValue('receipt_printer_device_id');
        $agentId = Setting::getValue('receipt_printer_agent_id');

        if (!$deviceId || !$agentId) {
            Log::warning('Receipt printer not configured', [
                'device_id' => $deviceId,
                'agent_id' => $agentId,
            ]);
            return null;
        }

        return [
            'device_id' => $deviceId,
            'agent_id' => $agentId,
        ];
    }
}
