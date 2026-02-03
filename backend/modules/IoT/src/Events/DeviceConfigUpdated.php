<?php

namespace Modules\IoT\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\IoT\Models\DeviceConfiguration;

class DeviceConfigUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DeviceConfiguration $config,
        public string $action // 'created', 'updated', 'deleted'
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel("agent.{$this->config->agent_id}");
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'device.config.' . $this->action;
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'device_config_' . $this->action,
            'device_id' => $this->config->id,
            'config' => [
                'id' => $this->config->id,
                'agent_id' => $this->config->agent_id,
                'device_type' => $this->config->device_type,
                'driver_type' => $this->config->driver_type,
                'name' => $this->config->name,
                'enabled' => $this->config->enabled,
                'connection_info' => $this->config->connection_info,
                'driver_config' => $this->config->driver_config,
                'capabilities' => $this->config->capabilities,
                'metadata' => $this->config->metadata,
            ],
            'timestamp' => now()->toISOString(),
        ];
    }
}
