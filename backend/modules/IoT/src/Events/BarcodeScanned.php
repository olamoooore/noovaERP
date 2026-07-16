<?php

namespace Modules\IoT\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BarcodeScanned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $deviceId,
        public string $barcode,
        public ?string $format = null,
        public ?string $deviceName = null,
        public ?string $scanResultId = null,
        public ?array $parsedData = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('iot-devices');
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'barcode.scanned';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'deviceId' => $this->deviceId,
            'deviceName' => $this->deviceName,
            'barcode' => $this->barcode,
            'format' => $this->format,
            'scanResultId' => $this->scanResultId,
            'parsed' => $this->parsedData,
            'isQrCode' => $this->format === 'QR' || ! empty($this->parsedData),
            'scannedAt' => now()->toIso8601String(),
        ];
    }
}
