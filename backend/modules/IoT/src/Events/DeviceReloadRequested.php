<?php

namespace Modules\IoT\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceReloadRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $agentId,
        public ?string $deviceId = null // null = reload all
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel("agent.{$this->agentId}");
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return $this->deviceId ? 'device.reload' : 'devices.reload_all';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $data = [
            'type' => $this->deviceId ? 'device_reload' : 'devices_reload_all',
            'timestamp' => now()->toISOString(),
        ];

        if ($this->deviceId) {
            $data['device_id'] = $this->deviceId;
        }

        return $data;
    }
}
