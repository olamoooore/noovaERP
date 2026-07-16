<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Modules\IoT\Models\DeviceConfiguration;

// Find a printer device
$device = DeviceConfiguration::where('device_type', 'printer')->first();
if (! $device) {
    echo "No printer device found\n";
    exit(1);
}

echo "Found device: {$device->name} ({$device->id})\n";
echo "Agent ID: {$device->agent_id}\n";
echo "Connection type: {$device->connection_type}\n";
echo 'Connection info: '.json_encode($device->connection_info)."\n";

// Try to get the device router
try {
    $router = app('Modules\IoT\Services\DeviceCommandRouter');
    echo "Router resolved\n";

    $result = $router->sendCommand($device, '/api/printer/receipt', [
        'deviceId' => $device->id,
        'jobId' => 'test-123',
        'jobType' => 'test_receipt',
        'commands' => 'test',
        'encoding' => 'base64',
    ]);

    echo 'Result: '.json_encode($result)."\n";
} catch (\Exception $e) {
    echo 'Exception: '.$e->getMessage()."\n";
    echo 'Trace: '.$e->getTraceAsString()."\n";
}
