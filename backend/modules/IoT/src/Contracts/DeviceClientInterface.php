<?php

namespace Modules\IoT\Contracts;

use Modules\IoT\Models\DeviceConfiguration;

interface DeviceClientInterface
{
    /**
     * Send command to device
     */
    public function send(
        DeviceConfiguration $device,
        string $command,
        array $payload = []
    ): array;

    /**
     * Get device status
     */
    public function getStatus(DeviceConfiguration $device): array;

    /**
     * Test device connection
     */
    public function testConnection(DeviceConfiguration $device): bool;

    /**
     * Get supported commands for this client
     */
    public function getSupportedCommands(): array;
}
