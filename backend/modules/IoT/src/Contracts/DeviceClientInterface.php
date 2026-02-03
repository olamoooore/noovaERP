<?php

namespace Modules\IoT\Contracts;

use Modules\IoT\Models\DeviceConfiguration;

interface DeviceClientInterface
{
    /**
     * Send command to device
     *
     * @param DeviceConfiguration $device
     * @param string $command
     * @param array $payload
     * @return array
     */
    public function send(
        DeviceConfiguration $device,
        string $command,
        array $payload = []
    ): array;

    /**
     * Get device status
     *
     * @param DeviceConfiguration $device
     * @return array
     */
    public function getStatus(DeviceConfiguration $device): array;

    /**
     * Test device connection
     *
     * @param DeviceConfiguration $device
     * @return bool
     */
    public function testConnection(DeviceConfiguration $device): bool;

    /**
     * Get supported commands for this client
     *
     * @return array
     */
    public function getSupportedCommands(): array;
}
