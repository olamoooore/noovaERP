<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Modules\IoT\Models\DeviceConfiguration;

// Test with Gprinter (label)
$gp = DeviceConfiguration::where('name', 'UMP Gprinter GP-D320FX')->first();
if ($gp) {
    echo "Testing Gprinter GP-D320FX...\n";
    $router = app('Modules\IoT\Services\DeviceCommandRouter');
    
    // Generate a simple TSPL test label
    $tspl = "SIZE 76 mm, 50 mm\r\nGAP 2 mm, 0 mm\r\nDIRECTION 0\r\nCLS\r\nTEXT 50,50,\"3\",0,1,1,\"Test Label\"\r\nPRINT 1,1\r\n";
    
    $result = $router->sendCommand($gp, '/api/printer/print', [
        'deviceId' => $gp->id,
        'jobId' => 'test-gp-123',
        'jobType' => 'test_label',
        'commands' => $tspl,
        'encoding' => 'raw',
    ]);
    
    echo "Result: " . json_encode($result) . "\n";
}
