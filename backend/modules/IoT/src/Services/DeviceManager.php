<?php

namespace Modules\IoT\Services;

use Modules\IoT\Enums\DeviceType;
use Modules\IoT\Models\IoTDevice;
use Modules\IoT\Services\Drivers\PrinterDriverInterface;

class DeviceManager
{
    /**
     * Get the appropriate driver for a device.
     */
    public function getDriver(IoTDevice $device)
    {
        $driverClass = $this->resolveDriverClass($device);

        if (! $driverClass || ! class_exists($driverClass)) {
            throw new \Exception("Driver not found for device type: {$device->type->value}, vendor: {$device->vendor}");
        }

        return new $driverClass($device);
    }

    /**
     * Resolve the driver class for a device.
     */
    protected function resolveDriverClass(IoTDevice $device): ?string
    {
        $drivers = config('iot.drivers');

        return $drivers[$device->type->value][$device->vendor] ?? null;
    }

    /**
     * Get terminal driver for a device.
     *
     * @throws \Exception If device is not a terminal or driver doesn't implement TerminalDriverInterface
     */
    public function getTerminalDriver(IoTDevice $device): \Modules\IoT\Services\Drivers\TerminalDriverInterface
    {
        if ($device->type !== DeviceType::TERMINAL) {
            throw new \Exception('Device is not a terminal');
        }

        $driver = $this->getDriver($device);

        if (! $driver instanceof \Modules\IoT\Services\Drivers\TerminalDriverInterface) {
            throw new \Exception('Driver does not implement TerminalDriverInterface');
        }

        return $driver;
    }

    /**
     * Get printer driver for a device.
     *
     * @throws \Exception If device is not a printer or driver doesn't implement PrinterDriverInterface
     */
    public function getPrinterDriver(IoTDevice $device): PrinterDriverInterface
    {
        if ($device->type !== DeviceType::PRINTER) {
            throw new \Exception('Device is not a printer');
        }

        $driver = $this->getDriver($device);

        if (! $driver instanceof PrinterDriverInterface) {
            throw new \Exception('Driver does not implement PrinterDriverInterface');
        }

        return $driver;
    }

    /**
     * Test device connection.
     */
    public function testConnection(IoTDevice $device): array
    {
        try {
            $driver = $this->getDriver($device);

            // Check if driver implements TerminalDriverInterface
            if ($driver instanceof \Modules\IoT\Services\Drivers\TerminalDriverInterface) {
                $connected = $driver->testConnection();
            } elseif (method_exists($driver, 'connect')) {
                $connected = $driver->connect();
            } else {
                return [
                    'success' => false,
                    'message' => 'Driver does not support connection testing',
                ];
            }

            if ($connected) {
                $device->markOnline();
                $device->logAction('test_connection', 'success');

                return [
                    'success' => true,
                    'message' => 'Device connected successfully',
                ];
            }

            $device->markOffline();
            $device->logAction('test_connection', 'failed', null, null, 'Connection failed');

            return [
                'success' => false,
                'message' => 'Failed to connect to device',
            ];
        } catch (\Exception $e) {
            $device->markOffline();
            $device->logAction('test_connection', 'failed', null, null, $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
