<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Modules\IoT\Models\DeviceConfiguration;

// Find all printer devices
$devices = DeviceConfiguration::where('device_type', 'printer')->get();
foreach ($devices as $device) {
    echo "Device: {$device->name} ({$device->id})\n";
    echo "  Agent ID: {$device->agent_id}\n";
    echo "  Connection info: " . json_encode($device->connection_info) . "\n\n";
}

// Test with KPOS
$kpos = DeviceConfiguration::where('name', 'UMP KPOS_80')->first();
if ($kpos) {
    echo "Testing KPOS_80...\n";
    $router = app('Modules\IoT\Services\DeviceCommandRouter');
    
    // Generate a simple test
    $commands = base64_encode("\x1B\x40Hello Test\x0A\x0A\x0A\x1D\x56\x41\x03");
    
    $result = $router->sendCommand($kpos, '/api/printer/receipt', [
        'deviceId' => $kpos->id,
        'jobId' => 'test-kpos-123',
        'jobType' => 'test_receipt',
        'commands' => $commands,
        'encoding' => 'base64',
    ]);
    
    echo "Result: " . json_encode($result) . "\n";
}
