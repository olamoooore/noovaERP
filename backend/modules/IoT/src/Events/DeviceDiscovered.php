<?php

namespace Modules\IoT\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\IoT\Models\DeviceTemplate;
use Modules\IoT\Models\DiscoveredDevice;

class DeviceDiscovered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DiscoveredDevice $device,
        public ?DeviceTemplate $suggestedTemplate = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        // Broadcast to tenant channel for frontend notifications
        $tenantId = tenant()?->id ?? 'default';

        return new Channel('tenant.'.$tenantId);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'device.discovered';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $data = [
            'type' => 'device_discovered',
            'device' => [
                'id' => $this->device->id,
                'agent_id' => $this->device->agent_id,
                'vendor_id' => $this->device->vendor_id,
                'product_id' => $this->device->product_id,
                'manufacturer' => $this->device->manufacturer,
                'model' => $this->device->model,
                'serial_number' => $this->device->serial_number,
                'port' => $this->device->port,
                'connection_type' => $this->device->connection_type,
                'status' => $this->device->status,
            ],
            'timestamp' => now()->toISOString(),
        ];

        if ($this->suggestedTemplate) {
            $data['suggested_template'] = [
                'id' => $this->suggestedTemplate->id,
                'name' => $this->suggestedTemplate->name,
                'device_type' => $this->suggestedTemplate->device_type,
                'driver_type' => $this->suggestedTemplate->driver_type,
            ];
        }

        return $data;
    }
}
