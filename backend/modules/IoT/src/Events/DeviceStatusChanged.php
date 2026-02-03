<?php

namespace Modules\IoT\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\IoT\Models\DeviceConfiguration;

class DeviceStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DeviceConfiguration $config,
        public string $status,
        public ?string $message = null,
        public ?string $errorCode = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            // Broadcast to agent channel
            new Channel("agent.{$this->config->agent_id}"),
            // Broadcast to tenant channel for frontend
            new Channel("tenant." . tenant()->id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'device.status_changed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'device_status_change',
            'device_id' => $this->config->id,
            'agent_id' => $this->config->agent_id,
            'device_name' => $this->config->name,
            'status' => $this->status,
            'message' => $this->message,
            'error_code' => $this->errorCode,
            'is_online' => $this->config->isOnline(),
            'timestamp' => now()->toISOString(),
        ];
    }
}
